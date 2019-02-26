<?php

/**
 * This file is part of the Orbitale DoctrineTools package.
 *
 * (c) Alexandre Rock Ancelet <alex@orbitale.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Orbitale\Component\DoctrineTools;

use Closure;
use Doctrine\Common\DataFixtures\AbstractFixture as BaseAbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Instantiator\Instantiator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use ReflectionClass;
use function method_exists;
use RuntimeException;
use function sprintf;

/**
 * This class is used mostly for inserting "fixed" data, especially with their primary keys forced on insert.
 * Two methods are mandatory to insert new data, and you can create them both as indexed array or as objects.
 * Objects are directly persisted to the database, and once they're all, the EntityManager is flushed with all objects.
 * You can override the `getOrder` and `getReferencePrefix` for more flexibility on how to link fixtures together.
 * Other methods can be overriden, notably `flushEveryXIterations` and `searchForMatchingIds`. See their docs to know more.
 *
 * @package Orbitale\Component\DoctrineTools
 */
abstract class AbstractFixture extends BaseAbstractFixture implements OrderedFixtureInterface
{
    /** @var EntityManagerInterface */
    private $manager;

    /** @var EntityRepository */
    private $repo;

    /** @var int */
    private $totalNumberOfObjects = 0;

    /** @var int */
    private $numberOfIteratedObjects = 0;

    /** @var bool */
    private $clearEMOnFlush;

    /** @var Instantiator|null */
    private static $instantiator;

    /** @var ReflectionClass|null */
    private static $reflection;

    public function __construct()
    {
        $this->clearEMOnFlush = $this->clearEntityManagerOnFlush();
    }

    /**
     * Returns the class of the entity you're managing.
     *
     * @return string
     */
    abstract protected function getEntityClass(): string;

    /**
     * Returns a nested array containing the list of objects that should be persisted.
     *
     * @return array[]
     */
    abstract protected function getObjects(): array;

    /**
     * {@inheritdoc}
     */
    public function load(ObjectManager $manager)
    {
        $this->manager = $manager;

        if ($this->manager instanceof EntityManagerInterface && $this->disableLogger()) {
            $this->manager->getConnection()->getConfiguration()->setSQLLogger(null);
        }

        $this->repo = $this->manager->getRepository($this->getEntityClass());

        $objects = $this->getObjects();

        $this->totalNumberOfObjects = \count($objects);

        $this->numberOfIteratedObjects = 0;
        foreach ($objects as $data) {
            $this->fixtureObject($data);
            $this->numberOfIteratedObjects++;
        }

        // Flush if we performed a "whole" fixture load,
        //  or if we flushed with batches but have not flushed all items.
        if (
            !$this->flushEveryXIterations()
            || ($this->flushEveryXIterations() && $this->numberOfIteratedObjects !== $this->totalNumberOfObjects)
        ) {
            $this->manager->flush();
            if ($this->clearEMOnFlush) {
                $this->manager->clear();
            }
        }
    }

    /**
     * Creates the object and persist it in database.
     *
     * @param object $data
     */
    private function fixtureObject(array $data): void
    {
        $obj = $this->createNewInstance($data);

        // And finally we persist the item
        $this->manager->persist($obj);

        // If we need to flush it, then we do it too.
        if (
            $this->numberOfIteratedObjects > 0
            && $this->flushEveryXIterations() > 0
            && $this->numberOfIteratedObjects % $this->flushEveryXIterations() === 0
        ) {
            $this->manager->flush();
            if ($this->clearEMOnFlush) {
                $this->manager->clear();
            }
        }

        // If we have to add a reference, we do it
        if ($prefix = $this->getReferencePrefix()) {
            $methodName = $this->getMethodNameForReference();

            $reference = null;

            if (method_exists($obj, $methodName)) {
                $reference = $obj->{$methodName}();
            } elseif (method_exists($obj, '__toString')) {
                $reference = (string) $obj;
            }

            if (!$reference) {
                throw new RuntimeException(sprintf(
                    'If you want to specify a reference with prefix "%s", method "%s" or "%s" must exist in the class, or you can override the "%s" method and add your own.',
                    $prefix, $methodName, '__toString()', 'getMethodNameForReference'
                ));
            }
            $this->addReference($prefix.$reference, $obj);
        }
    }

    /**
     * Get the order of this fixture.
     * Default null means 0, so the fixture will be run at the beginning in order of appearance.
     * Is to be overriden if used.
     *
     * @return int
     */
    public function getOrder(): int
    {
        return 0;
    }

    /**
     * If true, the SQL logger will be disabled, and therefore will avoid memory leaks and save memory during execution.
     * Very useful for big batches of entities.
     *
     * @return bool
     */
    protected function disableLogger(): bool
    {
        return true;
    }

    /**
     * Returns the prefix used to create fixtures reference.
     * If returns `null`, no reference will be created for the object.
     * NOTE: To create references of an object, it must have an ID, and if not, implement __toString(), because
     *   each object is referenced BEFORE flushing the database.
     * NOTE2: If you specified a "flushEveryXIterations" value, then the object will be provided with an ID every time.
     */
    protected function getReferencePrefix(): ?string
    {
        return null;
    }

    /**
     * When set, you can customize the method that will be used
     * to determine the second part of the reference prefix.
     * For example, if reference prefix is "my-entity-" and the
     * method is "getIdentifier()", the reference will be:
     * "$reference = 'my-entity-'.$obj->getIdentifier()".
     *
     * Only used when getReferencePrefix() returns non-empty value.
     *
     * Always tries to fall back to "__toString()".
     */
    protected function getMethodNameForReference(): string
    {
        return 'getId';
    }

    /**
     * If specified, the entity manager will be flushed every X times, depending on your specified values.
     * Default is null, so the database is flushed only at the end of all persists.
     */
    protected function flushEveryXIterations(): int
    {
        return 0;
    }

    /**
     * If true, will run $em->clear() after having run $em->flush().
     * This allows saving some memory when using huge sets of non-referenced fixtures.
     *
     * @return bool
     */
    protected function clearEntityManagerOnFlush(): bool
    {
        return true;
    }

    /**
     * Creates a new instance of the class associated with the fixture.
     * Override this method if you have constructor arguments to manage yourself depending on input data.
     *
     * @param array $data
     *
     * @return object
     */
    protected function createNewInstance(array $data): object
    {
        $instance = self::getInstantiator()->instantiate($this->getEntityClass());

        $refl = $this->getReflection();

        foreach ($data as $key => $value) {
            $prop = $refl->getProperty($key);
            $prop->setAccessible(true);

            if ($value instanceof Closure) {
                $value = $value($instance, $this, $this->manager);
            }

            $prop->setValue($instance, $value);
        }

        return $instance;
    }

    private function getReflection(): ReflectionClass
    {
        if (!self::$reflection) {
            return self::$reflection = new ReflectionClass($this->getEntityClass());
        }

        return self::$reflection;
    }

    private static function getInstantiator(): Instantiator
    {
        if (!self::$instantiator) {
            return self::$instantiator = new Instantiator();
        }

        return self::$instantiator;
    }
}

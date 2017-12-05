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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\DataFixtures\AbstractFixture as BaseAbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\Exception\NoSuchIndexException;

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
    /** @var ObjectManager */
    private $manager;

    /** @var EntityRepository */
    private $repo;

    /** @var PropertyAccessor */
    private $propertyAccessor;

    /** @var int */
    private $totalNumberOfObjects = 0;

    /** @var int */
    private $numberOfIteratedObjects = 0;

    /** @var bool */
    private $clearEMOnFlush = true;

    public function __construct()
    {
        $this->clearEMOnFlush = $this->clearEntityManagerOnFlush();
        if (class_exists('Symfony\Component\PropertyAccess\PropertyAccess')) {
            $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
        }
    }

    /**
     * Returns the class of the entity you're managing.
     *
     * @return string
     */
    abstract protected function getEntityClass(): string;

    /**
     * Returns a list of objects to insert in the database.
     *
     * @return ArrayCollection|object[]
     */
    abstract protected function getObjects();

    /**
     * {@inheritdoc}
     */
    public function load(ObjectManager $manager)
    {
        $this->manager = $manager;

        if ($this->disableLogger() && $this->manager instanceof EntityManager) {
            $this->manager->getConnection()->getConfiguration()->setSQLLogger(null);
        }

        $this->repo = $this->manager->getRepository($this->getEntityClass());

        $objects = $this->getObjects();

        $this->totalNumberOfObjects = count($objects);

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
     * This allows you to change your ID generator if you are using IDs in your objects.
     * On reason to change this would be to use a GeneratorType constant instead of IdGenerator instance.
     * ID generation can be managed differently depending on your DBMS: sqlite, mysql, postgres, etc.,
     * all react differently...
     *
     * @param ClassMetadata $metadata
     * @param null          $id
     */
    protected function setGeneratorBasedOnId(ClassMetadata $metadata, $id = null): void
    {
        if ($id) {
            $metadata->setIdGenerator(new AssignedGenerator());
        } else {
            $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);
        }
    }

    /**
     * Creates the object and persist it in database.
     *
     * @param object $data
     */
    private function fixtureObject($data)
    {
        /** @var ClassMetadata $metadata */
        $metadata = $this->manager->getClassMetadata($this->getEntityClass());

        // Can be either one or multiple identifiers.
        $identifier = $metadata->getIdentifier();

        // The ID is taken in account to force its use in the database.
        $id = [];
        foreach ($identifier as $key) {
            $id[$key] = $this->getPropertyFromData($data, $key);
        }

        // Make sure id is correctly ready for $repo->find($id).
        if (0 === count($id)) {
            $id = null;
        }

        $obj = null;
        $newObject = false;
        $addRef = false;

        // If the user specifies an ID and the fixture class wants it to be merged, we search for an object.
        if ($id && $this->searchForMatchingIds()) {
            // Checks that the object ID exists in database.
            $obj = $this->repo->findOneBy($id);
            if ($obj) {
                // If so, the object is not overwritten.
                $addRef = true;
            } else {
                // Else, we create a new object.
                $newObject = true;
            }
        } else {
            $newObject = true;
        }

        if ($newObject === true) {
            // If the data are in an array, we instanciate a new object.
            // If it's not, then it's an object, and we consider that it's already populated.
            if (is_array($data)) {
                $obj = $this->createNewInstance($data);
                foreach ($data as $field => $value) {
                    // If the value is a callable we execute it and inject the fixture object and the manager.
                    if ($value instanceof \Closure) {
                        $value = $value($obj, $this, $this->manager);
                    }

                    if ($this->propertyAccessor) {
                        $this->propertyAccessor->setValue($obj, $field, $value);
                    } else {
                        // Force the use of a setter if accessor is not available.
                        $obj->{'set'.ucfirst($field)}($value);
                    }
                }
            }

            // Set the id generator in case it is overriden.
            $this->setGeneratorBasedOnId($metadata, $id);

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
            $addRef = true;
        }

        // If we have to add a reference, we do it
        if ($addRef === true && $obj && $this->getReferencePrefix()) {
            if (!$id || !reset($id)) {
                // If no id was provided in the object, maybe there was one after data hydration.
                // Can be done maybe in entity constructor or in a property callback.
                // So let's try to get it.
                if ($this->propertyAccessor) {
                    if ($this->propertyAccessor) {
                        try {
                            $id = ['id' => $this->propertyAccessor->getValue($obj, 'id')];
                        } catch (NoSuchIndexException $e) {
                            $id = [];
                        }
                    }
                } elseif (method_exists($obj, 'getId')) {
                    $id = ['id' => $obj->getId()];
                }
            }
            if (1 === count($id)) {
                // Only reference single identifiers.
                $id = reset($id);
                $this->addReference($this->getReferencePrefix().($id ?: (string) $obj), $obj);
            } elseif (count($id) > 1) {
                throw new \RuntimeException('Cannot add reference for composite identifiers.');
            }
        }

        $obj = null;
    }

    /**
     * @param mixed $data
     * @param string $key
     *
     * @return mixed
     */
    private function getPropertyFromData($data, string $key)
    {
        if (is_object($data)) {
            $method = 'get'.ucfirst($key);
            if (method_exists($data, $method) && $data->$method()) {
                return $data->$method;
            }
            if ($this->propertyAccessor) {
                return $this->propertyAccessor->getValue($data, $key);
            }
        }

        return $data[$key] ?? null;
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
        return false;
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
     * If specified, the entity manager will be flushed every X times, depending on your specified values.
     * Default is null, so the database is flushed only at the end of all persists.
     */
    protected function flushEveryXIterations(): int
    {
        return 0;
    }

    /**
     * If true and an ID is specified in fixture's $data, will execute a $manager->find($id) in the database.
     * Be careful, if you set it to false you may have "duplicate entry" errors if your database is already populated.
     *
     * @return bool
     */
    protected function searchForMatchingIds(): bool
    {
        return true;
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
    protected function createNewInstance(array $data)
    {
        $class = $this->getEntityClass();

        return new $class;
    }
}

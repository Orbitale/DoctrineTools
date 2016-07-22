<?php

/*
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
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

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
    /**
     * @var EntityManager
     */
    private $manager;

    /**
     * @var EntityRepository
     */
    private $repo;

    /**
     * @var int
     */
    private $order;

    /**
     * @var string
     */
    private $entityClass;

    /**
     * @var PropertyAccessor
     */
    private $propertyAccessor;

    /**
     * @var null|string
     */
    private $referencePrefix;

    /**
     * @var bool
     */
    private $searchForMatchingIds = true;

    /**
     * @var int
     */
    private $totalNumberOfObjects = 0;

    /**
     * @var int
     */
    private $numberOfIteratedObjects = 0;

    /**
     * @var int
     */
    private $flushEveryXIterations = 0;

    public function __construct()
    {
        $this->order                 = $this->getOrder();
        $this->flushEveryXIterations = $this->flushEveryXIterations();
        $this->searchForMatchingIds  = $this->searchForMatchingIds();
        $this->entityClass           = $this->getEntityClass();
        $this->referencePrefix       = $this->getReferencePrefix();
        $this->propertyAccessor      = class_exists('Symfony\Component\PropertyAccess\PropertyAccess') ? PropertyAccess::createPropertyAccessor() : null;
    }

    /**
     * {@inheritdoc}
     */
    public function load(ObjectManager $manager)
    {
        $this->manager = $manager;

        if ($this->disableLogger()) {
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
            !$this->flushEveryXIterations
            || ($this->flushEveryXIterations && $this->numberOfIteratedObjects !== $this->totalNumberOfObjects)
        ) {
            $this->manager->flush();
            $this->manager->clear();
        }
    }

    /**
     * Creates the object and persist it in database.
     *
     * @param array|object $data
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
        if ($id && $this->searchForMatchingIds) {
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
            if (is_array($data)) {
                $class = $this->entityClass;
                $obj = new $class;
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

            // If the ID is set, we tell Doctrine to force the insertion of it.
            if ($id) {
                $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
            }

            // And finally we persist the item
            $this->manager->persist($obj);

            // If we need to flush it, then we do it too.
            if (
                $this->flushEveryXIterations
                && $this->numberOfIteratedObjects
                && $this->numberOfIteratedObjects % $this->flushEveryXIterations === 0
            ) {
                $this->manager->flush();
                $this->manager->clear();
            }
            $addRef = true;
        }

        // If we have to add a reference, we do it
        if ($addRef === true && $obj && $this->referencePrefix) {
            if (1 === count($id)) {
                // Only reference single identifiers.
                reset($id);
                $id = current($id);
                $this->addReference($this->referencePrefix.($id ?: (string) $obj), $obj);
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
    private function getPropertyFromData($data, $key)
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

        if (isset($data[$key])) {
            return $data[$key];
        }
    }

    /**
     * Get the order of this fixture.
     * Default null means 0, so the fixture will be run at the beginning in order of appearance.
     * Is to be overriden if used.
     *
     * @return int
     */
    public function getOrder() {
        return $this->order;
    }

    /**
     * If true, the SQL logger will be disabled, and therefore will avoid memory leaks and save memory during execution.
     * Very useful for big batches of entities.
     *
     * @return bool
     */
    protected function disableLogger()
    {
        return false;
    }

    /**
     * Returns the prefix used to create fixtures reference.
     * If returns `null`, no reference will be created for the object.
     * NOTE: To create references of an object, it must have an ID, and if not, implement __toString(), because
     *   each object is referenced BEFORE flushing the database.
     * NOTE2: If you specified a "flushEveryXIterations" value, then the object will be provided with an ID every time.
     *
     * @return string|null
     */
    protected function getReferencePrefix() {
        return $this->referencePrefix;
    }

    /**
     * If specified, the entity manager will be flushed every X times, depending on your specified values.
     * Default is null, so the database is flushed only at the end of all persists.
     *
     * @return bool
     */
    protected function flushEveryXIterations()
    {
        return $this->flushEveryXIterations;
    }

    /**
     * If true and an ID is specified, will execute a $manager->find($id) in the database.
     * By default this var is true.
     * Be careful, if you set it to false you may have "duplicate entry" errors if your database is already populated.
     *
     * @return bool
     */
    protected function searchForMatchingIds()
    {
        return $this->searchForMatchingIds;
    }

    /**
     * Returns the class of the entity you're managing.
     *
     * @return string
     */
    protected abstract function getEntityClass();

    /**
     * Returns a list of objects to insert in the database.
     *
     * @return ArrayCollection|object[]
     */
    protected abstract function getObjects();

}

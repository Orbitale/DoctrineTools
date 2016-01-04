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

/**
 * This class is used mostly for inserting "fixed" datas, especially with their primary keys forced on insert.
 * Two methods are mandatory to insert new datas, and you can create them both as indexed array or as objects.
 * Objects are directly persisted to the database, and once they're all, the EntityManager is flushed with all objects.
 * You can override the `getOrder` and `getReferencePrefix` for more flexibility on how to link fixtures together.
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
     * {@inheritdoc}
     */
    public function load(ObjectManager $manager)
    {
        $this->manager = $manager;

        $this->repo = $this->manager->getRepository($this->getEntityClass());

        foreach ($this->getObjects() as $data) {
            $this->fixtureObject($data);
        }

        $this->manager->flush();
    }

    /**
     * Creates the object and persist it in database.
     *
     * @param array|object $datas
     */
    protected function fixtureObject($datas)
    {
        // The ID is taken in account to force its use in the database.
        $id = (is_object($datas) && method_exists($datas, 'getId') && $datas->getId())
            ? $datas->getId()
            : (isset($datas['id']) ? $datas['id'] : null);

        $obj = null;
        $newObject = false;
        $addRef = false;

        // If the user specifies an ID
        if ($id) {
            // Checks that the object ID exists in database
            $obj = $this->repo->find($id);
            if ($obj) {
                // If so, the object is not overwritten
                $addRef = true;
            } else {
                // Else, we create a new object
                $newObject = true;
            }
        } else {
            $newObject = true;
        }

        if ($newObject === true) {

            $accessor = class_exists('Symfony\Component\PropertyAccess\PropertyAccess') ? PropertyAccess::createPropertyAccessor() : null;

            // If the datas are in an array, we instanciate a new object
            if (is_array($datas)) {
                $class = $this->getEntityClass();
                $obj = new $class;
                foreach ($datas as $field => $value) {
                    if ($accessor) {
                        $accessor->setValue($obj, $field, $value);
                    } else {
                        // Force the use of a setter if accessor is not available
                        $obj->{'set'.ucfirst($field)}($value);
                    }
                }
            }

            // If the ID is set, we tell Doctrine to force the insertion of it
            if ($id) {
                /** @var ClassMetadata $metadata */
                $metadata = $this->manager->getClassMetaData(get_class($obj));
                $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
            }

            // And finally we persist the item
            $this->manager->persist($obj);
            $addRef = true;
        }

        // If we have to add a reference, we set it
        if ($addRef === true && $obj && $this->getReferencePrefix()) {
            $this->addReference($this->getReferencePrefix().($id ?: (string) $obj), $obj);
        }
    }

    /**
     * Returns the prefix used to create fixtures reference.
     * If returns `null`, no reference will be created for the object.
     * NOTE: To create references of an object, it must have an ID, and if not, implement __toString(), because
     *   each object is referenced BEFORE flushing the database.
     * Is to be overriden if used.
     *
     * @return string|null
     */
    protected function getReferencePrefix() {
        return null;
    }

    /**
     * Get the order of this fixture.
     * Is to be overriden if used.
     *
     * @return int
     */
    public function getOrder() {
        return 0;
    }

    /**
     * Returns the class of the entity you're managing
     *
     * @return string
     */
    protected abstract function getEntityClass();

    /**
     * Returns a list of objects to
     *
     * @return ArrayCollection|object[]
     */
    protected abstract function getObjects();

}

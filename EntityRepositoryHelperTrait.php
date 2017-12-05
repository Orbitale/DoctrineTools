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

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Query;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * This trait is a tool to be used with the Doctrine ORM.
 * It adds many useful methods to the default EntityRepository, and can be used in any ORM environment.
 *
 * Many methods accept a "$indexBy" parameter. This parameter is used to modify the returned collection index.
 * If you specify the $indexBy parameter, the returned array will be indexed by the specified field.
 * For instance, if you want to index by "id", you can have something similar to this:
 * [ '1' => ['id': 1, 'slug': 'object'] , '12' => ['id': '12', 'slug' => 'another-object'] ]
 * This is great to be sure that primary/unique indexes garantee unique objects in the returned array.
 */
trait EntityRepositoryHelperTrait
{
    /**
     * Finds all objects and retrieve only "root" objects, without their associated relatives.
     * This prevends potential "fetch=EAGER" to be thrown.
     */
    public function findAllRoot(string $indexBy = null): iterable
    {
        $this->checkRepository();

        return $this->createQueryBuilder('object')
            ->indexBy($indexBy)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Finds all objects and fetches them as array.
     */
    public function findAllArray(string $indexBy = null): array
    {
        $this->checkRepository();

        $datas = $this->createQueryBuilder('object', $indexBy)->getQuery()->getArrayResult();

        if ($datas && $indexBy) {
            $datas = $this->sortCollection($datas, $indexBy);
        }

        return $datas;
    }

    /**
     * Alias for findBy, but adding the $indexBy argument.
     *
     * {@inheritdoc}
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null, string $indexBy = null)
    {
        $this->checkRepository();

        $data = parent::findBy($criteria, $orderBy, $limit, $offset);

        if ($data && $indexBy) {
            $data = $this->sortCollection($data, $indexBy);
        }

        return $data;
    }

    /**
     * Alias for findAll, but adding the $indexBy argument.
     * If you do not want the associated elements to be fetched at the same time, please {@see EntityRepositoryHelperTrait::findAllRoot}
     *
     * {@inheritdoc}
     */
    public function findAll(string $indexBy = null)
    {
        $this->checkRepository();

        $datas = $this->findBy(array());

        if ($datas && $indexBy) {
            $datas = $this->sortCollection($datas, $indexBy);
        }

        return $datas;
    }

    /**
     * Gets current AUTO_INCREMENT value from table.
     * Useful to see get the maximum ID of the table.
     * NOTE: Not compatible with every platform.
     */
    public function getAutoIncrement(): int
    {
        $this->checkRepository();

        $table = $this->getClassMetadata()->getTableName();

        $connection = $this->getEntityManager()->getConnection();
        $statement  = $connection->prepare('SHOW TABLE STATUS LIKE "'.$table.'" ');
        $statement->execute();
        $data = $statement->fetch();

        return (int) $data['Auto_increment'];
    }

    /**
     * Gets total number of elements in the table.
     */
    public function getNumberOfElements(): ?int
    {
        $this->checkRepository();

        return $this->_em->createQueryBuilder()
            ->select('count(a)')
            ->from($this->getEntityName(), 'a')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Sorts a collection by a specific key, usually the primary key one,
     *  but you can specify any key.
     * For "cleanest" uses, you'd better use a primary or unique key.
     */
    public function sortCollection(iterable $collection, string $indexBy = null): iterable
    {
        $this->checkRepository();

        $finalCollection = array();
        $currentObject   = current($collection);
        $accessor        = class_exists('Symfony\Component\PropertyAccess\PropertyAccess') ? PropertyAccess::createPropertyAccessor() : null;

        if ('_primary' === $indexBy || true === $indexBy) {
            $indexBy = $this->getClassMetadata()->getSingleIdentifierFieldName();
        }

        if (is_object($currentObject) && property_exists($currentObject, $indexBy) && method_exists($currentObject, 'get'.ucfirst($indexBy))) {

            // Sorts a list of objects only if the property and its getter exist
            foreach ($collection as $entity) {
                $id = $accessor ? $accessor->getValue($entity, $indexBy) : $entity->{'get'.ucFirst($indexBy)};
                $finalCollection[$id] = $entity;
            }
            return $finalCollection;
        }

        if (is_array($currentObject) && array_key_exists($indexBy, $currentObject)) {
            // Sorts a list of arrays only if the key exists
            foreach ($collection as $array) {
                $finalCollection[$array[$indexBy]] = $array;
            }
            return $finalCollection;
        }

        if ($collection) {
            throw new \InvalidArgumentException('The collection to sort by index seems to be invalid.');
        }

        return $collection;
    }

    /**
     * Gets the list of all single identifiers (id) from table
     */
    public function getIds(): iterable
    {
        $this->checkRepository();

        $primaryKey  = $this->getClassMetadata()->getSingleIdentifierFieldName();

        $result = $this->_em
            ->createQueryBuilder()
            ->select('entity.'.$primaryKey)
            ->from($this->_entityName, 'entity')
            ->getQuery()
            ->getResult(Query::HYDRATE_ARRAY)
        ;

        $array = array();

        foreach ($result as $id) {
            $array[] = $id[$primaryKey];
        }

        return $array;
    }

    private function checkRepository(): void
    {
        if (!$this instanceof EntityRepository) {
            throw new \RuntimeException(sprintf('This trait can only be used by %s classes.', EntityRepository::class));
        }
    }
}

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

/**
 * This class is a tool to be used with the Doctrine ORM.
 * It adds many useful methods to the default EntityRepository, and can be used in any ORM environment.
 * For Symfony, if you want to change the default EntityRepository to this one, just change the configuration,
 * in app/config.yml
 * doctrine:
 *     orm:
 *         default_repository_class: Orbitale\Component\DoctrineTools\BaseEntityRepository
 *
 * Many methods accept a "$indexBy" parameter. This parameter is used to modify the returned collection index.
 * If you specify the $indexBy parameter, the returned array will be indexed by the specified field.
 * For instance, if you want to index by "id", you can have something similar to this:
 * [ '1' => ['id': 1, 'slug': 'object'] , '12' => ['id': '12', 'slug' => 'another-object'] ]
 * This is great to be sure that primary/unique indexes garantee unique objects in the returned array.
 *
 * @package Orbitale\Component\DoctrineTools
 */
class BaseEntityRepository extends EntityRepository
{

    /**
     * Finds all objects and retrieve only "root" objects, without their associated relatives.
     * This prevends potential "fetch=EAGER" to be thrown.
     *
     * @param string $indexBy The field to use as array key index.
     *
     * @return object[]
     */
    public function findAllRoot($indexBy = null)
    {
        if ($indexBy && strpos($indexBy, '.') === false) {
            $indexBy = 'object.'.$indexBy;
        }

        return $this->createQueryBuilder('object', $indexBy)->getQuery()->getResult();
    }

    /**
     * Finds all objects and fetches them as array.
     *
     * @param string $indexBy The field to use as array key index.
     *
     * @return array[]
     */
    public function findAllArray($indexBy = null)
    {
        return $this->createQueryBuilder('object', $indexBy)->getQuery()->getArrayResult();
    }

    /**
     * Alias for findBy, but adding the $indexBy argument.
     *
     * {@inheritdoc}
     *
     * @param string $indexBy The field to use as array key index.
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null, $indexBy = null)
    {
        $datas = parent::findBy($criteria, $orderBy, $limit, $offset);
        if ($datas && $indexBy) {
            $datas = $this->sortCollection($datas);
        }

        return $datas;
    }

    /**
     * Alias for findAll, but adding the $indexBy argument.
     *
     * {@inheritdoc}
     *
     * @param string $indexBy The field to use as array key index.
     */
    public function findAll($indexBy = false)
    {
        $datas = $this->findBy(array());

        if ($datas && true === $indexBy) {
            $datas = $this->sortCollection($datas);
        }

        return $datas;
    }

    /**
     * Gets current AUTO_INCREMENT value from table.
     * Useful to see get the maximum ID of the table.
     * NOTE: Not compatible with every platform.
     *
     * @internal
     * @return integer
     */
    public function getAutoIncrement()
    {
        $table = $this->getClassMetadata()->getTableName();

        $connection = $this->getEntityManager()->getConnection();
        $statement  = $connection->prepare('SHOW TABLE STATUS LIKE "'.$table.'" ');
        $statement->execute();
        $datas = $statement->fetch();

        $max = (int) $datas['Auto_increment'];

        return $max;
    }

    /**
     * Gets total number of elements in the table.
     *
     * @return integer
     */
    public function getNumberOfElements()
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->select('count(a)')
           ->where('a.deleted = 0')
           ->from($this->getEntityName(), 'a');

        $count = $qb->getQuery()->getSingleScalarResult();

        return $count;
    }

    /**
     * Sorts a collection by a specific key, usually the primary key one,
     *  but you can specify any key.
     * For "cleanest" uses, you'd better use a primary or unique key.
     *
     * @param object[] $collection  The collection to sort by index
     * @param string   $indexBy     The field to use as array key index.
     * @throws MappingException
     * @return array[]|object[]
     */
    public function sortCollection($collection, $indexBy = '_primary')
    {
        $finalCollection = array();
        $currentObject   = current($collection);

        if ('_primary' === $indexBy) {
            $indexBy = $this->getClassMetadata()->getSingleIdentifierFieldName();
        }

        if (is_object($currentObject) && property_exists($currentObject, $indexBy) && method_exists($currentObject, 'get'.ucfirst($indexBy))) {
            // Sorts a list of objects only if the property and its getter exist
            foreach ($collection as $entity) {
                $finalCollection[$entity->{'get'.ucfirst($indexBy)}()] = $entity;
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
     *
     * @return array
     *
     * @throws MappingException
     */
    public function getIds()
    {
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
}

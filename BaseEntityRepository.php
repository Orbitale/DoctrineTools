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

use Doctrine\ORM\EntityRepository;

class BaseEntityRepository extends EntityRepository
{

}

@trigger_error(sprintf('Class %s is deprecated, use the %s trait instead.', BaseEntityRepository::class, EntityRepositoryHelperTrait::class), E_USER_DEPRECATED);

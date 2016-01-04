Orbitale Doctrine Tools
=======================

This library is composed of multiple tools to be used with the Doctrine ORM.


### Documentation

* [Installation](#installation)
* [Usage](#usage)
  * [Entity Repository](#entity-repository)
  * [Doctrine Fixtures](#doctrine-fixtures)


# Installation

Simply install the library with [Composer](https://getcomposer.org):

```php
    composer require orbitale/doctrine-tools:~0.1
```

# Usage

## Entity Repository

There are 3 ways of using `BaseEntityRepository`:

1. In your own repositories, just extend the Orbitale's one:

```php
<?php

namespace AppBundle\Repository;

use Orbitale\Component\DoctrineTools\BaseEntityRepository;

class PostRepository extends BaseEntityRepository
{

    // Your custom logic here ...

}

```

2. If you are using [Symfony](http://symfony.com/), you can override the default entity manager in the configuration:

```yml
# app/config.yml
doctrine:
    orm:
        default_repository_class: Orbitale\Component\DoctrineTools\BaseEntityRepository

```

3. If you are using Doctrine "natively", you can override the default entity repository class in the Doctrine Configuration class:

```php

$configuration = new Doctrine\ORM\Configuration();
$configuration->setDefaultRepositoryClassName('Orbitale\Component\DoctrineTools\BaseEntityRepository');

// Build the EntityManager with its configuration...

```

This way, you can use your EntityRepository exactly like before, it just adds new cool methods!

Just take a look at the [BaseEntityRepository](BaseEntityRepository.php) class to see what nice features it adds!

## Doctrine Fixtures

This class is used mostly when you have to create Doctrine Fixtures, and when you want the most simple way to do it.

To use it, you **must** install `doctrine/data-fixtures` (and `doctrine/doctrine-fixtures-bundle` if you are using Symfony).

Here is a small example of a new fixtures class:

```php
<?php

namespace AppBundle\DataFixtures\ORM;

use Orbitale\Component\DoctrineTools\AbstractFixture;

class PostFixtures extends AbstractFixture
{

    public function getEntityClass() {
        return 'AppBundle\Entity\Post';
    }
    
    public function getObjects() {
        return [
            ['id' => 1, 'title' => 'First post', 'description' => 'Lorem ipsum'],
            ['id' => 2, 'title' => 'Second post', 'description' => 'muspi meroL'],
        ];
    }

}

```

### Methods of the `AbstractFixture` class that can be overriden:

* `getOrder()` to change the order in which the fixtures will be loaded.
* `getReferencePrefix()` to add a reference in the Fixtures' batch so you can use them later.
  References are stored as `{referencePrefix}-{id|__toString()}`.
* `flushEveryXIterations()` to flush in batches instead of flushing only once at the end of all fixtures persist.
* `searchForMatchingIds()` to check that an ID exists in database and therefore not insert it back if it does.
* `disableLogger()` to disable SQL queries logging, useful to save memory at runtime.

This way, 2 objects are automatically persisted in the database, and they're all identified with their ID.
Also, if you run the symfony `app/console doctrine:fixtures:load` using the `--append` option, the IDs will be detected
in the database and will not be inserted twice, with no error so you can really use fixtures as reference datas!

Take a look at the [AbstractFixture](AbstractFixture.php) class to see what other methods you can override!

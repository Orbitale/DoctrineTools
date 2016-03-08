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

### Using a callable to get a reference

When you have self-referencing relationships, you may need a reference of an object that may have already been persisted.

For this, first, you should set the `flushEveryXIterations` option to `1` (view below) to allow flushing on every iteration.

And next, you can set a `callable` element as the value of your object so you can interact manually with the injected object
 as 1st argument, and the `AbstractFixture` object as 2nd argument.

The `ObjectManager` is also injected as 3rd argument in case you need to do some specific requests or query through another
 table.

Example here:

```php
<?php

namespace AppBundle\DataFixtures\ORM;

use Orbitale\Component\DoctrineTools\AbstractFixture;
use Doctrine\Common\Persistence\ObjectManager;

class PostFixtures extends AbstractFixture
{

    public function getEntityClass() {
        return 'AppBundle\Entity\Post';
    }

    /**
     * With this, we can retrieve a Post reference with this method:
     * $this->getReference('posts-1');
     * where '1' is the post id.
     */
    public function getReferencePrefix() {
        return 'posts-';
    }

    /**
     * Set this to 1 so the first post is always persisted before the next one.
     */
    public function flushOnEveryXIterations() {
        return 1;
    }

    public function getObjects() {
        return [
            ['id' => 1, 'title' => 'First post', 'parent' => null],
            [
                'id' => 2,
                'title' => 'Second post',
                'parent' => function(Post $object, AbstractFixture $fixture, ObjectManager $manager) {
                    $ref = $fixture->getReference('posts-1');
                    $object->setParent($ref); // This is often needed if you don't use cascade persist
                    return $ref;
                },
            ],
        ];
    }

}

```

This allows perfect synchronicity when dealing with self-referencing relations.

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

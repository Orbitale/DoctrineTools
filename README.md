## :warning: This package is abandoned, please use [Orbitale/ArrayFixture](https://github.com/Orbitale/ArrayFixture) instead

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

There are 3 ways of using `EntityRepositoryHelperTrait`:

1. In your own repositories, just extend the Orbitale's one:

```php
<?php

namespace AppBundle\Repository;

use Orbitale\Component\DoctrineTools\EntityRepositoryHelperTrait;

class PostRepository
{
    use EntityRepositoryHelperTrait;

    // Your custom logic here ...

}

```

2. If you are using [Symfony](http://symfony.com/), you can override the default entity manager in the configuration:

```yml
# app/config.yml
doctrine:
    orm:
        default_repository_class: Orbitale\Component\DoctrineTools\EntityRepositoryHelperTrait

```

3. If you are using Doctrine "natively", you can override the default entity repository class in the Doctrine Configuration class:

```php

$configuration = new Doctrine\ORM\Configuration();
$configuration->setDefaultRepositoryClassName('Orbitale\Component\DoctrineTools\EntityRepositoryHelperTrait');

// Build the EntityManager with its configuration...

```

This way, you can use your EntityRepository exactly like before, it just adds new cool methods!

Just take a look at the [EntityRepositoryHelperTrait](EntityRepositoryHelperTrait.php) class to see what nice features it adds!

## Doctrine Fixtures

This class is used mostly when you have to create Doctrine Fixtures, and when you want the most simple way to do it.

To use it, you **must** install `doctrine/data-fixtures` (and `doctrine/doctrine-fixtures-bundle` if you are using Symfony).

Here is a small example of a new fixtures class:

```php
<?php

namespace AppBundle\DataFixtures\ORM;

use App\Entity\Post;
use Orbitale\Component\DoctrineTools\AbstractFixture;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;

class PostFixtures extends AbstractFixture implements ORMFixtureInterface
{
    public function getEntityClass(): string
    {
        return Post::class;
    }

    public function getObjects(): array
    {
        return [
            ['title' => 'First post', 'description' => 'Lorem ipsum'],
            ['title' => 'Second post', 'description' => 'muspi meroL'],
        ];
    }
}
```

### Using a callable to get a reference

When you have self-referencing relationships, you may need a reference of an object that may have already been persisted.

For this, first, you should set the `flushEveryXIterations` option to `1` (view below) to allow flushing on every iteration.

And next, you can set a `callable` element as the value of your object so you can interact manually with the injected object
 as 1st argument, and the `AbstractFixture` object as 2nd argument.

The `EntityManagerInterface` is also injected as 3rd argument in case you need to do some specific requests or query through another
 table.

Example here:

```php
<?php

namespace App\DataFixtures\ORM;

use App\Entity\Post;
use Orbitale\Component\DoctrineTools\AbstractFixture;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;

class PostFixtures extends AbstractFixture implements ORMFixtureInterface
{
    public function getEntityClass(): string
    {
        return Post::class;
    }

    /**
     * With this, we can retrieve a Post reference with this method:
     * $this->getReference('posts-1');
     * where '1' is the post id.
     * Only works with same object if it's flushed on every iteration.
     */
    public function getReferencePrefix(): ?string
    {
        return 'posts-';
    }

    /**
     * Set this to 1 so the first post is always persisted before the next one.
     * This is mandatory as we are referencing the same object. 
     * If we had to use a reference to another object, only "getOrder()" would have to be overriden. 
     */
    public function flushEveryXIterations(): int 
    {
        return 1;
    }

    public function getObjects(): array
    {
        return [
            ['id' => 'c5022243-343b-40c3-8c88-09c1a76faf78', 'title' => 'First post', 'parent' => null],
            [
                'title' => 'Second post',
                'parent' => function(Post $object, AbstractFixture $fixture, EntityManagerInterface $manager) {
                    return $fixture->getReference('posts-c5022243-343b-40c3-8c88-09c1a76faf78');
                },
            ],
        ];
    }
}
```

This allows perfect synchronicity when dealing with self-referencing relations.

### Methods of the `AbstractFixture` class that can be overriden:

* `getOrder()` (default `0`) to change the order in which the fixtures will be loaded.
* `getReferencePrefix()` (default `null`) to add a reference in the Fixtures' batch so you can use them later.
  References are stored as `{referencePrefix}-{id|__toString()}`.
* `getMethodNameForReference()` (default `getId`) to specify which method on the object is used to specify the 
  reference. Defaults to `getId` and **always falls back** to `__toString()` if exists.
* `flushEveryXIterations()` (default `0`) to flush in batches instead of flushing only once at the end of all fixtures persist.
* `disableLogger()` to disable SQL queries logging, useful to save memory at runtime.

This way, 2 objects are automatically persisted in the database, and they're all identified with their ID.
Also, if you run the symfony `app/console doctrine:fixtures:load` using the `--append` option, the IDs will be detected
in the database and will not be inserted twice, with no error so you can really use fixtures as reference datas!

Take a look at the [AbstractFixture](AbstractFixture.php) class to see what other methods you can override!

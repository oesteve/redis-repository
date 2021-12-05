<?php

namespace Oesteve\RedisRepository\Tests;

use Oesteve\RedisRepository\Config\ClassMapping;
use Oesteve\RedisRepository\Config\PropertyMapping;
use Oesteve\RedisRepository\Repository;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase
{
    private \Redis $client;

    public function setUp(): void
    {
        parent::setUp();

        $client = $this->getClient();
        $client->flushAll();
        $this->client = $client;
    }


    public function testInvalidClassOnPersist(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            []
        );

        $object = new InvalidClass();

        $this->expectExceptionMessage("Unable to persists object of type Oesteve\RedisRepository\Tests\InvalidClass, Oesteve\RedisRepository\Tests\MyClass allowed");
        $this->expectException("Oesteve\RedisRepository\RepositoryException");
        /** @phpstan-ignore-next-line */
        $repository->persist($object);
    }

    public function testPrimaryKeyNotDefinedOnPersist(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            []
        );

        $object = new MyClass("foo");

        $this->expectExceptionMessage("Primary key not defined for class Oesteve\RedisRepository\Tests\MyClass");
        $this->expectException("Oesteve\RedisRepository\RepositoryException");
        $repository->persist($object);
    }

    public function testPersist(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            [ new PropertyMapping('foo', 'string', true)]
        );

        $object = new MyClass("foo");

        $repository->persist($object);

        $this->assertEquals(
            [
            '59cb0368_MyClass_foo_foo',
            '59cb0368_MyClass_pkey_foo',
            '59cb0368_MyClass_all',
            ],
            $this->getClient()->keys('*')
        );
    }

    public function testFindNotFound(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            [ new PropertyMapping('foo', 'string', true)]
        );

        $res = $repository->find('foo');
        $this->assertNull($res);
    }

    public function testFind(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            [ new PropertyMapping('foo', 'string', true)]
        );

        $object = new MyClass('foo');
        $repository->persist($object);

        $res = $repository->find('foo');
        $this->assertNotNull($res);
    }

    public function testFindOneBy(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            [ new PropertyMapping('foo', 'string', true)]
        );

        $repository->persist(new MyClass("bar"));

        $res = $repository->findOneBy('foo', 'bar');

        $this->assertEquals(new MyClass('bar'), $res);
    }

    public function testFindBy(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            [ new PropertyMapping('foo', 'string', true)]
        );

        for ($i=0; $i < 20; $i++) {
            $repository->persist(new MyClass("Object ".$i));
        }

        $res = $repository->findBy('foo', 'Object 10');

        $this->assertEquals(new MyClass('Object 10'), $res[0]);
    }

    public function testFindAll(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            [ new PropertyMapping('foo', 'string', true)]
        );

        for ($i=0; $i < 2; $i++) {
            $repository->persist(new MyClass('User '.$i));
        }

        $res = $repository->findAll();

        $this->assertCount(2, $res);
        $this->assertInstanceOf(MyClass::class, $res[0]);
    }

    public function testFindAllSorted(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            [ new PropertyMapping('foo', 'string', true)]
        );


        for ($i=0; $i < 100; $i++) {
            $repository->persist(new MyClass('User '.$i));
        }

        $res = $repository->findAll(50, 15, 'foo');

        $this->assertCount(15, $res);
    }

    public function testDelete(): void
    {
        $repository = new Repository(
            $this->client,
            new ClassMapping(MyClass::class),
            [ new PropertyMapping('foo', 'string', true)]
        );

        $repository->persist(new MyClass('User 101'));
        $repository->persist(new MyClass('User 102'));

        $keys = $this->getClient()->keys('*');
        $this->assertCount(5, $keys);

        $repository->delete(new MyClass('User 101'));
        $keys = $this->getClient()->keys('*');
        $this->assertCount(3, $keys);

        $repository->delete(new MyClass('User 102'));
        $keys = $this->getClient()->keys('*');
        $this->assertCount(0, $keys);
    }

    private function getClient(): \Redis
    {
        $client = new \Redis();
        $client->connect('127.0.0.1');

        return $client;
    }
}


class MyClass
{
    public string $foo;

    /**
     * @param string $foo
     */
    public function __construct(string $foo)
    {
        $this->foo = $foo;
    }
}

class InvalidClass
{
}

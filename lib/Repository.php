<?php

namespace Oesteve\RedisRepository;

use Oesteve\RedisRepository\Config\ClassMapping;
use Oesteve\RedisRepository\Config\PropertyMapping;
use Redis;

/**
 * @template T of object
 */
class Repository
{
    private Redis $client;
    /** @var ClassMapping<T> */
    private ClassMapping $class;
    /** @var array<PropertyMapping> */
    private array $properties;

    /**
     * @param ClassMapping<T> $class
     * @param array<PropertyMapping> $properties
     *
     * @throws RepositoryException
     *
     */
    public function __construct(
        Redis       $client,
        ClassMapping $class,
        array $properties,
    ) {
        $this->client = $client;
        $this->class = $class;
        $this->properties = $properties;
    }

    /**
     * @return ClassMapping<T>
     */
    public function getClass(): ClassMapping
    {
        return $this->class;
    }


    public function find(string $key): ?object
    {
        $redisKey = $this->getRedisKey('pkey', $key);
        $get = $this->client->hGet($redisKey, '__object');

        if (!$get) {
            return null;
        }

        return $this->unserialize($get);
    }

    /**
     * @param string ...$ars
     * @return string
     */
    private function getRedisKey(...$ars): string
    {
        return sprintf('%s_%s', $this->class->getClassPrefix(), implode('_', $ars));
    }

    /**
     * @return array<T>
     */
    public function findAll(?int $start = null, ?int $end = null, ?string $sortBy = null): array
    {
        $allKey = $this->getRedisKey('all');

        $options = [
            'get' => $this->getSortGetPattern()
        ];

        if ($start) {
            $options['limit'][0] = $start;
        }

        if ($end) {
            $options['limit'][1] = $end;
        }

        if ($this->getPKeyMapping()->getPropertyType() === PropertyMapping::TYPE_STRING) {
            $options['alpha'] = true;
        }

        if ($sortBy) {
            $sortMapping = $this->getPropertyMapping($sortBy);

            $byPattern = $this->getRedisKey('pkey', '*->'.$sortMapping->getPropertyName());
            $options['by'] = $byPattern;
            $options['alpha'] = $sortMapping->getPropertyType() === PropertyMapping::TYPE_STRING;
        }

        $res = $this->client->sort($allKey, $options);

        if (!is_array($res)) {
            throw new RepositoryException("Unexpected redis sort result");
        }

        return $this->unserializeMany($res);
    }

    /**
     * @param string $data
     * @return T
     */
    private function unserialize(mixed $data): object
    {
        return unserialize($data, ['allowed_classes' => [$this->getClass()->getClassName()]]);
    }

    /**
     * @param mixed $res
     * @return string
     */
    private function serializer(mixed $res): string
    {
        return serialize($res);
    }

    /**
     * @param string $propertyName
     * @param string $value
     * @return T | null
     * @throws RepositoryException
     */
    public function findOneBy(string $propertyName, string $value): ?object
    {
        $result = $this->findBy($propertyName, $value);

        if (!count($result)) {
            return null;
        }

        return $result[0];
    }

    /**
     * @param string $propertyName
     * @param string $value
     * @return array<T>
     * @throws RepositoryException
     */
    public function findBy(string $propertyName, string $value): array
    {
        $propertyMapping = $this->getPropertyMapping($propertyName);

        $resultKey = $this->getRedisKey($propertyName, $value);
        $options = [
            'get' => $this->getSortGetPattern()
        ];

        if ($propertyMapping->getPropertyType() === PropertyMapping::TYPE_STRING) {
            $options['alpha'] = true;
        }

        $result = $this->client->sort(
            $resultKey,
            $options
        );

        if (!is_array($result)) {
            return [];
        }

        return $this->unserializeMany($result);
    }

    public function delete(object $object): void
    {

        // Remove from all set
        $key = $this->getObjectPrimaryKeyValue($object);
        $setKey = $this->getRedisKey('all');
        $this->client->sRem($setKey, $key);

        // Remove pkey key
        $objectPKeyKey = $this->getRedisKey('pkey', $key);
        $this->client->del($objectPKeyKey);

        foreach ($this->properties as $property) {
            $propertyName = $property->getPropertyName();
            $propertyValue = $object->$propertyName;
            $objectPKeyKey = $this->getRedisKey($propertyName, $propertyValue);
            $this->client->del($objectPKeyKey);
        }
    }

    /**
     * @param T $object
     * @return void
     */
    public function persist(object $object): void
    {

        // Set primary key record
        $repoClassName = $this->class->getClassName();
        if (!$object instanceof $repoClassName) {
            throw new RepositoryException("Unable to persists object of type ".get_class($object).", ".$repoClassName. " allowed");
        }

        $objectPKeyValue = $this->getObjectPrimaryKeyValue($object);
        $setAll = $this->getRedisKey('all');
        $this->client->sAdd($setAll, $objectPKeyValue);

        // Set object data
        $hMSetKey = $this->getRedisKey('pkey', $objectPKeyValue);
        $hashKeys = [
            '__object' => $this->serializer($object)
        ];
        $this->client->hMSet($hMSetKey, $hashKeys);

        // Mapped properties

        foreach ($this->properties as $property) {
            $propertyName = $property->getPropertyName();
            $propertyValue = $object->$propertyName;
            $setKey = $this->getRedisKey($property->getPropertyName(), $propertyValue);
            $this->client->sAdd($setKey, $objectPKeyValue);
        }
    }

    private function getObjectPrimaryKeyValue(object $object): string
    {
        $mapping = $this->getPKeyMapping();
        $property = $mapping->getPropertyName();

        return $object->$property;
    }

    /**
     * @param string $propertyName
     * @return PropertyMapping
     * @throws RepositoryException
     */
    private function getPropertyMapping(string $propertyName): PropertyMapping
    {
        foreach ($this->properties as $property) {
            if ($property->getPropertyName() === $propertyName) {
                return $property;
            }
        }

        throw new RepositoryException("Mapping for $propertyName not found for class ".$this->class->getClassName());
    }


    private function getPKeyMapping(): PropertyMapping
    {
        foreach ($this->properties as $property) {
            if ($property->isPrimary()) {
                return $property;
            }
        }

        throw new RepositoryException("Primary key not defined for class ".$this->class->getClassName());
    }

    /**
     * @return string
     */
    private function getSortGetPattern(): string
    {
        $objectPattern = $this->getRedisKey('pkey', '*');
        return $objectPattern . '->__object';
    }

    /**
     * @param array<string> $data
     * @return array<T>
     */
    private function unserializeMany(array $data): array
    {
        return array_map(fn (string $objectData) => $this->unserialize($objectData), $data);
    }
}

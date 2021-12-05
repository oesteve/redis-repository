<?php

namespace Oesteve\RedisRepository\Config;

class PropertyMapping
{
    public const TYPE_STRING = 'string';
    private string $propertyName;
    private string $propertyType;
    private bool $primary;

    /**
     * @param string $propertyName
     * @param string $propertyType
     */
    public function __construct(string $propertyName, string $propertyType, bool $primary)
    {
        $this->propertyName = $propertyName;
        $this->propertyType = $propertyType;
        $this->primary = $primary;
    }

    /**
     * @return string
     */
    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    /**
     * @return string
     */
    public function getPropertyType(): string
    {
        return $this->propertyType;
    }

    /**
     * @return bool
     */
    public function isPrimary(): bool
    {
        return $this->primary;
    }
}

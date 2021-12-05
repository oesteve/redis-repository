<?php

namespace Oesteve\RedisRepository\Config;

use Oesteve\RedisRepository\RepositoryException;

/**
 * @template T
 */
class ClassMapping
{
    private string $className;
    private string $classPrefix;

    /**
     * @param class-string<T> $className
     */
    public function __construct(string $className)
    {
        $this->className = $className;
        $this->buildClassPrefix();
    }

    /**
     * @return string
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @return string
     */
    public function getClassPrefix(): string
    {
        return $this->classPrefix;
    }

    /**
     * @return void
     * @throws RepositoryException
     */
    private function buildClassPrefix(): void
    {
        $hash = hash('crc32', $this->className);

        $matches = null;
        if (!preg_match('/[a-z]*$/i', $this->className, $matches)) {
            throw new RepositoryException('Invalid repository class name');
        }

        $name = $matches[0];

        $this->classPrefix =  sprintf('%s_%s', $hash, $name);
    }
}

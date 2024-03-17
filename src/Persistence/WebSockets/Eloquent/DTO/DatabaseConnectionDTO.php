<?php

namespace Conveyor\Persistence\WebSockets\Eloquent\DTO;

use ArrayAccess;

/**
 * @implements ArrayAccess<array-key, mixed>
 */
class DatabaseConnectionDTO implements ArrayAccess
{
    /**
     * @param string $driver
     * @param string $host
     * @param string $database
     * @param int|null $port
     * @param string|null $username
     * @param string|null $password
     * @param string $charset
     * @param string $collation
     * @param string $prefix
     * @param array<array-key, mixed> $options
     */
    public function __construct(
        public string $driver,
        public string $host,
        public string $database,
        public ?int $port = null,
        public ?string $username = null,
        public ?string $password = null,
        public string $charset = 'utf8',
        public string $collation = 'utf8_unicode_ci',
        public string $prefix = '',
        public array $options = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['driver'],
            $data['host'] ?? '',
            $data['database'],
            $data['username'] ?? null,
            $data['password'] ?? null,
            $data['charset'] ?? 'utf8',
            $data['collation'] ?? 'utf8_unicode_ci',
            $data['prefix'] ?? ''
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'prefix' => $this->prefix,
        ];
    }

    public function offsetExists($offset): bool
    {
        return property_exists($this, $offset);
    }

    public function offsetGet($offset): mixed
    {
        return $this->{$offset};
    }

    public function offsetSet($offset, $value): void
    {
        $this->{$offset} = $value;
    }

    public function offsetUnset($offset): void
    {
        $this->{$offset} = null;
    }
}

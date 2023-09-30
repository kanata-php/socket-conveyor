<?php

namespace Conveyor\Persistence\DTO;

use ArrayAccess;

class DatabaseConnectionDTO implements ArrayAccess
{
    public function __construct(
        public string $driver,
        public string $database,
        public ?string $username = null,
        public ?string $password = null,
        public string $charset = 'utf8',
        public string $collation = 'utf8_unicode_ci',
        public string $prefix = '',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['driver'],
            $data['database'],
            $data['username'] ?? null,
            $data['password'] ?? null,
            $data['charset'] ?? 'utf8',
            $data['collation'] ?? 'utf8_unicode_ci',
            $data['prefix'] ?? ''
        );
    }

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

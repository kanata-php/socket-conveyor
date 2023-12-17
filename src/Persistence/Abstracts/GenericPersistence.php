<?php

namespace Conveyor\Persistence\Abstracts;

use Conveyor\Persistence\DTO\DatabaseConnectionDTO;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Illuminate\Database\Capsule\Manager;

abstract class GenericPersistence implements GenericPersistenceInterface
{
    /**
     * @param DatabaseConnectionDTO|array{
     *     driver:string,
     *     host:string,
     *     database:string,
     *     port:int|null,
     *     username:string|null,
     *     password:string|null,
     *     charset:string,
     *     collation:string,
     *     prefix:string
     * } $databaseOptions
     */
    public function __construct(
        protected DatabaseConnectionDTO|array $databaseOptions = [
            'driver' => 'sqlite',
            'host' => '',
            'database' => __DIR__ . '/../../../database.sqlite',
            'port' => null,
            'username' => null,
            'password' => null,
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
        ],
        protected ?Manager $manager = null,
    ) {
        if (is_array($this->databaseOptions)) {
            $this->databaseOptions = DatabaseConnectionDTO::fromArray($this->databaseOptions);
        }
    }

    abstract public function refresh(bool $fresh = false): static;
}

<?php

namespace Conveyor\Persistence\Abstracts;

use Conveyor\Persistence\DTO\DatabaseConnectionDTO;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Illuminate\Database\Capsule\Manager;

abstract class GenericPersistence implements GenericPersistenceInterface
{
    /**
     * @param @param DatabaseConnectionDTO|array{driver:string,database:string,username:string,password:string,charset:string,collation:string,prefix:string} $databaseOptions
     */
    public function __construct(
        protected DatabaseConnectionDTO|array $databaseOptions = [
            'driver' => 'sqlite',
            'host' => '',
            'database' => __DIR__ . '/../../../../../database/database.sqlite',
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

        $this->refresh(true);
    }

    abstract public function refresh(bool $fresh = false): static;
}

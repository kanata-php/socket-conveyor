<?php

namespace Tests;

use Conveyor\Persistence\DTO\DatabaseConnectionDTO;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    public function getDatabaseOptions(): DatabaseConnectionDTO
    {
        return DatabaseConnectionDTO::fromArray([
            'driver' => 'sqlite',
            'database' => __DIR__ . '/Assets/temp-database.sqlite',
        ]);
    }
}

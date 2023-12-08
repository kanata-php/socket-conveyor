<?php

namespace Tests;

use Conveyor\Persistence\DTO\DatabaseConnectionDTO;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected string $dbLocation = __DIR__ . '/Assets/temp-database.sqlite';

    public function getDatabaseOptions(): DatabaseConnectionDTO
    {
        return DatabaseConnectionDTO::fromArray([
            'driver' => 'sqlite',
            'database' => $this->dbLocation,
        ]);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->dbLocation)) {
            unlink($this->dbLocation);
        }
    }
}

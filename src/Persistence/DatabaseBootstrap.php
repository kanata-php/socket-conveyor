<?php

namespace Conveyor\Persistence;

use Conveyor\Models\WsAssociation;
use Conveyor\Models\WsChannel;
use Conveyor\Models\WsListener;
use Conveyor\Persistence\DTO\DatabaseConnectionDTO;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

class DatabaseBootstrap
{
    /**
     * @param DatabaseConnectionDTO|array{
     *     driver:string,
     *     database:string,
     *     username:string,
     *     password:string,
     *     charset:string,
     *     collation:string,
     *     prefix:string
     * } $databaseOptions
     * @throws Exception
     */
    public function __construct(
        protected DatabaseConnectionDTO|array $databaseOptions,
        protected ?Manager $manager = null,
    ) {
        /** @throws Exception */
        $this->validateDatabaseOptions();

        if (is_array($this->databaseOptions)) {
            $this->databaseOptions = DatabaseConnectionDTO::fromArray($this->databaseOptions);
        }

        $database = $this->databaseOptions['database'];
        if (!file_exists($database) && !touch($database)) {
            throw new Exception('Failed to create database file (' . $database . ') for Conveyor functionalities!');
        }

        $this->startCapsule();
    }

    private function startCapsule(): void
    {
        if (!$this->isLaravel()) {
            $this->getCapsule();
            return;
        }

        if (!function_exists('config')) {
            return;
        }

        if (null === config('database.connections.socket-conveyor')) {
            config([
                'database.connections.socket-conveyor' => [
                    'driver'   => 'sqlite',
                    'database' => $this->databaseOptions['database'],
                    'prefix'   => '',
                ]
            ]);
        }
    }

    private function getCapsule(): Manager
    {
        if (null !== $this->manager) {
            return $this->manager;
        }

        $manager = new Manager();
        $manager->addConnection($this->databaseOptions->toArray(), 'socket-conveyor');
        $manager->setAsGlobal();
        $manager->bootEloquent();
        return $manager;
    }

    public function migrateChannelPersistence(bool $fresh = false): void
    {
        $schema = $this->getSchema();

        if ($fresh && $schema->hasTable(WsChannel::TABLE_NAME)) {
            $schema->drop(WsChannel::TABLE_NAME);
        }

        if ($schema->hasTable(WsChannel::TABLE_NAME)) {
            return;
        }

        $schema->create(WsChannel::TABLE_NAME, function (Blueprint $table) {
            $table->id();
            $table->integer('fd');
            $table->string('channel');
            $table->timestamps();
        });
    }

    public function migrateListenerPersistence(bool $fresh = false): void
    {
        $schema = $this->getSchema();

        if ($fresh && $schema->hasTable(WsListener::TABLE_NAME)) {
            $schema->drop(WsListener::TABLE_NAME);
        }

        if ($schema->hasTable(WsListener::TABLE_NAME)) {
            return;
        }

        $schema->create(WsListener::TABLE_NAME, function (Blueprint $table) {
            $table->id();
            $table->integer('fd');
            $table->string('action');
            $table->timestamps();
        });
    }

    public function migrateAssociationPersistence(bool $fresh = false): void
    {
        $schema = $this->getSchema();

        if ($fresh && $schema->hasTable(WsAssociation::TABLE_NAME)) {
            $schema->drop(WsAssociation::TABLE_NAME);
        }

        if ($schema->hasTable(WsAssociation::TABLE_NAME)) {
            return;
        }

        $schema->create(WsAssociation::TABLE_NAME, function (Blueprint $table) {
            $table->id();
            $table->integer('fd');
            $table->integer('user_id');
            $table->timestamps();
        });
    }

    private function getSchema(): Builder
    {
        $connection = 'socket-conveyor';

        if ($this->isLaravel()) {
            return Schema::connection($connection);
        }

        return $this->getCapsule()->schema($connection);
    }

    private function isLaravel(): bool
    {
        return function_exists('app') && app() instanceof Application;
    }

    /**
     * @throws Exception
     */
    private function validateDatabaseOptions(): void
    {
        if (!isset($this->databaseOptions['driver'])) {
            throw new Exception('Database driver not set!');
        }

        if (!isset($this->databaseOptions['database'])) {
            throw new Exception('Database name not set!');
        }

        if (!isset($this->databaseOptions['username'])) {
            throw new Exception('Database username not set!');
        }

        if (!isset($this->databaseOptions['password'])) {
            throw new Exception('Database password not set!');
        }

        if (!isset($this->databaseOptions['charset'])) {
            throw new Exception('Database charset not set!');
        }

        if (!isset($this->databaseOptions['collation'])) {
            throw new Exception('Database collation not set!');
        }

        if (!isset($this->databaseOptions['prefix'])) {
            throw new Exception('Database prefix not set!');
        }
    }
}

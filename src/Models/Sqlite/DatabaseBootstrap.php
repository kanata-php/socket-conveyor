<?php

namespace Conveyor\Models\Sqlite;

use Exception;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

class DatabaseBootstrap
{
    protected string $databasePath = __DIR__ . '/../../../database/ws.sqlite';

    public function __construct(?string $databasePath = null)
    {
        if (null !== $databasePath) {
            $this->databasePath = $databasePath;
        }

        if (file_exists($this->databasePath)) {
            return;
        }

        if (!touch($this->databasePath)) {
            throw new Exception('Failed to create database file for Conveyor functionalities!');
        }
    }

    private function getCapsule(): Manager
    {
        $capsule = new Manager;
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        return $capsule;
    }

    public function migrateChannelPersistence(bool $fresh = false): void
    {
        $schema = $this->getCapsule()->schema();

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
        $schema = $this->getCapsule()->schema();

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
        $schema = $this->getCapsule()->schema();

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
}

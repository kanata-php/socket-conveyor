<?php

namespace Conveyor\SocketHandlers\Workflow;

use Conveyor\Actions\ActionManager;
use Conveyor\Actions\BaseAction;
use Conveyor\Persistence\Interfaces\ChannelPersistenceInterface;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\Persistence\Interfaces\ListenerPersistenceInterface;
use Conveyor\Persistence\Interfaces\UserAssocPersistenceInterface;
use Exception;
use League\Pipeline\Pipeline;
use OpenSwoole\WebSocket\Server;

class MessageRouter
{
    public string $state;

    // persistence

    public ?ChannelPersistenceInterface $channelPersistence = null;

    public ?ListenerPersistenceInterface $listenerPersistence = null;

    public ?UserAssocPersistenceInterface $userAssocPersistence = null;

    // context

    public ?Server $server = null;

    public ?int $fd = null;

    // actions

    /**
     * This is the incoming message parsed.
     * @var array<array-key, mixed> $data
     */
    public array $data = [];

    public ?ActionManager $actionManager = null;

    public ?Pipeline $pipeline = null;

    // methods

    /**
     * @throws Exception
     */
    public function __construct()
    {
        // @throws Exception
        $this->actionManager = ActionManager::make();
    }

    public function setState(string $state): static
    {
        $this->state = $state;
        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setPersistence(GenericPersistenceInterface $persistence): void
    {
        switch (true) {
            case is_a($persistence, ChannelPersistenceInterface::class):
                $this->channelPersistence = $persistence;
                break;

            case is_a($persistence, ListenerPersistenceInterface::class):
                $this->listenerPersistence = $persistence;
                break;

            case is_a($persistence, UserAssocPersistenceInterface::class):
                $this->userAssocPersistence = $persistence;
                break;
        }
    }

    public function ingestData(string $data): void
    {
        $parsedData = json_decode($data, true);

        if (null === $parsedData) {
            $parsedData = [
                'action' => BaseAction::NAME,
                'data' => $data,
            ];
        }

        $this->data = $parsedData;
    }

    /**
     * This is a health check for connections to channels. Here we remove not necessary connections.
     *
     * @return void
     */
    public function closeConnections(): void
    {
        if (
            !isset($this->server->connections)
            || null === $this->channelPersistence
        ) {
            return;
        }

        $registeredConnections = $this->channelPersistence->getAllConnections();

        $existingConnections = [];
        foreach ($this->server->connections as $connection) {
            if ($this->server->isEstablished($connection)) {
                $existingConnections[] = $connection;
            }
        }

        $closedConnections = array_filter(
            array_keys($registeredConnections),
            fn ($item) => !in_array($item, $existingConnections)
        );

        foreach ($closedConnections as $connection) {
            $this->channelPersistence->disconnect($connection);
        }
    }

    /**
     * @return mixed
     * @throws Exception
     */
    public function processMessage(): mixed
    {
        $action = $this->actionManager->getCurrentAction();

        if (null === $action) {
            throw new Exception('Action not found while processing message!');
        }

        return $action($this->data);
    }
}

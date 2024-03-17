<?php

namespace Conveyor\Workflow;

use Conveyor\Actions\ActionManager;
use Conveyor\Actions\BaseAction;
use Conveyor\Config\ConveyorOptions;
use Conveyor\Exceptions\InvalidActionException;
use Conveyor\Persistence\Interfaces\ChannelPersistenceInterface;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\Persistence\Interfaces\ListenerPersistenceInterface;
use Conveyor\Persistence\Interfaces\MessageAcknowledgmentPersistenceInterface;
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

    public ?MessageAcknowledgmentPersistenceInterface $messageAcknowledgmentPersistence = null;

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
    public function __construct(
        protected ConveyorOptions $options
    ) {
        // @throws Exception
        $this->actionManager = ActionManager::make($options);
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

            case is_a($persistence, MessageAcknowledgmentPersistenceInterface::class):
                $this->messageAcknowledgmentPersistence = $persistence;
                break;
        }
    }

    public function refreshPersistence(): void
    {
        if (method_exists($this->channelPersistence, 'destroyTable')) {
            $this->channelPersistence->destroyTable();
        }

        if (method_exists($this->listenerPersistence, 'destroyTable')) {
            $this->listenerPersistence->destroyTable();
        }

        if (method_exists($this->userAssocPersistence, 'destroyTable')) {
            $this->userAssocPersistence->destroyTable();
        }

        if (method_exists($this->messageAcknowledgmentPersistence, 'destroyTable')) {
            $this->messageAcknowledgmentPersistence->destroyTable();
        }
    }

    /**
     * @param string $data
     * @return void
     * @throws InvalidActionException
     */
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

        $this->actionManager->ingestData(
            data: $this->data,
            server: $this->server,
            fd: $this->fd,
            persistence: array_filter([
                $this->channelPersistence,
                $this->listenerPersistence,
                $this->userAssocPersistence,
                $this->messageAcknowledgmentPersistence,
            ]),
        );
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
            $this->channelPersistence->disconnect((int) $connection);
        }
    }

    public function getCurrentUser(): ?int
    {
        return $this->userAssocPersistence?->getAssoc($this->fd);
    }

    public function preparePipeline(): void
    {
        $this->pipeline = $this->actionManager->getPipeline();
    }

    /**
     * @return mixed
     * @throws Exception
     */
    public function processPipeline(): mixed
    {
        // @throws Exception
        $this->pipeline->process([
            'server' => $this->server,
            'fd' => $this->fd,
            'data' => $this->data,
            'user' => $this->getCurrentUser(),
        ]);

        // @throws Exception
        return $this->processMessage();
    }

    /**
     * @return mixed
     * @throws Exception
     */
    protected function processMessage(): mixed
    {
        $action = $this->actionManager->getCurrentAction();

        if (null === $action) {
            throw new Exception('Action not found while processing message!');
        }

        return $action($this->data);
    }
}

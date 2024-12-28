<?php

namespace Conveyor\SubProtocols\Conveyor\Actions\Abstractions;

use Conveyor\Config\ConveyorOptions;
use Conveyor\SubProtocols\Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\SubProtocols\Conveyor\Actions\Traits\HasAcknowledgment;
use Conveyor\SubProtocols\Conveyor\Actions\Traits\HasPersistence;
use Conveyor\SubProtocols\Conveyor\Broadcast;
use Exception;
use InvalidArgumentException;
use OpenSwoole\WebSocket\Server;

abstract class AbstractAction implements ActionInterface
{
    use HasPersistence;
    use HasAcknowledgment;

    protected ConveyorOptions $conveyorOptions;

    protected string $name;

    /**
     * @var array <array-key, mixed>
     */
    protected array $data;

    /** @var int Origin Fd */
    protected int $fd;

    protected Server $server;
    protected ?string $channel = null;
    protected bool $fresh = false;

    /**
     * @param array<array-key, mixed> $data
     * @return mixed
     * @throws Exception|InvalidArgumentException
     */
    public function __invoke(array $data): mixed
    {
        /** @throws InvalidArgumentException */
        $this->baseValidator($data);

        /** @throws InvalidArgumentException */
        $this->validateData($data);

        $this->acknowledgeMessage($data);

        return $this->execute($data);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return void
     */
    private function baseValidator(array $data): void
    {
        if (!isset($data['action'])) {
            throw new InvalidArgumentException('Actions required \'action\' field to be created!');
        }
    }

    public function setServer(mixed $server): void
    {
        $this->server = $server;
    }

    public function setFresh(bool $fresh): void
    {
        $this->fresh = $fresh;
    }

    /**
     * @return Server
     */
    public function getServer(): Server
    {
        return $this->server;
    }

    public function setFd(int $fd): void
    {
        $this->fd = $fd;
    }

    public function setConveyorOptions(ConveyorOptions $conveyorOptions): void
    {
        $this->conveyorOptions = $conveyorOptions;
    }

    /**
     * @return int
     */
    public function getFd(): int
    {
        return $this->fd;
    }

    public function getCurrentChannel(): ?string
    {
        foreach ($this->channelPersistence->getAllConnections() as $fd => $channel) {
            if ($fd === $this->fd) {
                return $channel;
            }
        }

        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param mixed $data
     * @param int|null $fd Destination Fd
     * @param bool $toChannel
     * @return void
     * @throws Exception
     */
    public function send(
        mixed $data,
        ?int $fd = null,
        bool $toChannel = false
    ): void {
        $data = json_encode([
            'action' => $this->getName(),
            'data' => $data,
            'fd' => $this->getFd(),
        ]);


        if (null !== $fd) {
            Broadcast::push(
                fd: $fd,
                data: $data,
                server: $this->server,
                ackPersistence: $this->messageAcknowledgementPersistence,
            );
            return;
        }

        if ($toChannel && null !== $this->channelPersistence) {
            $this->broadcast($data);
            return;
        }

        if (!$toChannel && null === $fd) {
            $this->fanout($data);
            return;
        }

        if (!$toChannel) {
            Broadcast::push(
                fd: $this->getFd(),
                data: $data,
                server: $this->server,
                ackPersistence: $this->messageAcknowledgementPersistence,
            );
        }
    }

    /**
     * Broadcast outside of channels.
     *
     * @param string $data
     * @return void
     */
    protected function fanout(string $data): void
    {
        foreach ($this->server->connections as $fd) {
            if (!$this->server->isEstablished($fd)) {
                continue;
            }

            Broadcast::push(
                fd: $fd,
                data: $data,
                server: $this->server,
                ackPersistence: $this->messageAcknowledgementPersistence,
            );
        }
    }

    /**
     * Broadcast.
     *
     * @param string $data
     * @return void
     */
    protected function broadcast(string $data): void
    {
        // Only broadcast to channel when connected to one...
        if (null !== $this->getCurrentChannel()) {
            Broadcast::broadcastToChannel(
                data: $data,
                channel: $this->getCurrentChannel(),
                currentFd: $this->getFd(),
                server: $this->server,
                channelPersistence: $this->channelPersistence,
                ackPersistence: $this->messageAcknowledgementPersistence,
            );
            return;
        }

        // ...otherwise, broadcast to anybody outside channels.
        Broadcast::broadcastWithoutChannel(
            data: $data,
            currentFd: $this->getFd(),
            server: $this->server,
            channelPersistence: $this->channelPersistence,
            ackPersistence: $this->messageAcknowledgementPersistence,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return mixed
     *
     * @throws Exception
     */
    abstract public function validateData(array $data): mixed;

    /**
     * Execute action.
     *
     * @param array<array-key, mixed> $data
     * @return mixed
     */
    abstract public function execute(array $data): mixed;
}

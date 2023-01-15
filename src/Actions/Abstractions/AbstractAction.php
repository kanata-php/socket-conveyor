<?php

namespace Conveyor\Actions\Abstractions;

use Conveyor\Actions\Traits\HasListener;
use Conveyor\Actions\Traits\HasPersistence;
use Exception;
use Conveyor\Actions\Interfaces\ActionInterface;
use InvalidArgumentException;

abstract class AbstractAction implements ActionInterface
{
    use HasPersistence, HasListener;

    protected array $data;

    /** @var int Origin Fd */
    protected int $fd;

    protected mixed $server = null;
    protected ?string $channel = null;
    protected array $listeners = [];

    protected bool $fresh = false;

    /**
     * @param array $data
     * @return mixed
     * @throws Exception|InvalidArgumentException
     */
    public function __invoke(array $data): mixed
    {
        /** @throws InvalidArgumentException */
        $this->baseValidator($data);

        /** @throws InvalidArgumentException */
        $this->validateData($data);

        return $this->execute($data);
    }

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
     * @return mixed
     */
    public function getServer()
    {
        return $this->server;
    }

    public function setFd(int $fd): void
    {
        $this->fd = $fd;
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

    public function getName() : string
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
        if (!method_exists($this->server, 'push')) {
            throw new Exception('Current Server instance doesn\'t have "send" method.');
        }

        $data = json_encode([
            'action' => $this->getName(),
            'data' => $data,
            'fd' => $this->getFd(), // origin fd
        ]);

        if (null !== $fd) {
            $this->push($fd, $data);
            return;
        }

        /** @var ?array $listeners */
        $listeners = $this->getListeners();

        if ($toChannel && null !== $this->channelPersistence) {
            $this->broadcastToChannel($data, $listeners);
            return;
        }

        if (!$toChannel && null === $fd) {
            $this->fanout($data, $listeners);
            return;
        }

        if (!$toChannel) {
            $this->push($this->getFd(), $data);
        }
    }

    /**
     * Broadcast outside of channels.
     *
     * @param string $data
     * @param array|null $listeners
     * @return void
     */
    protected function fanout(string $data, ?array $listeners = null)
    {
        foreach ($this->server->connections as $fd) {
            $isOnlyListeningOtherActions = null === $listeners
                && $this->isListeningAnyAction($fd);
            $isNotListeningThisAction = null !== $listeners
                && !in_array($fd, $listeners);

            if (
                !$this->server->isEstablished($fd)
                || (
                    // if listening any action, let's analyze
                    $this->isListeningAnyAction($fd)
                    && ($isNotListeningThisAction
                        || $isOnlyListeningOtherActions)
                )
            ) {
                continue;
            }

            $this->push($fd, $data);
        }
    }

    /**
     * Broadcast when messaging to channel.
     *
     * @param string $data
     * @param array|null $listeners
     * @return void
     */
    protected function broadcastToChannel(string $data, ?array $listeners = null): void
    {
        $connections = array_filter(
            $this->channelPersistence->getAllConnections(),
            fn($c) => $c === $this->getCurrentChannel()
        );

        foreach ($connections as $fd => $channel) {
            $isOnlyListeningOtherActions = null === $listeners
                && $this->isListeningAnyAction($fd);
            $isNotListeningThisAction = null !== $listeners
                && !in_array($fd, $listeners);

            if (
                !$this->server->isEstablished($fd)
                || $fd === $this->getFd()
                || (
                    // if listening any action, let's analyze
                    $this->isListeningAnyAction($fd)
                    && ($isNotListeningThisAction
                        || $isOnlyListeningOtherActions)
                )
            ) {
                continue;
            }

            $this->push($fd, $data);
        }
    }

    public function push(int $fd, string $data)
    {
        $this->server->push($fd, $data);
    }

    /**
     * @param array $data
     * @return void
     *
     * @throws Exception
     */
    abstract public function validateData(array $data) : void;

    /**
     * Execute action.
     *
     * @param array $data
     * @param int $fd
     * @param mixed $server
     * @return mixed
     */
    abstract public function execute(array $data): mixed;
}

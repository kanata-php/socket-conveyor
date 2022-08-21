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
     * @param string $data
     * @param int|null $fd Destination Fd
     * @param bool $toChannel
     * @return void
     * @throws Exception
     */
    public function send(
        string $data,
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
            $this->server->push($fd, $data);
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
            $this->server->push($this->getFd(), $data);
        }
    }

    /**
     * Broadcast outside of channels.
     *
     * @param string $data
     * @param array|null $listeners
     * @return void
     */
    private function fanout(string $data, ?array $listeners = null)
    {
        foreach ($this->server->connections as $fd) {
            $isOnlyListeningOtherActions = null === $listeners
                && $this->isListeningAnyAction($fd);
            $isNotListeningThisAction = null !== $listeners
                && !in_array($fd, $listeners);

            if (
                !$this->server->isEstablished($fd)
                || $isNotListeningThisAction
                || $fd === $this->getFd()
                || $isOnlyListeningOtherActions
            ) {
                continue;
            }

            $this->server->push($fd, $data);
        }
    }

    /**
     * Broadcast when messaging to channel.
     *
     * @param string $data
     * @param array|null $listeners
     * @return void
     */
    private function broadcastToChannel(string $data, ?array $listeners = null): void
    {
        $connections = array_filter(
            $this->channelPersistence->getAllConnections(),
            fn($c) => $c === $this->getCurrentChannel()
        );

        foreach ($connections as $fd => $channel) {
            $isNotListeningThisAction = null !== $listeners
                && !in_array($fd, $listeners);

            if ($fd === $this->fd || $isNotListeningThisAction) {
                continue;
            }

            $this->server->push($fd, $data);
        }
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

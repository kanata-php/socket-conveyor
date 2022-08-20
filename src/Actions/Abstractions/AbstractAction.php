<?php

namespace Conveyor\Actions\Abstractions;

use Conveyor\Actions\Traits\HasPersistence;
use Exception;
use Conveyor\Actions\Interfaces\ActionInterface;

abstract class AbstractAction implements ActionInterface
{
    use HasPersistence;

    protected array $data;
    protected int $fd;
    protected mixed $server = null;
    protected ?string $channel = null;
    protected array $listeners = [];

    /**
     * @param array $data
     * @return mixed
     * @throws Exception
     */
    public function __invoke(array $data): mixed
    {
        $this->validateData($data);
        return $this->execute($data);
    }

    public function setServer(mixed $server): void
    {
        $this->server = $server;
    }

    public function setFd(int $fd): void
    {
        $this->fd = $fd;
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
     * @param int|null $fd
     * @param bool $toChannel
     * @return void
     * @throws Exception
     */
    public function send(string $data, ?int $fd = null, bool $toChannel = false): void
    {
        if (!method_exists($this->server, 'push')) {
            throw new Exception('Current Server instance doesn\'t have "send" method.');
        }

        $data = json_encode([
            'action' => $this->getName(),
            'data' => $data,
        ]);

        if (null !== $fd) {
            $this->server->push($fd, $data);
            return;
        }

        // listeners
        $listeners = $this->getListeners();

        if ($toChannel && null !== $this->channelPersistence) {
            $this->broadcastToChannel($data, $listeners);
            return;
        }

        if (!$toChannel && null !== $listeners) {
            $this->fanout($data, $listeners);
            return;
        }

        if (!$toChannel) {
            $this->server->push($this->fd, $data);
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
        $counter = 0;
        foreach ($this->server->connections as $fd) {
            if (
                !$this->server->isEstablished($fd)
                || (null !== $listeners && !in_array($fd, $listeners))
            ) {
                continue;
            }
            $this->server->push($fd, $data);
        }
    }

    /**
     * Get listeners for the current listener persistence.
     *
     * @return array|null
     */
    private function getListeners(): ?array
    {
        if (null !== $this->listenerPersistence) {
            $listeners = [];
            foreach ($this->listenerPersistence->getAllListeners() as $fd => $listened) {
                if ($fd === $this->fd || !in_array($this->getName(), $listened)) {
                    continue;
                }
                $listeners[] = $fd;
            }
            return $listeners;
        }

        return null;
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
            if (
                $fd === $this->fd
                // if listening and not to this channel
                || (null !== $listeners && !in_array($fd, $listeners))
            ) {
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

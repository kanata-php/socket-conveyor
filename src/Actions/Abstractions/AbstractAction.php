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

        $received = []; // this is meant to prevent duplicated messages to be sent

        $data = json_encode([
            'action' => $this->getName(),
            'data' => $data,
        ]);

        if (null !== $fd) {
            $this->server->push($fd, $data);
            return;
        }

        // listeners
        if (null !== $this->listenerPersistence) {
            foreach ($this->listenerPersistence->getAllListeners() as $fd => $listened) {
                if ($fd === $this->fd || !in_array($this->getName(), $listened)) {
                    continue;
                }
                $this->server->push($fd, $data);
                $received[] = $fd;
            }
        }

        if ($toChannel && null !== $this->channelPersistence) {
            $connections = $this->channelPersistence->getAllConnections();
            $currentChannel = isset($connections[$this->fd]) ? $connections[$this->fd] : null;
            foreach ($connections as $fd => $channel) {
                if (
                    $fd === $this->fd
                    || in_array($fd, $received)
                    || $channel !== $currentChannel
                ) {
                    continue;
                }
                $this->server->push($fd, $data);
                $received[] = $fd;
            }
        }

        if (!$toChannel && !in_array($this->fd, $received)) {
            $this->server->push($this->fd, $data);
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

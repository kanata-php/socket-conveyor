<?php

namespace Conveyor\Actions\Abstractions;

use Exception;
use Conveyor\Actions\Interfaces\ActionInterface;

abstract class AbstractAction implements ActionInterface
{
    protected array $data;
    protected int $fd;
    protected mixed $server = null;
    protected ?string $channel = null;
    protected ?array $channels = null;
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

    public function setChannels(array $channels): void
    {
        $this->channels = $channels;
    }

    public function getCurrentChannel(): ?string
    {
        foreach ($this->channels as $fd => $channel) {
            if ($fd === $this->fd) {
                return $channel;
            }
        }

        return null;
    }

    public function setListeners(array $listeners): void
    {
        $this->listeners = array_map(fn($item) => array_filter($item), $listeners);
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

        if ($toChannel && null !== $this->channels) {
            foreach (array_keys($this->channels) as $fd) {
                if (
                    $fd !== $this->fd
                    && $this->isFdListeningAction($fd)
                ) {
                    $this->server->push($fd, $data);
                }
            }
            return;
        }

        $this->server->push($this->fd, $data);
    }

    protected function isFdListeningAction(int $fd): bool
    {
        return !isset($this->listeners[$fd])
            || count($this->listeners[$fd]) === 0
            || in_array($this->getName(), $this->listeners[$fd]);
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

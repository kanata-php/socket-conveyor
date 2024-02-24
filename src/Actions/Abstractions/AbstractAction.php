<?php

namespace Conveyor\Actions\Abstractions;

use Conveyor\Actions\Traits\HasAcknowledgment;
use Conveyor\Actions\Traits\HasChannel;
use Conveyor\Actions\Traits\HasListener;
use Conveyor\Actions\Traits\HasPersistence;
use Conveyor\Config\ConveyorOptions;
use Conveyor\Constants;
use Exception;
use Conveyor\Actions\Interfaces\ActionInterface;
use Hook\Filter;
use InvalidArgumentException;
use OpenSwoole\WebSocket\Server;
use OpenSwoole\Coroutine as Co;

abstract class AbstractAction implements ActionInterface
{
    use HasPersistence;
    use HasListener;
    use HasChannel;
    use HasAcknowledgment;

    protected string $name;

    /**
     * @var array <array-key, mixed>
     */
    protected array $data;

    protected ConveyorOptions $conveyorOptions;

    /** @var int Origin Fd */
    protected int $fd;

    protected mixed $server = null;
    protected ?string $channel = null;

    /**
     * @var array <array-key, int>
     */
    protected array $listeners = [];

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

        /** @var ?array<array-key, int> $listeners */
        $listeners = $this->getListeners();

        if ($toChannel && null !== $this->channelPersistence) {
            $this->broadcast($data, $listeners);
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
     * @param array<array-key, mixed>|null $listeners
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
     * Broadcast.
     *
     * @param string $data
     * @param array<array-key, int>|null $listeners
     * @return void
     */
    protected function broadcast(string $data, ?array $listeners = null): void
    {
        // Only broadcast to channel when connected to one...
        if (null !== $this->getCurrentChannel()) {
            $this->broadcastToChannel($data, $listeners);
            return;
        }

        // ...otherwise, broadcast to anybody outside channels.
        $this->broadcastWithoutChannel($data, $listeners);
    }

    /**
     * Broadcast when messaging to channel.
     *
     * @param string $data
     * @param array<array-key, int>|null $listeners
     * @param bool $includeSelf
     * @return void
     */
    protected function broadcastToChannel(
        string $data,
        ?array $listeners = null,
        bool $includeSelf = false,
    ): void {
        $connections = array_filter(
            $this->channelPersistence->getAllConnections(),
            fn($c) => $c === $this->getCurrentChannel()
        );

        Co::run(function () use ($connections, $listeners, $includeSelf, $data) {
            foreach ($connections as $fd => $channel) {
                $isOnlyListeningOtherActions = null === $listeners
                    && $this->isListeningAnyAction($fd);
                $isNotListeningThisAction = null !== $listeners
                    && !in_array($fd, $listeners);

                if (
                    !$this->server->isEstablished($fd)
                    || (!$includeSelf && $fd === $this->getFd())
                    || (
                        // if listening any action, let's analyze
                        $this->isListeningAnyAction($fd)
                        && ($isNotListeningThisAction || $isOnlyListeningOtherActions)
                    )
                ) {
                    continue;
                }

                go(function () use ($data, $fd) {
                    $this->push($fd, $data);
                });
            }
        });
    }

    /**
     * Broadcast when broadcasting without channel.
     *
     * @param string $data
     * @param array<array-key, int>|null $listeners
     * @return void
     */
    protected function broadcastWithoutChannel(
        string $data,
        ?array $listeners = null,
        bool $includeSelf = false,
    ): void {
        Co::run(function () use ($listeners, $includeSelf, $data) {
            foreach ($this->server->connections as $fd) {
                $isOnlyListeningOtherActions = null === $listeners
                    && $this->isListeningAnyAction($fd);
                $isNotListeningThisAction = null !== $listeners
                    && !in_array($fd, $listeners);
                $isConnectedToAnyChannel = $this->isConnectedToAnyChannel($fd);

                if (
                    !$this->server->isEstablished($fd)
                    || (!$includeSelf && $fd === $this->getFd())
                    || $isConnectedToAnyChannel
                    || (
                        // if listening any action, let's analyze
                        $this->isListeningAnyAction($fd)
                        && ($isNotListeningThisAction
                            || $isOnlyListeningOtherActions)
                    )
                ) {
                    continue;
                }

                go(function () use ($data, $fd) {
                    $this->push($fd, $data);
                });
            }
        });
    }

    public function push(int $fd, string $data): void
    {
        /**
         * Description: Filter the message before sending it to the client.
         * Filter: Constants::FILTER_ACTION_PUSH_MESSAGE
         * Expected value: <string>
         * Parameters:
         *   string $data
         *   int $fd
         *   Server $server
         */
        $data = Filter::applyFilters(
            Constants::FILTER_ACTION_PUSH_MESSAGE,
            $data,
            $fd,
            $this->server,
        );

        $this->server->push($fd, $data);

        $this->checkAcknowledgment($fd, $data);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return void
     *
     * @throws Exception
     */
    abstract public function validateData(array $data): void;

    /**
     * Execute action.
     *
     * @param array<array-key, mixed> $data
     * @return mixed
     */
    abstract public function execute(array $data): mixed;
}

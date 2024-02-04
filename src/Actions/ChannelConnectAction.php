<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

class ChannelConnectAction extends AbstractAction
{
    public const NAME = 'channel-connect';

    protected string $name = self::NAME;

    /**
     * @param array<array-key, mixed> $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->validateData($data);

        $channel = $data['channel'];

        if (null !== $this->channelPersistence) {
            $this->channelPersistence->connect($this->fd, $channel);
        }

        if ($this->conveyorOptions->usePresence) {
            $this->broadcastPresence();
        }

        return null;
    }

    public function validateData(array $data): void
    {
        if (!isset($data['channel'])) {
            throw new InvalidArgumentException('Channel connection must specify "channel"!');
        }
    }

    public function broadcastPresence(): void
    {
        $fds = array_keys(array_filter(
            $this->channelPersistence?->getAllConnections() ?? [],
            fn($c) => $c === $this->getCurrentChannel(),
        ));

        $userIds = array_filter(
            $this->userAssocPersistence?->getAllAssocs() ?? [],
            fn($fd) => in_array($fd, $fds),
            ARRAY_FILTER_USE_KEY,
        );

        $data = json_encode([
            'action' => self::NAME,
            'data' => json_encode([
                'fd' => $this->fd,
                'event' => 'channel-presence',
                'channel' => $this->getCurrentChannel(),
                'fds' => $fds,
                'userIds' => $userIds,
            ]),
        ]);

        $this->broadcastToChannel($data);
        $this->server->push($this->fd, $data);
    }
}

<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Conveyor\Constants;
use Exception;
use Hook\Filter;
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

        // @phpstan-ignore-next-line
        if ($this->conveyorOptions->{Constants::USE_PRESENCE}) {
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

        $data = Filter::applyFilters(
            tag: Constants::FILTER_PRESENCE_MESSAGE_CONNECT,
            value: [
                'action' => self::NAME,
                'data' => json_encode([
                    'fd' => $this->fd,
                    'event' => Constants::ACTION_EVENT_CHANNEL_PRESENCE,
                    'channel' => $this->getCurrentChannel(),
                    'fds' => $fds,
                    'userIds' => $userIds,
                ]),
            ],
        );

        $message = json_encode($data);

        $this->broadcastToChannel(data: $message, includeSelf: true);
    }
}

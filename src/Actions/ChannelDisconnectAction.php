<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Conveyor\Constants;
use Exception;
use Hook\Filter;

class ChannelDisconnectAction extends AbstractAction
{
    public const NAME = 'channel-disconnect';

    protected string $name = self::NAME;

    public function validateData(array $data): void
    {
        return;
    }

    /**
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        // @throws Exception
        $this->validateData($data);

        if (null !== $this->channelPersistence) {
            $this->channelPersistence->disconnect($this->fd);
        }

        // @phpstan-ignore-next-line
        if ($this->conveyorOptions->{Constants::USE_PRESENCE}) {
            $this->broadcastPresence();
        }

        return null;
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
            tag: Constants::FILTER_PRESENCE_MESSAGE_DISCONNECT,
            value: [
                'action' => self::NAME,
                'data' => json_encode([
                    'fd' => $this->fd,
                    'event' => 'channel-presence',
                    'channel' => $this->getCurrentChannel(),
                    'fds' => $fds,
                    'userIds' => $userIds,
                ]),
            ],
        );

        $message = json_encode($data);

        $this->broadcastToChannel($message);
        $this->server->push($this->fd, $message);
    }
}

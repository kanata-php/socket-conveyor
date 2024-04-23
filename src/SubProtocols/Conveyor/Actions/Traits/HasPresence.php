<?php

namespace Conveyor\SubProtocols\Conveyor\Actions\Traits;

use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Broadcast;
use Hook\Filter;

// TODO: move this to a separate component
trait HasPresence
{
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

        Broadcast::broadcastToChannel(
            data: json_encode($data),
            channel: $this->getCurrentChannel(),
            currentFd: $this->getFd(),
            server: $this->server,
            channelPersistence: $this->channelPersistence,
            ackPersistence: $this->messageAcknowledmentPersistence,
        );
    }
}

<?php

namespace Conveyor\SubProtocols\Conveyor;

use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\ChannelPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\MessageAcknowledgementPersistenceInterface;
use Hook\Action;
use Hook\Filter;
use OpenSwoole\WebSocket\Server;

class Broadcast
{
    /**
     * Broadcast when messaging to channel.
     *
     * @param string $data
     * @param string $channel
     * @param int $currentFd
     * @param bool $includeSelf
     * @return void
     */
    public static function broadcastToChannel(
        string $data,
        string $channel,
        int $currentFd,
        Server $server,
        ChannelPersistenceInterface $channelPersistence,
        ?MessageAcknowledgementPersistenceInterface $ackPersistence,
        bool $includeSelf = true,
    ): void {
        $connections = array_filter(
            $channelPersistence->getAllConnections(),
            fn($c) => $c === $channel
        );

        foreach ($connections as $fd => $channel) {
            if (
                !$server->isEstablished($fd)
                || (!$includeSelf && $fd === $currentFd)
            ) {
                continue;
            }

            self::push(
                fd: $fd,
                data: $data,
                server: $server,
                ackPersistence: $ackPersistence,
            );
        }
    }

    /**
     * Broadcast when broadcasting without channel.
     *
     * @param string $data
     * @param bool $includeSelf
     * @param Server $server
     * @param bool $includeSelf
     * @return void
     */
    public static function broadcastWithoutChannel(
        string $data,
        int $currentFd,
        Server $server,
        ChannelPersistenceInterface $channelPersistence,
        ?MessageAcknowledgementPersistenceInterface $ackPersistence,
        bool $includeSelf = true,
    ): void {
        foreach ($server->connections as $fd) {
            $isConnectedToAnyChannel = in_array(
                $fd,
                array_keys($channelPersistence->getAllConnections()),
            );

            if (
                !$server->isEstablished($fd)
                || (!$includeSelf && $fd === $currentFd)
                || $isConnectedToAnyChannel
            ) {
                continue;
            }

            self::push(
                fd: $fd,
                data: $data,
                server: $server,
                ackPersistence: $ackPersistence,
            );
        }
    }

    /**
     * Push message to client.
     *
     * @param int $fd
     * @param string $data
     * @param Server $server
     * @return void
     */
    public static function push(
        int $fd,
        string $data,
        Server $server,
        ?MessageAcknowledgementPersistenceInterface $ackPersistence,
    ): void {
        /**
         * Description: Filter the message before sending it to the client.
         * Filter: Constants::FILTER_PUSH_MESSAGE
         * Expected value: <string>
         * Parameters:
         *   string $data
         *   int $fd
         *   Server $server
         */
        $data = Filter::applyFilters(
            Constants::FILTER_PUSH_MESSAGE,
            $data,
            $fd,
            $server,
        );

        $connected = $server->isEstablished($fd);

        if ($connected) {
            $server->push($fd, $data);
        }

        /**
         * Description: Filter the message after sending it to the client.
         * Action: Constants::ACTION_AFTER_PUSH_MESSAGE
         * Expected value: <string>
         * Parameters:
         *   string $data
         *   int $fd
         *   Server $server
         */
        Action::doAction(
            Constants::ACTION_AFTER_PUSH_MESSAGE,
            $fd,
            $data,
            $server,
            $ackPersistence,
        );
    }
}

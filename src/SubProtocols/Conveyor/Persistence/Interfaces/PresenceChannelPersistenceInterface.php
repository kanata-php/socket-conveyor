<?php

namespace Conveyor\SubProtocols\Conveyor\Persistence\Interfaces;

interface PresenceChannelPersistenceInterface extends GenericPersistenceInterface
{
    /**
     * Record a presence member on a channel.
     *
     * @param int $fd
     * @param string $channel
     * @param string $channelData Raw JSON string: {"user_id":..,"user_info":..}
     * @return void
     */
    public function add(int $fd, string $channel, string $channelData): void;

    /**
     * Remove a single presence membership.
     *
     * @param int $fd
     * @param string $channel
     * @return void
     */
    public function remove(int $fd, string $channel): void;

    /**
     * Remove every presence membership held by an fd (used on disconnect).
     *
     * @param int $fd
     * @return array<array-key, array{0: string, 1: string}> Removed [channel, channelData] pairs.
     */
    public function removeConnection(int $fd): array;

    /**
     * Get the roster for a channel.
     *
     * @param string $channel
     * @return array<array-key, string> Format: [fd => channelData]
     */
    public function getMembers(string $channel): array;
}

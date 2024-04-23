<?php

namespace Conveyor\SubProtocols\Conveyor\Persistence\Interfaces;

interface ChannelPersistenceInterface extends GenericPersistenceInterface
{
    /**
     * Connect a fd to a channel.
     *
     * @param int $fd
     * @param string $channel
     * @return void
     */
    public function connect(int $fd, string $channel): void;

    /**
     * Disconnect a fd from a channel.
     *
     * @param int $fd
     * @return void
     */
    public function disconnect(int $fd): void;

    /**
     * Get all fd-channel associations.
     *
     * @return array<array-key, string> Format: [fd => channel][]
     */
    public function getAllConnections(): array;

    /**
     * Get a channel for the given $fd.
     * @param int $fd
     * @return ?string
     */
    public function getChannel(int $fd): ?string;
}

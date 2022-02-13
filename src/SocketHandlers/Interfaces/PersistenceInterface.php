<?php

namespace Conveyor\SocketHandlers\Interfaces;

interface PersistenceInterface
{
    // -----------------------------------------------------
    // Channels
    // -----------------------------------------------------

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
     * @return array Format: [fd => channel-name, ...]
     */
    public function getAllConnections(): array;

    // -----------------------------------------------------
    // Listeners
    // -----------------------------------------------------

    /**
     * Add a listener for an fd.
     *
     * @param int $fd
     * @param string $action
     * @return void
     */
    public function listen(int $fd, string $action): void;

    /**
     * Get the listener for a specific fd.
     *
     * @param int $fd
     * @return array
     */
    public function getListener(int $fd): array;

    /**
     * Retrieve a list of all fds with its listened actions.
     *
     * @return array Format: [fd => [listener1, listener2, ...]]
     */
    public function getAllListeners(): array;

    // -----------------------------------------------------
    // User Associations
    // -----------------------------------------------------

    /**
     * Associate a user id to a fd.
     *
     * @param int $fd
     * @param int $userId
     * @return void
     */
    public function assoc(int $fd, int $userId): void;

    /**
     * Disassociate a user from a fd.
     *
     * @param int $fd
     * @return void
     */
    public function disassoc(int $fd): void;

    /**
     * Get user-id for a fd.
     *
     * @param int $fd
     * @return int
     */
    public function getAssoc(int $fd): int;

    /**
     * Retrieve all associations.
     *
     * @return array Format:
     */
    public function getAllAssocs(): array;
}
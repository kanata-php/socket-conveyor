<?php

namespace Conveyor\SocketHandlers\Interfaces;

interface ListenerPersistenceInterface extends GenericPersistenceInterface
{
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
     * @return ?array
     */
    public function getListener(int $fd): ?array;

    /**
     * Retrieve a list of all fds with its listened actions.
     *
     * @return array Format: [fd => [listener1, listener2, ...]]
     */
    public function getAllListeners(): array;

    /**
     * Stop listener.
     *
     * @param int $fd
     * @param string $action
     * @return bool
     */
    public function stopListener(int $fd, string $action): bool;

    /**
     * Stop listeners for fd.
     *
     * @param int $fd
     * @return bool
     */
    public function stopListenersForFd(int $fd): bool;

    /**
     * Truncate the data storage.
     *
     * @return void
     */
    public function refresh(): void;
}

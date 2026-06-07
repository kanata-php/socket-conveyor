<?php

namespace Conveyor\SubProtocols\Pusher;

use OpenSwoole\Table;

class SocketIdRepository
{
    public const MAX_TABLE_SIZE = 50000;

    /**
     * Forward mapping, keyed by fd: [fd => socket_id].
     */
    protected Table $forward;

    /**
     * Reverse mapping, keyed by socket_id: [socket_id => fd].
     */
    protected Table $reverse;

    /**
     * Connection -> app binding, keyed by fd: [fd => app_key].
     *
     * A Pusher connection belongs to exactly one app, decided at handshake from
     * the `/app/{key}` path. The message/close handlers run in task workers that
     * only receive the fd, so the binding is kept in shared memory to recover the
     * owning app (its secret / client-event policy) away from the handshake.
     */
    protected Table $apps;

    public function __construct()
    {
        $this->createTables();
    }

    /**
     * Bind a connection to the app it handshook against.
     */
    public function bindApp(int $fd, string $appKey): void
    {
        $this->apps->set((string) $fd, ['app_key' => $appKey]);
    }

    public function appKeyFor(int $fd): ?string
    {
        $result = $this->apps->get((string) $fd, 'app_key');

        if ($result === false) {
            return null;
        }

        return $result;
    }

    /**
     * Create (idempotently) and return the socket_id for an fd.
     *
     * The socket_id is derived deterministically from the fd so it is stable for
     * the life of the connection; both directions are stored so lookups are O(1).
     */
    public function register(int $fd): string
    {
        $existing = $this->socketIdFor($fd);
        if ($existing !== null) {
            return $existing;
        }

        $socketId = sprintf('%d.%d', $fd, 1000000 + $fd);

        $this->forward->set((string) $fd, ['socket_id' => $socketId]);
        $this->reverse->set($socketId, ['fd' => $fd]);

        return $socketId;
    }

    public function socketIdFor(int $fd): ?string
    {
        $result = $this->forward->get((string) $fd, 'socket_id');

        if ($result === false) {
            return null;
        }

        return $result;
    }

    public function fdFor(string $socketId): ?int
    {
        $result = $this->reverse->get($socketId, 'fd');

        if ($result === false) {
            return null;
        }

        return $result;
    }

    public function forget(int $fd): void
    {
        $socketId = $this->socketIdFor($fd);

        $this->forward->del((string) $fd);
        $this->apps->del((string) $fd);

        if ($socketId !== null) {
            $this->reverse->del($socketId);
        }
    }

    private function createTables(): void
    {
        $this->forward = new Table(self::MAX_TABLE_SIZE);
        $this->forward->column('socket_id', Table::TYPE_STRING, 32);
        $this->forward->create();

        $this->reverse = new Table(self::MAX_TABLE_SIZE);
        $this->reverse->column('fd', Table::TYPE_INT, 8);
        $this->reverse->create();

        $this->apps = new Table(self::MAX_TABLE_SIZE);
        $this->apps->column('app_key', Table::TYPE_STRING, 64);
        $this->apps->create();
    }
}

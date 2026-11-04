<?php

namespace Conveyor\SubProtocols\Pusher;

use OpenSwoole\Table;

class SocketIdRepository
{
    public const MAX_TABLE_SIZE = 50000;
    protected Table $forward;
    protected Table $reverse;
    private PusherMutex $lock;
    private PusherLimits $limits;

    public function __construct(?PusherLimits $limits = null)
    {
        PusherDiagnostics::initialize();
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('Pusher requires 64-bit PHP');
        }
        $this->limits = $limits ?? new PusherLimits();
        $this->lock = new PusherMutex();
        $this->forward = new Table(self::MAX_TABLE_SIZE);
        $this->forward->column('socket_id', Table::TYPE_STRING, 40);
        $this->forward->column('app_key', Table::TYPE_STRING, 64);
        $this->forward->create();
        $this->reverse = new Table(self::MAX_TABLE_SIZE);
        $this->reverse->column('fd', Table::TYPE_INT, 8);
        $this->reverse->create();
    }

    /** Legacy binding API; production uses atomic register(fd, appKey). */
    public function bindApp(int $fd, string $appKey): void
    {
        $this->synchronized(function () use ($fd, $appKey): void {
            $row = $this->forward->get((string) $fd);
            if (
                $row === false || strlen($appKey) > 64 || $appKey === ''
                || ($row['app_key'] !== '' && $row['app_key'] !== $appKey)
            ) {
                throw new \RuntimeException('Invalid app binding');
            }
            $this->checkCapacity($appKey, $fd);
            if (!$this->forward->set((string) $fd, ['app_key' => $appKey])) {
                throw new \RuntimeException('Connection storage unavailable');
            }
        });
    }

    public function appKeyFor(int $fd): ?string
    {
        $key = $this->forward->get((string) $fd, 'app_key');
        return $key === false || $key === '' ? null : $key;
    }

    public function register(int $fd, ?string $appKey = null): string
    {
        return $this->synchronized(function () use ($fd, $appKey): string {
            // A killed writer may have reserved reverse storage before committing its forward row.
            foreach ($this->reverse as $candidate => $row) {
                if ($this->socketIdFor($row['fd']) !== $candidate) {
                    $this->reverse->del($candidate);
                }
            }
            $existing = $this->socketIdFor($fd);
            if ($existing !== null) {
                if ($appKey !== null && $this->appKeyFor($fd) !== $appKey) {
                    throw new \RuntimeException('Connection already bound');
                }
                return $existing;
            }
            if ($appKey !== null && ($appKey === '' || strlen($appKey) > 64)) {
                throw new \InvalidArgumentException('Invalid app binding');
            }
            $this->checkCapacity($appKey ?? '', $fd);
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $id = random_int(1, PHP_INT_MAX) . '.' . random_int(1, PHP_INT_MAX);
                if ($this->reverse->exists($id)) {
                    continue;
                }
                try {
                    if (
                        !$this->reverse->set($id, ['fd' => $fd])
                        || !$this->forward->set((string) $fd, ['socket_id' => $id, 'app_key' => $appKey ?? ''])
                        || $this->socketIdFor($fd) !== $id || $this->fdFor($id) !== $fd
                    ) {
                        throw new \RuntimeException('Connection storage unavailable');
                    }
                } catch (\Throwable $error) {
                    $this->forward->del((string) $fd);
                    $this->reverse->del($id);
                    throw $error;
                }
                return $id;
            }
            throw new \RuntimeException('Connection identity unavailable');
        });
    }

    public function socketIdFor(int $fd): ?string
    {
        $id = $this->forward->get((string) $fd, 'socket_id');
        return $id === false ? null : $id;
    }

    public function fdFor(string $socketId): ?int
    {
        $fd = $this->reverse->get($socketId, 'fd');
        return $fd === false || $this->socketIdFor($fd) !== $socketId ? null : $fd;
    }

    public function forget(int $fd, ?string $expectedSocketId = null): void
    {
        $this->synchronized(function () use ($fd, $expectedSocketId): void {
            $id = $this->socketIdFor($fd);
            if ($id === null || ($expectedSocketId !== null && $id !== $expectedSocketId)) {
                return;
            }
            $this->forward->del((string) $fd);
            $this->reverse->del($id);
        });
    }

    private function checkCapacity(string $app, int $excludeFd): void
    {
        $total = 0;
        $appCount = 0;
        foreach ($this->forward as $fd => $row) {
            if ((int) $fd === $excludeFd) {
                continue;
            }
            $total++;
            $appCount += (int) ($row['app_key'] === $app);
        }
        if (
            $total >= $this->limits->get('connections_total')
            || $appCount >= $this->limits->get('connections_per_app')
        ) {
            throw new \OverflowException('Connection capacity reached');
        }
    }

    public function mutex(): PusherMutex
    {
        return $this->lock;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function synchronized(callable $operation): mixed
    {
        return $this->lock->run($operation);
    }
}

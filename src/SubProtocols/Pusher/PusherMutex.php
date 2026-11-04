<?php

namespace Conveyor\SubProtocols\Pusher;

/** Kernel-released process lock; allocate its inode before fork, open separately in each worker. */
final class PusherMutex
{
    private string $path;
    private int $creatorPid;
    private int $lockPid = 0;
    private int $depth = 0;
    /** @var resource|null */
    private $handle = null;

    public function __construct()
    {
        $path = tempnam(sys_get_temp_dir(), 'conveyor-pusher-lock-');
        if ($path === false) {
            throw new \RuntimeException('Pusher synchronization unavailable');
        }
        $this->path = $path;
        $this->creatorPid = getmypid();
    }

    public function held(): bool
    {
        return $this->lockPid === getmypid() && $this->depth > 0;
    }

    /**
     * Callbacks must not yield, push or call network services.
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        if ($this->lockPid !== getmypid()) {
            if (is_resource($this->handle)) {
                fclose($this->handle);
            }
            $handle = fopen($this->path, 'r+');
            if ($handle === false) {
                throw new \RuntimeException('Pusher synchronization unavailable');
            }
            $this->handle = $handle;
            $this->lockPid = getmypid();
            $this->depth = 0;
        }
        if ($this->depth === 0 && !flock($this->handle, LOCK_EX)) {
            throw new \RuntimeException('Pusher synchronization unavailable');
        }
        $this->depth++;
        try {
            return $operation();
        } finally {
            $this->depth--;
            if ($this->depth === 0) {
                flock($this->handle, LOCK_UN);
            }
        }
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        if (getmypid() === $this->creatorPid && isset($this->path)) {
            unlink($this->path);
        }
    }
}

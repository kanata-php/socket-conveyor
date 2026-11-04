<?php

namespace Conveyor\SubProtocols\Pusher;

use OpenSwoole\Table;

/** Shared admission state, allocated before fork. No I/O while holding the mutex. */
final class PusherAdmission
{
    private Table $rates;
    private Table $pending;
    private PusherMutex $lock;

    public function __construct(private PusherLimits $limits)
    {
        $this->lock = new PusherMutex();
        $this->rates = new Table(32768);
        $this->rates->column('tokens', Table::TYPE_FLOAT);
        $this->rates->column('time', Table::TYPE_FLOAT);
        $this->rates->create();
        $this->pending = new Table(2048);
        $this->pending->column('generation', Table::TYPE_STRING, 40);
        $this->pending->column('time', Table::TYPE_FLOAT);
        $this->pending->column('pid', Table::TYPE_INT, 8);
        $this->pending->create();
    }

    public function enqueue(string $generation, string $app, int $bytes): string
    {
        return $this->atomic(function () use ($generation, $app, $bytes): string {
            if ($bytes > $this->limits->get('frame_bytes')) {
                throw new \OverflowException('Frame too large');
            }
            $this->take('fs', $generation, 'frame_rate_socket', 'frame_burst_socket');
            $this->take('fa', $app, 'frame_rate_app', 'frame_burst_app');
            $now = hrtime(true) / 1e9;
            $socket = $total = 0;
            foreach ($this->pending as $key => $row) {
                // A queued lease expires; an executing lease expires only after its worker dies.
                if (
                    $now - $row['time'] > $this->limits->get('task_timeout_seconds')
                    && ($row['pid'] === 0 || !$this->workerAlive($row['pid']))
                ) {
                    $this->pending->del($key);
                    continue;
                }
                $total++;
                $socket += (int) ($row['generation'] === $generation);
            }
            if ($socket >= $this->limits->get('pending_per_socket') || $total >= $this->limits->get('pending_total')) {
                throw new \OverflowException('Task capacity reached');
            }
            $id = bin2hex(random_bytes(16));
            if (!$this->pending->set($id, ['generation' => $generation, 'time' => $now, 'pid' => 0])) {
                throw new \OverflowException('Task storage unavailable');
            }
            return $id;
        });
    }

    public function claim(string $id, string $generation): bool
    {
        return $this->atomic(function () use ($id, $generation): bool {
            $row = $this->pending->get($id);
            if (
                $row === false || $row['generation'] !== $generation || $row['pid'] !== 0
                || hrtime(true) / 1e9 - $row['time'] > $this->limits->get('task_timeout_seconds')
            ) {
                return false;
            }
            return $this->pending->set($id, ['pid' => getmypid(), 'time' => hrtime(true) / 1e9]);
        });
    }

    public function release(string $id): void
    {
        $this->atomic(fn() => $this->pending->del($id));
    }

    public function event(string $app, ?string $generation = null, int $count = 1): void
    {
        $this->atomic(function () use ($app, $generation, $count): void {
            if ($generation !== null) {
                $this->take('cs', $generation, 'client_rate_socket', 'client_burst_socket', $count);
            }
            $this->take('ea', $app, 'event_rate_app', 'event_burst_app', $count);
        });
    }

    public function forget(string $generation): void
    {
        $this->atomic(function () use ($generation): void {
            foreach (['fs', 'cs'] as $type) {
                $this->rates->del($this->key($type, $generation));
            }
            foreach ($this->pending as $key => $row) {
                if ($row['generation'] === $generation) {
                    $this->pending->del($key);
                }
            }
        });
    }

    private function take(string $type, string $identity, string $rate, string $burst, int $count = 1): void
    {
        $key = $this->key($type, $identity);
        $row = $this->rates->get($key);
        $now = hrtime(true) / 1e9;
        $capacity = $this->limits->get($burst);
        $tokens = $row === false ? $capacity
            : min($capacity, $row['tokens'] + ($now - $row['time']) * $this->limits->get($rate));
        if ($tokens < $count) {
            throw new \OverflowException('Rate limit reached');
        }
        if (!$this->rates->set($key, ['tokens' => (float) ($tokens - $count), 'time' => $now])) {
            throw new \OverflowException('Rate storage unavailable');
        }
    }

    private function workerAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        // OpenSwoole production containers are Linux; /proc remains available without ext-posix.
        return is_dir('/proc/' . $pid);
    }

    private function key(string $type, string $identity): string
    {
        return substr(hash('sha256', json_encode([$type, $identity], JSON_THROW_ON_ERROR)), 0, 48);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function atomic(callable $operation): mixed
    {
        return $this->lock->run($operation);
    }
}

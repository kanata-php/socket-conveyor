<?php

namespace Conveyor\SubProtocols\Pusher;

use OpenSwoole\Table;

/** Fixed-cardinality counters shared by all workers, with logarithmically sampled diagnostics. */
final class PusherDiagnostics
{
    private static ?Table $counts = null;

    public static function initialize(): void
    {
        if (self::$counts !== null) {
            return;
        }
        self::$counts = new Table(8);
        self::$counts->column('count', Table::TYPE_INT, 8);
        self::$counts->create();
        foreach (['invalid', 'capacity', 'storage', 'unexpected'] as $reason) {
            self::$counts->set($reason, ['count' => 0]);
        }
    }

    public static function record(\Throwable $error): void
    {
        $reason = match (true) {
            $error instanceof \InvalidArgumentException, $error instanceof \JsonException => 'invalid',
            $error instanceof \OverflowException => 'capacity',
            $error instanceof \RuntimeException => 'storage',
            default => 'unexpected',
        };
        if (self::$counts === null) {
            error_log('pusher_failure class=' . get_class($error));
            return;
        }
        $count = self::$counts->incr($reason, 'count');
        if ($reason !== 'invalid' && $reason !== 'capacity' && is_int($count) && ($count & ($count - 1)) === 0) {
            error_log('pusher_failure reason=' . $reason . ' class=' . get_class($error) . ' count=' . $count);
        }
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        $counts = [];
        if (self::$counts !== null) {
            foreach (self::$counts as $reason => $row) {
                $counts[$reason] = $row['count'];
            }
        }
        return $counts;
    }
}

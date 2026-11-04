<?php

namespace Conveyor\SubProtocols\Pusher;

/** Integer safety budgets. Overrides may lower, but never exceed, physical defaults. */
final class PusherLimits
{
    public const DEFAULTS = [
        'memberships_per_socket' => 100, 'memberships_per_app' => 10000, 'memberships_total' => 40000,
        'connections_per_app' => 1000, 'connections_total' => 5000, 'presence_per_channel' => 100,
        'client_rate_socket' => 10, 'client_burst_socket' => 10,
        'event_rate_app' => 1000, 'event_burst_app' => 1000,
        'frame_rate_socket' => 100, 'frame_burst_socket' => 100,
        'frame_rate_app' => 5000, 'frame_burst_app' => 5000,
        'pending_per_socket' => 8, 'pending_total' => 1024,
        'frame_bytes' => 65536, 'http_body_bytes' => 1048576,
        'send_buffer_bytes' => 1048576, 'send_timeout_seconds' => 1,
        'idle_seconds' => 120, 'heartbeat_seconds' => 30, 'task_timeout_seconds' => 30,
    ];

    /** @var array<string, int> */
    private array $values;

    /** @param array<string, mixed> $overrides */
    public function __construct(array $overrides = [])
    {
        $this->values = self::DEFAULTS;
        foreach ($overrides as $name => $value) {
            if (!isset(self::DEFAULTS[$name]) || !is_int($value) || $value < 1 || $value > self::DEFAULTS[$name]) {
                throw new \InvalidArgumentException('Invalid Pusher limit');
            }
            $this->values[$name] = $value;
        }
        foreach (
            [['memberships_per_socket', 'memberships_per_app'], ['memberships_per_app', 'memberships_total'],
            ['connections_per_app', 'connections_total'], ['pending_per_socket', 'pending_total'],
            ['heartbeat_seconds', 'idle_seconds']] as [$small, $large]
        ) {
            if ($this->get($small) > $this->get($large)) {
                throw new \InvalidArgumentException('Contradictory Pusher limits');
            }
        }
    }

    public function get(string $name): int
    {
        return $this->values[$name];
    }
}

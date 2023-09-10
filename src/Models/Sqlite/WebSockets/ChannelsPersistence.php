<?php

namespace Conveyor\Models\Sqlite\WebSockets;

use Conveyor\Models\Interfaces\ChannelPersistenceInterface;
use Conveyor\Models\Sqlite\DatabaseBootstrap;
use Conveyor\Models\Sqlite\WsChannel;
use Error;
use Exception;

class ChannelsPersistence implements ChannelPersistenceInterface
{
    public function __construct()
    {
        $this->refresh(true);
    }

    public function connect(int $fd, string $channel): void
    {
        $this->disconnect($fd);
        try {
            WsChannel::create([
                'fd' => $fd,
                'channel' => $channel,
            ]);
        } catch (Exception|Error $e) {
            // --
        }
    }

    public function disconnect(int $fd): void
    {
        try {
            WsChannel::where('fd', '=', $fd)->first()?->delete();
        } catch (Exception|Error $e) {
            // --
        }
    }

    public function getAllConnections(): array
    {
        try {
            $channels = WsChannel::all()->toArray();
        } catch (Exception|Error $e) {
            return [];
        }

        if (empty($channels)) {
            return [];
        }

        $connections = [];
        foreach ($channels as $channel) {
            $connections[$channel['fd']] = $channel['channel'];
        }

        return $connections;
    }

    public function refresh(bool $fresh = false, ?string $databasePath = null): static
    {
        (new DatabaseBootstrap($databasePath))->migrateChannelPersistence($fresh);

        if (!$fresh) {
            return $this;
        }

        WsChannel::truncate();
        return $this;
    }

    public function getChannel(int $fd): ?string
    {
        return WsChannel::where('fd', '=', $fd)->first()?->channel;
    }
}

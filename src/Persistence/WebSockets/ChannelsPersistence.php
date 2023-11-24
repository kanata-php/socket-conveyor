<?php

namespace Conveyor\Persistence\WebSockets;

use Conveyor\Models\WsChannel;
use Conveyor\Persistence\Abstracts\GenericPersistence;
use Conveyor\Persistence\DatabaseBootstrap;
use Conveyor\Persistence\Interfaces\ChannelPersistenceInterface;
use Error;
use Exception;

class ChannelsPersistence extends GenericPersistence implements ChannelPersistenceInterface
{
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

    /**
     * @throws Exception
     */
    public function refresh(bool $fresh = false): static
    {
        /** @throws Exception */
        (new DatabaseBootstrap($this->databaseOptions, $this->manager))->migrateChannelPersistence($fresh);

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

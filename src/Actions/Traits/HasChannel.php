<?php

namespace Conveyor\Actions\Traits;

trait HasChannel
{
    use HasPersistence;

    protected function isConnectedToAnyChannel(int $fd): bool
    {
        return in_array($fd, array_keys($this->channelPersistence->getAllConnections()));
    }
}

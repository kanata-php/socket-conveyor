<?php

namespace Conveyor\SubProtocols\Conveyor\Actions;

use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use Conveyor\SubProtocols\Conveyor\Actions\Traits\HasPresence;
use Exception;

class ChannelDisconnectAction extends AbstractAction
{
    use HasPresence;

    public const NAME = 'channel-disconnect';

    protected string $name = self::NAME;

    public function validateData(array $data): mixed
    {
        return true;
    }

    public function execute(array $data): mixed
    {
        if (!$this->validateData($data)) {
            return false;
        }

        if (null !== $this->channelPersistence) {
            $this->channelPersistence->disconnect($this->fd);
        }

        // @phpstan-ignore-next-line
        if ($this->conveyorOptions->{Constants::USE_PRESENCE}) {
            $this->broadcastPresence();
        }

        return null;
    }
}

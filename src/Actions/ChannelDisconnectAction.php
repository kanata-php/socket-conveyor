<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;

class ChannelDisconnectAction extends AbstractAction
{
    const NAME = 'channel-disconnect';

    protected string $name = self::NAME;

    public function validateData(array $data): void
    {
        return;
    }

    public function execute(array $data): mixed
    {
        $this->validateData($data);

        if (null === $this->fd) {
            throw new Exception('FD not specified!');
        }

        if (null !== $this->channelPersistence) {
            $this->channelPersistence->disconnect($this->fd);
        }

        return null;
    }
}

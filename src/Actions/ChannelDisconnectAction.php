<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;

class ChannelDisconnectAction extends AbstractAction
{
    const ACTION_NAME = 'channel-disconnect';

    protected string $name = self::ACTION_NAME;

    public function validateData(array $data): void
    {
        return;
    }

    public function execute(array $data): mixed
    {
        return null;
    }
}
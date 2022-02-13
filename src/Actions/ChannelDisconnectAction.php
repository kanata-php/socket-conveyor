<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Conveyor\Actions\Traits\HasPersistence;
use Exception;

class ChannelDisconnectAction extends AbstractAction
{
    use HasPersistence;
    
    protected string $name = 'channel-disconnect';

    public function validateData(array $data): void
    {
        return;
    }

    public function execute(array $data): mixed
    {
        return null;
    }
}
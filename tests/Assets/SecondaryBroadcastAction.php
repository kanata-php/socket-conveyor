<?php

namespace Tests\Assets;

use Conveyor\Actions\BroadcastAction;

class SecondaryBroadcastAction extends BroadcastAction
{
    const ACTION_NAME = 'secondary-broadcast-action';
    protected string $name = self::ACTION_NAME;
}
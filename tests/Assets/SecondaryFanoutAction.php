<?php

namespace Tests\Assets;

use Conveyor\Actions\FanoutAction;;

class SecondaryFanoutAction extends FanoutAction
{
    const ACTION_NAME = 'secondary-fanout-action';
    protected string $name = self::ACTION_NAME;
}
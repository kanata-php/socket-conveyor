<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;

class AddListenerAction extends AbstractAction
{
    protected string $name = 'add-listener';

    public function validateData(array $data): void
    {
        return;
    }

    public function execute(array $data): mixed
    {
        return null;
    }
}
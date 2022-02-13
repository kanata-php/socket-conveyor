<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;

class BaseAction extends AbstractAction
{
    protected string $name = 'base-action';

    public function validateData(array $data): void
    {
        return;
    }

    public function execute(array $data): mixed
    {
        $this->send($data['data'], $this->fd);
        return null;
    }
}
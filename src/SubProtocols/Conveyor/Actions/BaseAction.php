<?php

namespace Conveyor\SubProtocols\Conveyor\Actions;

use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use InvalidArgumentException;

class BaseAction extends AbstractAction
{
    public const NAME = 'base-action';

    protected string $name = self::NAME;

    public function validateData(array $data): mixed
    {
        if (!isset($data['data'])) {
            $this->send('BaseAction required \'data\' field to be created!', $this->fd);
            return false;
        }

        return true;
    }

    public function execute(array $data): mixed
    {
        if (!$this->validateData($data)) {
            return false;
        }

        $this->send($data['data'], $this->fd);
        return null;
    }
}

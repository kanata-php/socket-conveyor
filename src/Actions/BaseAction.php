<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use InvalidArgumentException;

class BaseAction extends AbstractAction
{
    public const NAME = 'base-action';

    protected string $name = self::NAME;

    public function validateData(array $data): void
    {
        if (!isset($data['data'])) {
            throw new InvalidArgumentException('BaseAction required \'data\' field to be created!');
        }
    }

    public function execute(array $data): mixed
    {
        $this->send($data['data'], $this->fd);
        return null;
    }
}

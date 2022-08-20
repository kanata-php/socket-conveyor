<?php

namespace Conveyor\Actions;

use Exception;
use InvalidArgumentException;
use Conveyor\Actions\Abstractions\AbstractAction;

class FanoutAction extends AbstractAction
{
    const ACTION_NAME = 'fanout-action';

    protected string $name = self::ACTION_NAME;
    protected int $fd;

    /**
     * @param array $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->send($data['data']);
        return true;
    }

    /**
     * @param array $data
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function validateData(array $data) : void
    {
        if (!isset($data['data'])) {
            throw new InvalidArgumentException('FanoutAction required \'data\' field to be created!');
        }
    }
}

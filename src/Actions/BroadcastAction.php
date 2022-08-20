<?php

namespace Conveyor\Actions;

use Exception;
use InvalidArgumentException;
use Conveyor\Actions\Abstractions\AbstractAction;

class BroadcastAction extends AbstractAction
{
    const ACTION_NAME = 'broadcast-action';

    protected string $name = self::ACTION_NAME;
    protected int $fd;

    /**
     * @param array $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->send($data['data'], null, true);
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
            throw new InvalidArgumentException('BroadcastAction required \'data\' field to be created!');
        }
    }
}
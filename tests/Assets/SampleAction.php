<?php

namespace Tests\Assets;

use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

class SampleAction extends AbstractAction
{
    public const NAME = 'sample-action';

    protected string $name = self::NAME;
    protected int $fd;

    /**
     * @param array $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->send(json_encode($data['data']), $this->fd);
        return true;
    }

    /**
     * @param array $data
     * @return mixed
     *
     * @throws InvalidArgumentException
     */
    public function validateData(array $data): mixed
    {
        if (!isset($data['action'])) {
            throw new InvalidArgumentException('SampleAction required \'action\' field to be created!');
        }

        return null;
    }
}

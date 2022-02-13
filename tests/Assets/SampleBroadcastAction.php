<?php

namespace Tests\Assets;

use Conveyor\Actions\Traits\HasPersistence;
use Exception;
use InvalidArgumentException;
use Conveyor\Actions\Abstractions\AbstractAction;

class SampleBroadcastAction extends AbstractAction
{
    use HasPersistence;

    protected string $name = 'sample-broadcast-action';
    protected int $fd;

    /**
     * @param array $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->send('message-back', null, true);
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
        if (!isset($data['action'])) {
            throw new InvalidArgumentException('SampleAction required \'action\' field to be created!');
        }
    }

    /**
     * @return int $fd
     *
     * @return void
     */
    public function setFd(int $fd): void
    {
        $this->fd = $fd;
    }

    /**
     * @return int
     */
    public function getFd(): int
    {
        return $this->fd;
    }

    /**
     * @return mixed $server
     *
     * @return void
     */
    public function setServer($server): void
    {
        $this->server = $server;
    }

    /**
     * @return mixed
     */
    public function getServer()
    {
        return $this->server;
    }
}
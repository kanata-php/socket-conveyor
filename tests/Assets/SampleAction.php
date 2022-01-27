<?php

namespace Tests\Assets;

use Exception;
use InvalidArgumentException;
use stdClass;
use Conveyor\Actions\Abstractions\AbstractAction;
use Conveyor\Actions\Traits\ProcedureActionTrait;

class SampleAction extends  AbstractAction
{
    use ProcedureActionTrait;

    protected string $name = 'sample-action';
    protected ?int $fd = null;

    /**
     * @param array $data
     *
     * @return bool
     *
     * @throws Exception
     */
    public function execute(array $data, ?int $fd, $server)
    {
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
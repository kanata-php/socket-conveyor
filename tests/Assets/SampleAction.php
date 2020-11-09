<?php

namespace Tests\Assets;

use stdClass;
use Conveyor\Actions\Abstractions\AbstractAction;
use Conveyor\Actions\Traits\ProcedureActionTrait;

class SampleAction extends  AbstractAction
{
    use ProcedureActionTrait;

    /** @var string */
    protected $name = 'sample-action';

    /** @var int */
    protected $fd;

    /**
     * @param array $data
     *
     * @return bool
     *
     * @throws Exception
     */
    public function execute(array $data)
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
     * @return stdClass $server
     *
     * @return void
     */
    public function setServer(stdClass $server): void
    {
        $this->server = $server;
    }

    /**
     * @return stdClass
     */
    public function getServer(): stdClass
    {
        return $this->server;
    }
}
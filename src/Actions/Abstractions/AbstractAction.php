<?php

namespace Conveyor\Actions\Abstractions;

use Exception;
use Conveyor\Actions\Interfaces\ActionInterface;

abstract class AbstractAction implements ActionInterface
{
    /** @var array */
    protected array $data;

    /**
     * @return string
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * @param array $data
     * @return void
     *
     * @throws Exception
     */
    abstract public function validateData(array $data) : void;

    /**
     * @param array $data
     * @param int $fd
     * @param mixed $server
     * @return mixed
     */
    abstract public function execute(array $data, int $fd, $server);
}

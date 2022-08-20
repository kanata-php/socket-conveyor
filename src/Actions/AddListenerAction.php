<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

class AddListenerAction extends AbstractAction
{
    const ACTION_NAME = 'add-listener';

    protected string $name = self::ACTION_NAME;

    public function validateData(array $data): void
    {
        if (!isset($data['listen'])) {
            throw new InvalidArgumentException('Add listener must specify "listen"!');
        }
    }

    public function execute(array $data): mixed
    {
        $this->validateData($data);


        if (null === $this->fd) {
            throw new Exception('FD not specified!');
        }

        if (null === $this->persistence && null === $this->listenerPersistence) {
            throw new Exception('Persistence not set!');
        }

        if (null !== $this->persistence) {
            $this->persistence->listen(fd: $this->fd, action: $data['listen']);
        }

        if (null !== $this->listenerPersistence) {
            $this->listenerPersistence->listen(fd: $this->fd, action: $data['listen']);
        }

        return null;
    }
}
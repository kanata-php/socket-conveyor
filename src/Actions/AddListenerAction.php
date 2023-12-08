<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

class AddListenerAction extends AbstractAction
{
    public const NAME = 'add-listener';

    protected string $name = self::NAME;

    public function validateData(array $data): void
    {
        if (!isset($data['listen'])) {
            throw new InvalidArgumentException('Add listener must specify "listen"!');
        }
    }

    /**
     * @return null
     * @throws Exception
     */
    public function execute(array $data): null
    {
        // @throws Exception
        $this->validateData($data);

        if (null === $this->listenerPersistence) {
            throw new Exception('Persistence not set!');
        }

        $this->listenerPersistence->listen(fd: $this->fd, action: $data['listen']);

        return null;
    }
}

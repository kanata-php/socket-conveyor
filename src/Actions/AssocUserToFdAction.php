<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

class AssocUserToFdAction extends AbstractAction
{
    const ACTION_NAME = 'assoc-user-to-fd-action';

    protected string $name = self::ACTION_NAME;

    /**
     * @param array $data
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->connectUserToFd($data);
        return null;
    }

    public function connectUserToFd(array $data): void
    {
        if (null === $this->fd) {
            throw new Exception('FD not specified!');
        }

        // TODO: this will be removed
        if (null !== $this->persistence) {
            $this->persistence->assoc($this->fd, $data['userId']);
        }

        if (null !== $this->userAssocPersistence) {
            $this->userAssocPersistence->assoc($this->fd, $data['userId']);
        }
    }

    /**
     * @param array $data
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function validateData(array $data) : void
    {
        if (!isset($data['userId'])) {
            throw new InvalidArgumentException('The userId is required to associate connection to team!');
        }
    }
}

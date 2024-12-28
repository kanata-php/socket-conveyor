<?php

namespace Conveyor\SubProtocols\Conveyor\Actions;

use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

class AssocUserToFdAction extends AbstractAction
{
    public const NAME = 'assoc-user-to-fd-action';

    protected string $name = self::NAME;

    /**
     * @param array<array-key, mixed> $data
     *
     * @return null
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function execute(array $data): null
    {
        if (!$this->validateData($data)) {
            return false;
        }

        $this->connectUserToFd($data);
        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return void
     * @throws Exception
     */
    public function connectUserToFd(array $data): void
    {
        if (null !== $this->userAssocPersistence) {
            $this->userAssocPersistence->assoc($this->fd, $data['userId']);
        }
    }

    public function validateData(array $data): mixed
    {
        if (!isset($data['userId'])) {
            $this->send('The userId is required to associate connection to team!', $this->fd);
            return false;
        }

        return true;
    }
}

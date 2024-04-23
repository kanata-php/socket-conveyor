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

    /**
     * @param array<array-key, mixed> $data
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function validateData(array $data): void
    {
        if (!isset($data['userId'])) {
            throw new InvalidArgumentException('The userId is required to associate connection to team!');
        }
    }
}

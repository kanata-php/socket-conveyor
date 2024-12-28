<?php

namespace Conveyor\SubProtocols\Conveyor\Actions;

use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use Exception;

class FanoutAction extends AbstractAction
{
    public const NAME = 'fanout-action';

    protected string $name = self::NAME;
    protected int $fd;

    /**
     * @param array<array-key, mixed> $data
     * @return bool
     * @throws Exception
     */
    public function execute(array $data): bool
    {
        if (!$this->validateData($data)) {
            return false;
        }

        if ($this->isAuthEnabled() && !$this->authCheck($data['auth'])) {
            $this->send('Failed to broadcast fanout', $this->fd);
            return false;
        }

        $this->send($data['data']);
        return true;
    }

    private function isAuthEnabled(): bool
    {
        return null !== $this->conveyorOptions->{Constants::WEBSOCKET_SERVER_TOKEN}; // @phpstan-ignore-line
    }

    public function validateData(array $data): mixed
    {
        if (!isset($data['data'])) {
            $this->send('FanoutAction required \'data\' field to be created!', $this->fd);
            return false;
        }

        if ($this->isAuthEnabled() && !isset($data['auth'])) {
            $this->send('FanoutAction must specify "auth" when authentication is set!', $this->fd);
            return false;
        }

        return true;
    }

    private function authCheck(string $auth): bool
    {
        if ($auth === $this->conveyorOptions->{Constants::WEBSOCKET_SERVER_TOKEN}) {
            return true;
        }

        return false;
    }
}

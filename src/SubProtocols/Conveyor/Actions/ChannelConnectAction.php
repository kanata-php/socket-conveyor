<?php

namespace Conveyor\SubProtocols\Conveyor\Actions;

use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use Conveyor\SubProtocols\Conveyor\Actions\Traits\HasPresence;
use Exception;
use InvalidArgumentException;

class ChannelConnectAction extends AbstractAction
{
    use HasPresence;

    public const NAME = 'channel-connect';

    protected string $name = self::NAME;

    /**
     * @param array{channel: string, auth: ?string} $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        if (!$this->validateData($data)) {
            return false;
        }

        $channel = $data['channel'];

        if ($this->isAuthEnabled() && !$this->authCheck($channel, auth: $data['auth'])) {
            $this->send('Failed to connect to channel', $this->fd);
            return null;
        }

        if (null !== $this->channelPersistence) {
            $this->channelPersistence->connect($this->fd, $channel);
        }

        // @phpstan-ignore-next-line
        if ($this->conveyorOptions->{Constants::USE_PRESENCE}) {
            $this->broadcastPresence();
        }

        return null;
    }

    public function validateData(array $data): mixed
    {
        if (!isset($data['channel'])) {
            $this->send('Channel connection must specify "channel"!', $this->fd);
        }

        if ($this->isAuthEnabled() && !isset($data['auth'])) {
            $this->send('Channel connection must specify "auth" when authentication is set!', $this->fd);
        }

        return true;
    }

    private function isAuthEnabled(): bool
    {
        return null !== $this->conveyorOptions->{Constants::WEBSOCKET_SERVER_TOKEN}; // @phpstan-ignore-line
    }

    /**
     * This implementation is for Laravel Broadcasting. This is just
     * the fallback, you are able to customize by using Filter Hooks.
     *
     * @param string $channel
     * @param string $auth
     * @return bool
     */
    private function authCheck(string $channel, string $auth): bool
    {
        if ($auth === $this->conveyorOptions->{Constants::WEBSOCKET_SERVER_TOKEN}) {
            return true;
        }

        $record = $this->authTokenPersistence->byToken($auth);
        $this->authTokenPersistence->consume($auth);

        if ($record === false || $record['channel'] !== $channel) {
            return false;
        }

        return true;
    }
}

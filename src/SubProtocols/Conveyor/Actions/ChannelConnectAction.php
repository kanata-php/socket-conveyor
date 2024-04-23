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
     * @param array<array-key, mixed> $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->validateData($data);

        $channel = $data['channel'];

        if (null !== $this->channelPersistence) {
            $this->channelPersistence->connect($this->fd, $channel);
        }

        // @phpstan-ignore-next-line
        if ($this->conveyorOptions->{Constants::USE_PRESENCE}) {
            $this->broadcastPresence();
        }

        return null;
    }

    public function validateData(array $data): void
    {
        if (!isset($data['channel'])) {
            throw new InvalidArgumentException('Channel connection must specify "channel"!');
        }
    }
}

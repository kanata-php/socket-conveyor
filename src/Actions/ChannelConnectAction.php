<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

class ChannelConnectAction extends AbstractAction
{
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

        return null;
    }

    public function validateData(array $data): void
    {
        if (!isset($data['channel'])) {
            throw new InvalidArgumentException('Channel connection must specify "channel"!');
        }
    }
}

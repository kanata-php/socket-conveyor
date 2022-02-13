<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

class ChannelConnectAction extends AbstractAction
{
    protected string $name = 'channel-connect';

    public function validateData(array $data): void
    {
        if (!isset($data['channel'])) {
            throw new InvalidArgumentException('Channel connection must specify "channel"!');
        }
    }

    /**
     * @param array $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->validateData($data);

        if (null === $this->fd) {
            throw new Exception('FD not specified!');
        }

        $channel = $data['channel'];

        $this->channels[$this->fd] = $channel;

        $this->persistence->connect($this->fd, $channel);

        return null;
    }
}
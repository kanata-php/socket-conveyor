<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Abstractions\AbstractAction;
use Exception;

class ChannelDisconnectAction extends AbstractAction
{
    public const NAME = 'channel-disconnect';

    protected string $name = self::NAME;

    public function validateData(array $data): void
    {
        return;
    }

    /**
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        // @throws Exception
        $this->validateData($data);

        if (null !== $this->channelPersistence) {
            $this->channelPersistence->disconnect($this->fd);
        }

        return null;
    }
}

<?php

namespace Conveyor\SubProtocols\Conveyor\Actions;

use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use Exception;

class BroadcastAction extends AbstractAction
{
    public const NAME = 'broadcast-action';

    protected string $name = self::NAME;
    protected int $fd;

    /**
     * @param array<array-key, mixed> $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        if (!$this->validateData($data)) {
            return false;
        }

        $this->send($data['data'], null, true);
        return true;
    }

    public function validateData(array $data): mixed
    {
        if (!isset($data['data'])) {
            $this->send('BroadcastAction required \'data\' field to be created!', $this->fd);
            return false;
        }

        return true;
    }
}

<?php

namespace Conveyor\SubProtocols\Conveyor\Actions;

use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

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
        $this->send($data['data']);
        return true;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function validateData(array $data): void
    {
        if (!isset($data['data'])) {
            throw new InvalidArgumentException('FanoutAction required \'data\' field to be created!');
        }
    }
}

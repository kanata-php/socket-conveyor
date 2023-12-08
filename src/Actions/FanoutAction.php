<?php

namespace Conveyor\Actions;

use Exception;
use InvalidArgumentException;
use Conveyor\Actions\Abstractions\AbstractAction;

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

<?php

namespace Conveyor\SubProtocols\Conveyor\Actions;

use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use Exception;
use InvalidArgumentException;

class AcknowledgeAction extends AbstractAction
{
    public const NAME = 'acknowledge-action';

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

        $this->messageAcknowledgementPersistence->acknowledge($data['data']);
        return true;
    }

    public function validateData(array $data): mixed
    {
        if (!isset($data['data'])) {
            $this->send('AcknowledgeAction required \'data\' field to be created!', $this->fd);
            return false;
        }

        return true;
    }
}

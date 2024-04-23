<?php

namespace Conveyor\SubProtocols\Conveyor\Actions\Traits;

use Conveyor\Constants;
use Conveyor\Helpers\Arr;
use Conveyor\SubProtocols\Conveyor\Actions\AcknowledgeAction;

trait HasAcknowledgment
{
    /**
     * @param array{action: string, id: string} $data
     * @return void
     */
    public function acknowledgeMessage(array $data): void
    {
        if (
            !$this->conveyorOptions->{Constants::USE_ACKNOWLEDGMENT} // @phpstan-ignore-line
            || AcknowledgeAction::NAME === $data['action']
            || !isset($data['id']) // @phpstan-ignore-line
        ) {
            return;
        }

        $this->server->push($this->fd, json_encode([
            'action' => AcknowledgeAction::NAME,
            'data' => $data['id'],
        ]));
    }
}

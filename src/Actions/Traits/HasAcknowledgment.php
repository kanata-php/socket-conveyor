<?php

namespace Conveyor\Actions\Traits;

use Conveyor\Actions\AcknowledgeAction;
use Conveyor\Constants;
use Conveyor\Helpers\Arr;

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

    /**
     * @important This method must be called from within a coroutine context.
     *
     * @param int $fd
     * @param string $data
     * @return void
     */
    public function checkAcknowledgment(int $fd, string $data): void
    {
        // @phpstan-ignore-next-line
        if (!$this->conveyorOptions->{Constants::USE_ACKNOWLEDGMENT}) {
            return;
        }

        $parsedData = json_decode($data, true);
        if (
            Arr::get($parsedData, 'action') !== AcknowledgeAction::NAME
            || !isset($parsedData['id'])
        ) {
            return;
        }
        $messageHash = $parsedData['id'];

        $this->messageAcknowledmentPersistence->register(
            messageHash: $messageHash,
            count: $this->conveyorOptions->{Constants::ACKNOWLEDGMENT_ATTEMPTS}, // @phpstan-ignore-line
        );

        $timers = [];
        $baseTimeout = $this->conveyorOptions->{Constants::ACKNOWLEDGMENT_TIMOUT} * 1000; // @phpstan-ignore-line
        $attempts = $this->conveyorOptions->{Constants::ACKNOWLEDGMENT_ATTEMPTS}; // @phpstan-ignore-line
        for ($i = 0; $i < $attempts; $i++) {
            $timers[] = $this->server->after(
                $baseTimeout * ($i + 1),
                function () use ($fd, $data, $messageHash, &$timers) {
                    if ($this->messageAcknowledmentPersistence->has($messageHash)) {
                        $this->messageAcknowledmentPersistence->subtract($messageHash);
                        if ($this->server->isEstablished($fd)) {
                            $this->server->push($fd, $data);
                        }
                        return;
                    }

                    foreach ($timers as $timer) {
                        $this->server->clearTimer($timer);
                    }
                }
            );
        }
    }
}

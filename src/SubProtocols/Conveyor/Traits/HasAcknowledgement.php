<?php

namespace Conveyor\SubProtocols\Conveyor\Traits;

use Conveyor\Constants;
use Conveyor\Helpers\Arr;
use Conveyor\SubProtocols\Conveyor\Actions\AcknowledgeAction;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\MessageAcknowledgementPersistenceInterface;
use Exception;
use Hook\Action;
use OpenSwoole\WebSocket\Server;

trait HasAcknowledgement
{
    /**
     * @important This method must be called from within a coroutine context.
     *
     * @return void
     */
    public function addAcknowledgementHooks(): void
    {
        Action::addAction(
            Constants::ACTION_AFTER_PUSH_MESSAGE,
            function (
                int $fd,
                string $data,
                Server $server,
                ?MessageAcknowledgementPersistenceInterface $ackPersistence
            ) {
                // @phpstan-ignore-next-line
                if (!$this->conveyorOptions->{Constants::USE_ACKNOWLEDGMENT}) {
                    return;
                }

                if (null === $ackPersistence) {
                    throw new Exception('Acknowledgement persistence is not set!');
                }

                $parsedData = json_decode($data, true);
                if (
                    Arr::get($parsedData, 'action') !== AcknowledgeAction::NAME
                    || !isset($parsedData['id'])
                ) {
                    return;
                }
                $messageHash = $parsedData['id'];

                $ackPersistence->register(
                    messageHash: $messageHash,
                    count: $this->conveyorOptions->{Constants::ACKNOWLEDGMENT_ATTEMPTS}, // @phpstan-ignore-line
                );

                // @phpstan-ignore-next-line
                $baseTimeout = $this->conveyorOptions->{Constants::ACKNOWLEDGMENT_TIMOUT} * 1000;
                // @phpstan-ignore-next-line
                $attempts = $this->conveyorOptions->{Constants::ACKNOWLEDGMENT_ATTEMPTS};
                for ($i = 0; $i < $attempts; $i++) {
                    // @phpstan-ignore-next-line
                    $server->after(
                        $baseTimeout * ($i + 1),
                        function () use (
                            $fd,
                            $data,
                            $messageHash,
                            $ackPersistence,
                            $server,
                        ) {
                            if ($ackPersistence->has($messageHash)) {
                                $ackPersistence->subtract($messageHash);
                                if ($server->isEstablished($fd)) {
                                    $server->push($fd, $data);
                                }
                            }
                        }
                    );
                }
            },
        );
    }
}

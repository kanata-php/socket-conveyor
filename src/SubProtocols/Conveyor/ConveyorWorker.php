<?php

namespace Conveyor\SubProtocols\Conveyor;

use Conveyor\Config\ConveyorOptions;
use Conveyor\Constants;
use Conveyor\Events\AfterMessageHandledEvent;
use Conveyor\Events\BeforeMessageHandledEvent;
use Conveyor\Events\ConnectionCloseEvent;
use Conveyor\Events\MessageReceivedEvent;
use Conveyor\Helpers\Arr;
use Conveyor\SubProtocols\Conveyor\Actions\AcknowledgeAction;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\MessageAcknowledgementPersistenceInterface;
use Exception;
use Hook\Action;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ConveyorWorker
{
    /**
     * @param Server $server
     * @param ConveyorOptions $conveyorOptions
     * @param EventDispatcher|null $eventDispatcher
     * @param array<array-key, GenericPersistenceInterface> $persistence
     */
    public function __construct(
        protected Server $server,
        protected ConveyorOptions $conveyorOptions,
        protected ?EventDispatcher $eventDispatcher = null,
        protected array $persistence = [],
    ) {
        $this->addListeners();
        $this->addAcknowledgementHooks();
    }

    private function addListeners(): void
    {
        $this->eventDispatcher->addListener(
            eventName: Constants::EVENT_MESSAGE_RECEIVED,
            listener: function (MessageReceivedEvent $event) {
                if (empty($event->data)) {
                    return;
                }
                $this->processMessage($event->data);
            }
        );

        $this->eventDispatcher->addListener(
            eventName: Constants::EVENT_SERVER_CLOSE,
            listener: fn(ConnectionCloseEvent $event) =>
                $this->closeConnections($event->server, $event->fd)
        );
    }

    private function closeConnections(Server $server, int $fd): void
    {
        if (isset($this->persistence['channels'])) {
            $this->persistence['channels']->disconnect($fd); // @phpstan-ignore-line
        }
    }

    private function processMessage(string $data): void
    {
        $parsedFrame = json_decode($data, true);
        $data = $parsedFrame['data'];
        $fd = $parsedFrame['fd'];

        $this->executeConveyor($fd, $data);
    }

    private function executeConveyor(int $fd, string $data): Conveyor
    {
        $this->eventDispatcher->dispatch(
            event: new BeforeMessageHandledEvent($this->server, $data, $fd),
            eventName: Constants::EVENT_BEFORE_MESSAGE_HANDLED,
        );

        $conveyor = Conveyor::init(options: $this->conveyorOptions->all())
            ->server($this->server)
            ->fd($fd)
            ->persistence($this->persistence)
            ->addActions($this->conveyorOptions->{Constants::ACTIONS} ?? [])
            ->closeConnections()
            ->run($data);

        $this->eventDispatcher->dispatch(
            event: new AfterMessageHandledEvent($this->server, $data, $fd),
            eventName: Constants::EVENT_AFTER_MESSAGE_HANDLED,
        );

        return $conveyor;
    }


    /**
     * @important This method must be called from within a coroutine context.
     *
     * TODO: move this to a trait
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

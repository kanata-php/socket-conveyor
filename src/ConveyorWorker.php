<?php

namespace Conveyor;

use Conveyor\Events\AfterMessageHandledEvent;
use Conveyor\Events\BeforeMessageHandledEvent;
use Conveyor\Events\MessageReceivedEvent;
use OpenSwoole\Process;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ConveyorWorker
{
    public const QUEUE_KEY = 'queue_key';
    public const QUEUE_MODE = 'queue_mode';

    /**
     * @var array<array-key, Process>
     */
    protected array $processes = [];

    public function __construct(
        protected Server $server,
        protected int $workers,
        protected array $conveyorOptions,
        protected ?EventDispatcher $eventDispatcher = null,
    ) {
        $this->prepareWorkers();
        $this->addListeners();
    }

    private function addListeners(): void
    {
        $this->eventDispatcher->addListener(
            eventName: ConveyorServer::EVENT_MESSAGE_RECEIVED,
            listener: fn(MessageReceivedEvent $event) => $this->push($event->data),
        );
    }

    public function push(string $data): void
    {
        $this->getProcess()->push($data);
    }

    private function getProcess(): Process
    {
        $process = current($this->processes);
        if (!next($this->processes)) {
            reset($this->processes);
        }

        return $process;
    }

    public function prepareWorkers(): void
    {
        for ($i = 0; $i < $this->workers; $i++) {
            $process = new Process(function (Process $process) {
                while (true) {
                    $frame = $process->pop();
                    $parsedFrame = json_decode($frame, true);
                    $data = $parsedFrame['data'];
                    $fd = $parsedFrame['fd'];

                    $this->eventDispatcher->dispatch(
                        event: new BeforeMessageHandledEvent($this->server, $data, $fd),
                        eventName: ConveyorServer::EVENT_BEFORE_MESSAGE_HANDLED,
                    );

                    $this->executeConveyor($fd, $data);

                    $this->eventDispatcher->dispatch(
                        event: new AfterMessageHandledEvent($this->server, $data, $fd),
                        eventName: ConveyorServer::EVENT_AFTER_MESSAGE_HANDLED,
                    );
                }
            });

            $process->useQueue(
                $conveyorOptions[self::QUEUE_KEY] ?? 0,
                $conveyorOptions[self::QUEUE_MODE] ?? 2,
            );

            $this->server->addProcess($process);
            $this->processes[] = $process;
        }
    }

    private function executeConveyor(int $fd, string $data): void
    {
        $subProcess = new Process(function ($process) use ($fd, $data) {
            Conveyor::init()
                ->server($this->server)
                ->fd($fd)
                ->persistence()
                ->closeConnections()
                ->run($data)
                ->finalize(fn() => $process->write('done!'));
        });

        $subProcessPid = $subProcess->start();
        $subProcess->read();
        Process::kill($subProcessPid);
    }
}

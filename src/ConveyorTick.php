<?php

namespace Conveyor;

use Conveyor\Events\AfterMessageHandledEvent;
use Conveyor\Events\PostServerReloadEvent;
use Conveyor\Events\PreServerReloadEvent;
use Conveyor\Events\ServerStartedEvent;
use OpenSwoole\Table;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ConveyorTick
{
    public const TIME_TRACKER = 'time_tracker';

    protected Table $timerTracker;

    public function __construct(
        protected Server $server,
        protected ConveyorLock $conveyorLock,
        protected EventDispatcher $eventDispatcher,
        protected int $interval = 1000,
    ) {
        $this->prepareTimerTracker();
        $this->addListeners();
    }

    private function addListeners(): void
    {
        $this->eventDispatcher->addListener(
            eventName: ConveyorServer::EVENT_SERVER_STARTED,
            listener: fn(ServerStartedEvent $event) => $this->tick($event->server),
        );

        $this->eventDispatcher->addListener(
            eventName: ConveyorServer::EVENT_AFTER_MESSAGE_HANDLED,
            listener: fn(AfterMessageHandledEvent $event) => $this->reset(),
        );

        $this->eventDispatcher->addListener(
            eventName: ConveyorServer::EVENT_PRE_SERVER_RELOAD,
            listener: fn(PreServerReloadEvent $event) => $this->preServerReload(),
        );

        $this->eventDispatcher->addListener(
            eventName: ConveyorServer::EVENT_POST_SERVER_RELOAD,
            listener: fn(PostServerReloadEvent $event) => $this->postServerReload(),
        );
    }

    protected function prepareTimerTracker(): void
    {
        $this->timerTracker = new Table(10024);
        $this->timerTracker->column('value', Table::TYPE_INT, 20);
        $this->timerTracker->create();
    }

    public function reset(): void
    {
        $this->timerTracker->set(self::TIME_TRACKER, ['value' => time()]);
    }

    public function tick(Server $server): void
    {
        $server->tick($this->interval, function () use ($server) {
            $this->reloadServer($server);
        });
    }

    private function reloadServer(Server $server): void
    {
        $existing = $this->timerTracker->get(self::TIME_TRACKER, 'value');
        if (!$existing || (time() - $existing) < 3) {
            return;
        }

        if ($this->conveyorLock->isLocked() || $this->conveyorLock->serverIsLocked()) {
            return;
        }

        $this->eventDispatcher->dispatch(
            event: new PreServerReloadEvent($server),
            eventName: ConveyorServer::EVENT_PRE_SERVER_RELOAD,
        );

        $server->reload();

        $this->eventDispatcher->dispatch(
            event: new PostServerReloadEvent($server),
            eventName: ConveyorServer::EVENT_POST_SERVER_RELOAD,
        );
    }

    public function preServerReload(): void
    {
        $this->conveyorLock->lockServer();
    }

    public function postServerReload(): void
    {
        $this->conveyorLock->releaseServer();

        $this->timerTracker->del(self::TIME_TRACKER);
    }
}

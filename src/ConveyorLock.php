<?php

namespace Conveyor;

use Conveyor\Events\AfterMessageHandledEvent;
use Conveyor\Events\BeforeMessageHandledEvent;
use OpenSwoole\Atomic;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ConveyorLock
{
    protected Atomic $serverReloadLock;
    protected Atomic $conveyorLock;

    public function __construct(
        protected EventDispatcher $eventDispatcher,
    ) {
        $this->serverReloadLock = new Atomic(0);
        $this->conveyorLock = new Atomic(0);
        $this->addListeners();
    }

    private function addListeners(): void
    {
        $this->eventDispatcher->addListener(
            eventName: ConveyorServer::EVENT_BEFORE_MESSAGE_HANDLED,
            listener: fn(BeforeMessageHandledEvent $event) => $this->waitServer()->lock(),
        );

        $this->eventDispatcher->addListener(
            eventName: ConveyorServer::EVENT_AFTER_MESSAGE_HANDLED,
            listener: fn(AfterMessageHandledEvent $event) => $this->release(),
        );
    }

    public function waitServer(): self
    {
        while ($this->conveyorLock->get() > 0) {
            $this->conveyorLock->wait(1);
        }

        return $this;
    }

    public function lock(): self
    {
        $this->serverReloadLock->add(1);

        return $this;
    }

    public function lockServer(): self
    {
        $this->conveyorLock->add(1);

        return $this;
    }

    public function releaseServer(): self
    {
        $this->conveyorLock->sub(1);

        return $this;
    }

    public function release(): self
    {
        $this->serverReloadLock->sub(1);

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->conveyorLock->get() > 0;
    }

    public function serverIsLocked(): bool
    {
        return $this->serverReloadLock->get() > 0;
    }
}

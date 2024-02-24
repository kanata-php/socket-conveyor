<?php

namespace Conveyor\Actions\Traits;

use Conveyor\Persistence\Interfaces\ChannelPersistenceInterface;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\Persistence\Interfaces\ListenerPersistenceInterface;
use Conveyor\Persistence\Interfaces\MessageAcknowledgmentPersistenceInterface;
use Conveyor\Persistence\Interfaces\UserAssocPersistenceInterface;

trait HasPersistence
{
    public ?ChannelPersistenceInterface $channelPersistence = null;

    public ?ListenerPersistenceInterface $listenerPersistence = null;

    public ?UserAssocPersistenceInterface $userAssocPersistence = null;

    public ?MessageAcknowledgmentPersistenceInterface $messageAcknowledmentPersistence = null;

    /**
     * This procedure happens at the bootstrap of the Action, not during
     * the execution.
     *
     * @param GenericPersistenceInterface $persistence
     * @return void
     */
    public function setPersistence(GenericPersistenceInterface $persistence): void
    {
        switch (true) {
            case is_a($persistence, ChannelPersistenceInterface::class):
                $this->channelPersistence = $persistence;
                break;

            case is_a($persistence, ListenerPersistenceInterface::class):
                $this->listenerPersistence = $persistence;
                break;

            case is_a($persistence, UserAssocPersistenceInterface::class):
                $this->userAssocPersistence = $persistence;
                break;

            case is_a($persistence, MessageAcknowledgmentPersistenceInterface::class):
                $this->messageAcknowledmentPersistence = $persistence;
                break;
        }
    }
}

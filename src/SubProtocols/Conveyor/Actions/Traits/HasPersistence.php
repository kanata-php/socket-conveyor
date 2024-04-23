<?php

namespace Conveyor\SubProtocols\Conveyor\Actions\Traits;

use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\ChannelPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\MessageAcknowledgementPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\UserAssocPersistenceInterface;

trait HasPersistence
{
    public ?ChannelPersistenceInterface $channelPersistence = null;

    public ?UserAssocPersistenceInterface $userAssocPersistence = null;

    public ?MessageAcknowledgementPersistenceInterface $messageAcknowledmentPersistence = null;

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

            case is_a($persistence, UserAssocPersistenceInterface::class):
                $this->userAssocPersistence = $persistence;
                break;

            case is_a($persistence, MessageAcknowledgementPersistenceInterface::class):
                $this->messageAcknowledmentPersistence = $persistence;
                break;
        }
    }
}

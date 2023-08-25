<?php

namespace Conveyor\Actions\Traits;

use Conveyor\Models\Interfaces\ChannelPersistenceInterface;
use Conveyor\Models\Interfaces\GenericPersistenceInterface;
use Conveyor\Models\Interfaces\ListenerPersistenceInterface;
use Conveyor\Models\Interfaces\UserAssocPersistenceInterface;

trait HasPersistence
{
    // protected ?ChannelPersistenceInterface $channelPersistence = null;
    public ?ChannelPersistenceInterface $channelPersistence = null;

    protected ?ListenerPersistenceInterface $listenerPersistence = null;

    protected ?UserAssocPersistenceInterface $userAssocPersistence = null;

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
                $this->channelPersistence = $persistence->refresh($this->fresh);
                break;

            case is_a($persistence, ListenerPersistenceInterface::class):
                $this->listenerPersistence = $persistence->refresh($this->fresh);
                break;

            case is_a($persistence, UserAssocPersistenceInterface::class):
                $this->userAssocPersistence = $persistence->refresh($this->fresh);
                break;
        }
    }
}

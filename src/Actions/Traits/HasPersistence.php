<?php

namespace Conveyor\Actions\Traits;

use Conveyor\SocketHandlers\Interfaces\ChannelPersistenceInterface;
use Conveyor\SocketHandlers\Interfaces\GenericPersistenceInterface;
use Conveyor\SocketHandlers\Interfaces\ListenerPersistenceInterface;
use Conveyor\SocketHandlers\Interfaces\UserAssocPersistenceInterface;

trait HasPersistence
{
    protected ?ChannelPersistenceInterface $channelPersistence = null;

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
                $this->channelPersistence = $persistence;
                if ($this->fresh) {
                    $this->channelPersistence->refresh();
                }
                break;

            case is_a($persistence, ListenerPersistenceInterface::class):
                $this->listenerPersistence = $persistence;
                if ($this->fresh) {
                    $this->listenerPersistence->refresh();
                }
                break;

            case is_a($persistence, UserAssocPersistenceInterface::class):
                $this->userAssocPersistence = $persistence;
                if ($this->fresh) {
                    $this->userAssocPersistence->refresh();
                }
                break;
        }
    }
}

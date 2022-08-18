<?php

namespace Conveyor\Actions\Traits;

use Conveyor\SocketHandlers\Interfaces\ChannelPersistenceInterface;
use Conveyor\SocketHandlers\Interfaces\GenericPersistenceInterface;
use Conveyor\SocketHandlers\Interfaces\ListenerPersistenceInterface;
use Conveyor\SocketHandlers\Interfaces\PersistenceInterface;
use Conveyor\SocketHandlers\Interfaces\UserAssocPersistenceInterface;

trait HasPersistence
{
    /**
     * @var GenericPersistenceInterface
     * @deprecated 2.0.0 This property will be removed. Instead, use specific persistence classes.
     */
    protected ?PersistenceInterface $persistence = null;

    protected ?ChannelPersistenceInterface $channelPersistence = null;

    protected ?ListenerPersistenceInterface $listenerPersistence = null;

    protected ?UserAssocPersistenceInterface $userAssocPersistence = null;

    public function setPersistence(GenericPersistenceInterface $persistence): void {
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

            case is_a($persistence, PersistenceInterface::class):
                $this->persistence = $persistence;
                break;
        }
    }
}
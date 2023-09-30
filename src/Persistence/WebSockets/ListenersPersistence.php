<?php

namespace Conveyor\Persistence\WebSockets;

use Conveyor\Models\WsListener;
use Conveyor\Persistence\Abstracts\GenericPersistence;
use Conveyor\Persistence\DatabaseBootstrap;
use Conveyor\Persistence\Interfaces\ListenerPersistenceInterface;
use Error;
use Exception;

class ListenersPersistence extends GenericPersistence implements ListenerPersistenceInterface
{
    public function listen(int $fd, string $action): void
    {
        try {
            WsListener::create([
                'fd' => $fd,
                'action' => $action,
            ]);
        } catch (Exception|Error $e) {
            // --
        }
    }

    public function getListener(int $fd): ?array
    {
        $listener = WsListener::where('fd', '=', $fd)->get()->toArray();
        return empty($listener) ? null : $listener;
    }

    /**
     * @return array Format: [fd => [listener1, listener2, ...]]
     */
    public function getAllListeners(): array
    {
        try {
            $listeners = WsListener::all()->toArray();
        } catch (Exception|Error $e) {
            return [];
        }

        if (empty($listeners)) {
            return [];
        }

        $listenersArray = [];
        foreach ($listeners as $listener) {
            if (!isset($listenersArray[$listener['fd']])) {
                $listenersArray[$listener['fd']] = [];
            }

            if (!in_array($listener['action'], $listenersArray[$listener['fd']])) {
                $listenersArray[$listener['fd']][] = $listener['action'];
            }
        }

        return $listenersArray;
    }

    public function stopListener(int $fd, string $action): bool
    {
        try {
            return WsListener::where('fd', '=', $fd)
                ->where('action', '=', $action)
                ->first()
                ?->delete();
        } catch (Exception|Error $e) {
            // --
        }

        return false;
    }

    public function stopListenersForFd(int $fd): bool
    {
        try {
            return WsListener::where('fd', '=', $fd)
                ->first()
                ?->delete();
        } catch (Exception|Error $e) {
            // --
        }

        return false;
    }

    public function cleanListeners(): bool
    {
        try {
            return WsListener::all()->delete();
        } catch (Exception|Error $e) {
            // --
        }

        return false;
    }

    /**
     * @throws Exception
     */
    public function refresh(bool $fresh = false): static
    {
        /** @throws Exception */
        (new DatabaseBootstrap($this->databaseOptions))->migrateListenerPersistence($fresh);

        if (!$fresh) {
            return $this;
        }

        WsListener::truncate();
        return $this;
    }
}

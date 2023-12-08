<?php

namespace Conveyor\Actions\Traits;

trait HasListener
{
    use HasPersistence;

    protected function isListeningAnyAction(int $fd): bool
    {
        return null !== $this->listenerPersistence
            && null !== $this->listenerPersistence->getListener($fd);
    }

    /**
     * Get listeners for the current listener persistence.
     *
     * @return array<array-key, int>|null Null if empty or not instantiated, array if listening.
     */
    protected function getListeners(): ?array
    {
        if (null !== $this->listenerPersistence) {
            $listeners = [];
            foreach ($this->listenerPersistence->getAllListeners() as $fd => $listened) {
                if ($fd === $this->fd || !in_array($this->getName(), $listened)) {
                    continue;
                }
                $listeners[] = $fd;
            }
            return empty($listeners) ? null : $listeners;
        }

        return null;
    }
}

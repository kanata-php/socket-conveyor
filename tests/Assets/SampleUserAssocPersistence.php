<?php

namespace Tests\Assets;

use Conveyor\Models\Interfaces\UserAssocPersistenceInterface;

class SampleUserAssocPersistence implements UserAssocPersistenceInterface
{
    protected array $associations = [];

    public function assoc(int $fd, int $userId): void
    {
        $this->associations[$fd] = $userId;
    }

    public function disassoc(int $fd): void
    {
        unset($this->associations[$fd]);
    }

    public function getAssoc(int $fd): ?int
    {
        if (!isset($this->associations[$fd])) {
            return null;
        }

        return $this->associations[$fd];
    }

    public function getAllAssocs(): array
    {
        return $this->associations;
    }

    public function refresh(bool $fresh = false): static
    {
        if ($fresh) {
            $this->associations = [];
        }

        return $this;
    }
}

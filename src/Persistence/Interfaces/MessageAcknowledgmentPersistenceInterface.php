<?php

namespace Conveyor\Persistence\Interfaces;

interface MessageAcknowledgmentPersistenceInterface extends GenericPersistenceInterface
{
    public function register(string $messageHash, int $count): void;

    public function subtract(string $messageHash): void;

    public function has(string $messageHash): bool;

    public function acknowledge(string $messageHash): void;
}

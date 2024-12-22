<?php

namespace Conveyor\SubProtocols\Conveyor\Persistence\Interfaces;

interface AuthTokenPersistenceInterface extends GenericPersistenceInterface
{
    public function storeToken(string $token, string $channel): void;

    /**
     * Get token record.
     *
     * @return array<array-key, string> Format: {channel: string}
     */
    public function byToken(string $token): array|false;

    /**
     * Remove token record.
     */
    public function consume(string $token): void;
}

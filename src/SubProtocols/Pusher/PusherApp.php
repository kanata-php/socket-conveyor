<?php

namespace Conveyor\SubProtocols\Pusher;

class PusherApp
{
    public function __construct(
        public readonly string $appId,
        public readonly string $key,
        public readonly string $secret,
        public readonly bool $enableClientMessages = false,
        public readonly bool $enabled = true,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): PusherApp
    {
        return new self(
            appId: (string) $data['app_id'],
            key: (string) $data['key'],
            secret: (string) $data['secret'],
            enableClientMessages: (bool) ($data['enable_client_messages'] ?? false),
            enabled: (bool) ($data['enabled'] ?? true),
        );
    }
}

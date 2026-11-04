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
        if ($appId === '' || $key === '' || $secret === '' || strlen($key) > 64) {
            throw new \InvalidArgumentException('Invalid Pusher app configuration');
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): PusherApp
    {
        foreach (['app_id', 'key', 'secret'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new \InvalidArgumentException('Invalid Pusher app configuration');
            }
        }
        foreach (['enable_client_messages', 'enabled'] as $field) {
            if (array_key_exists($field, $data) && !is_bool($data[$field])) {
                throw new \InvalidArgumentException('Invalid Pusher app configuration');
            }
        }
        return new self(
            appId: $data['app_id'],
            key: $data['key'],
            secret: $data['secret'],
            enableClientMessages: ($data['enable_client_messages'] ?? false),
            enabled: ($data['enabled'] ?? true),
        );
    }
}

<?php

namespace Conveyor\SubProtocols\Pusher;

final class PusherValidation
{
    public static function channel(mixed $value): string
    {
        if (
            !is_string($value) || strlen($value) > 164
            || preg_match('/\A[A-Za-z0-9_=@,.;-]+\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid channel');
        }
        return $value;
    }

    public static function event(mixed $value, bool $rest = false): string
    {
        if (
            !is_string($value) || $value === '' || strlen($value) > 200
            || preg_match('//u', $value) !== 1 || preg_match('/\p{Cc}/u', $value)
            || ($rest && (str_starts_with($value, 'pusher:') || str_starts_with($value, 'pusher_internal:')))
        ) {
            throw new \InvalidArgumentException('Invalid event');
        }
        return $value;
    }

    public static function data(mixed $value): string
    {
        if (!is_string($value) && !is_array($value) && !is_object($value)) {
            throw new \InvalidArgumentException('Invalid event data');
        }
        $data = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR, 64);
        if (strlen($data) > 10000 || preg_match('//u', $data) !== 1) {
            throw new \InvalidArgumentException('Invalid event data');
        }
        return $data;
    }

    /** @return array<string, mixed> */
    public static function object(string $raw): array
    {
        $value = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        if (!$value instanceof \stdClass) {
            throw new \InvalidArgumentException('Expected JSON object');
        }
        return (array) $value;
    }

    public static function auth(mixed $auth, string $key): string
    {
        if (
            !is_string($auth) || strlen($auth) !== strlen($key) + 65
            || !str_starts_with($auth, $key . ':') || !ctype_xdigit(substr($auth, strlen($key) + 1))
        ) {
            throw new \InvalidArgumentException('Invalid auth');
        }
        return $auth;
    }

    /** @return array<string, mixed> */
    public static function presence(mixed $raw): array
    {
        if (!is_string($raw) || strlen($raw) > 2048) {
            throw new \InvalidArgumentException('Invalid channel data');
        }
        $data = self::object($raw);
        $id = $data['user_id'] ?? null;
        if (
            (!is_int($id) && !is_string($id)) || (string) $id === '' || strlen((string) $id) > 128
            || preg_match('//u', (string) $id) !== 1 || preg_match('/\p{Cc}/u', (string) $id)
        ) {
            throw new \InvalidArgumentException('Invalid user id');
        }
        return $data;
    }

    public static function socketId(mixed $id): bool
    {
        return is_string($id) && strlen($id) <= 39 && preg_match('/\A[0-9]+\.[0-9]+\z/D', $id) === 1;
    }

    public static function scope(string $app, string $channel): string
    {
        return hash('sha256', json_encode([$app, $channel], JSON_THROW_ON_ERROR));
    }
}

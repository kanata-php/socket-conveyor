<?php

namespace Conveyor\SubProtocols\Pusher\Frame;

/**
 * Pure parser/encoder for Pusher protocol wire frames.
 *
 * The defining quirk of the Pusher protocol is that the outer frame is a JSON
 * object whose `data` field is itself a JSON **string** (double-encoded) on the
 * wire. Inbound, however, some clients send `data` as a nested object instead
 * of a string, so {@see PusherFrame::dataArray()} normalises both forms.
 *
 * Stateless by design — no OpenSwoole or persistence dependency — so it can be
 * unit-tested in isolation and reused by the router.
 */
final class PusherFrame
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Decode the outer wire frame into an assoc array.
     *
     * Returns an empty array for malformed JSON or a non-object payload so the
     * caller can safely inspect `$frame['event'] ?? null` without try/catch.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Return the frame's `data` payload as an assoc array regardless of whether
     * it arrived as a double-encoded JSON string or an already-nested object.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    public static function dataArray(array $decoded): array
    {
        $data = $decoded['data'] ?? null;

        if (is_string($data)) {
            $inner = json_decode($data, true);

            return is_array($inner) ? $inner : [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Build an outbound frame with `data` stringified (double-encoded).
     *
     * If $data is already a string it is used verbatim as the stringified
     * `data` value (no double-stringify). `channel` is included only when set.
     */
    public static function encode(string $event, mixed $data, ?string $channel = null): string
    {
        $frame = ['event' => $event];

        if ($channel !== null) {
            $frame['channel'] = $channel;
        }

        $frame['data'] = self::stringify($data);

        $encoded = json_encode($frame, self::JSON_FLAGS);

        return $encoded === false ? '{"event":"' . $event . '","data":"{}"}' : $encoded;
    }

    public static function connectionEstablished(string $socketId, int $activityTimeout = 120): string
    {
        return self::encode(PusherEvent::CONNECTION_ESTABLISHED, [
            'socket_id' => $socketId,
            'activity_timeout' => $activityTimeout,
        ]);
    }

    /**
     * Acknowledge a subscription.
     *
     * Non-presence channels carry an empty `data` (`"{}"`). Presence channels
     * carry the roster nested under `presence`; the roster math
     * (count/ids/hash) is computed upstream by the router and handed here as a
     * ready array — this codec only stringifies it.
     *
     * @param array<string, mixed>|null $presenceRoster
     */
    public static function subscriptionSucceeded(string $channel, ?array $presenceRoster = null): string
    {
        // Empty array and null both mean "no roster" -> data must be `{}` (an
        // empty object), which an empty PHP array would not produce.
        $data = ($presenceRoster === null || $presenceRoster === [])
            ? (object) []
            : ['presence' => $presenceRoster];

        return self::encode(PusherEvent::SUBSCRIPTION_SUCCEEDED, $data, $channel);
    }

    public static function error(int $code, string $message): string
    {
        return self::encode(PusherEvent::ERROR, [
            'code' => $code,
            'message' => $message,
        ]);
    }

    public static function pong(): string
    {
        return self::encode(PusherEvent::PONG, (object) []);
    }

    public static function memberAdded(string $channel, int|string $userId, mixed $userInfo): string
    {
        return self::encode(PusherEvent::MEMBER_ADDED, [
            'user_id' => $userId,
            'user_info' => $userInfo,
        ], $channel);
    }

    public static function memberRemoved(string $channel, int|string $userId): string
    {
        return self::encode(PusherEvent::MEMBER_REMOVED, [
            'user_id' => $userId,
        ], $channel);
    }

    /**
     * Reduce any payload to the stringified form Pusher expects for `data`.
     *
     * A string passes through unchanged so callers can supply a pre-encoded
     * body without it being double-stringified.
     */
    private static function stringify(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }

        $encoded = json_encode($data, self::JSON_FLAGS);

        return $encoded === false ? '{}' : $encoded;
    }
}

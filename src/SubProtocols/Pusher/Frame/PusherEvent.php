<?php

namespace Conveyor\SubProtocols\Pusher\Frame;

/**
 * Canonical Pusher protocol event names and connection error codes.
 *
 * Grouped here so the frame codec and the message router share one source of
 * truth for the wire vocabulary.
 */
final class PusherEvent
{
    public const CONNECTION_ESTABLISHED = 'pusher:connection_established';
    public const SUBSCRIPTION_SUCCEEDED = 'pusher_internal:subscription_succeeded';
    public const MEMBER_ADDED = 'pusher_internal:member_added';
    public const MEMBER_REMOVED = 'pusher_internal:member_removed';
    public const ERROR = 'pusher:error';
    public const PING = 'pusher:ping';
    public const PONG = 'pusher:pong';
    public const SUBSCRIBE = 'pusher:subscribe';
    public const UNSUBSCRIBE = 'pusher:unsubscribe';

    /** Application does not exist (unknown app key). 4000-range: do not reconnect. */
    public const ERROR_APP_NOT_FOUND = 4001;

    /** Connection is unauthorized (e.g. invalid channel auth signature). */
    public const ERROR_UNAUTHORIZED = 4009;

    /** Over capacity — client should reconnect with backoff. */
    public const ERROR_OVER_CAPACITY = 4100;

    /** Generic error — client should reconnect immediately. */
    public const ERROR_GENERIC = 4200;
}

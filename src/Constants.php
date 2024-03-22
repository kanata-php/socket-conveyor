<?php

namespace Conveyor;

class Constants
{
    // Conveyor Options

    /**
     * Description: Default options for Conveyor.
     * @var array<array-key, mixed>
     */
    public const DEFAULT_OPTIONS = [
        self::TRACK_PROFILE => false,
        self::USE_PRESENCE => false,
        self::TIMER_TICK => false,
        self::USE_ACKNOWLEDGMENT => false,
        self::USE_MESSAGE_SUB_PROCESS => false,
        self::ACKNOWLEDGMENT_ATTEMPTS => 3,
        self::ACKNOWLEDGMENT_TIMOUT => 0.5,
    ];

    /**
     * Description: Enable the timer reloads the server every 60 seconds
     *              when there is nobody connected, just to refresh the
     *              server's memory.
     * Expected value: <bool>
     * Default: false
     */
    public const TIMER_TICK = 'option.timer';

    /**
     * Description: Enable the presence feature, which will broadcast
     *              presence messages to all clients connected to a channel.
     * Expected value: <bool>
     * Default: false
     */
    public const USE_PRESENCE = 'option.use_presence';

    /**
     * Description: Enable the tracking of messages resources profile,
     *              which will log the memory and time usage of each message.
     * Expected value: <bool>
     * Default: false
     */
    public const TRACK_PROFILE = 'option.track_profile';

    /**
     * Description: Enable the acknowledgment feature, which will send
     *              acknowledgment messages to the client after a message
     *              has been received and processed.
     * Expected value: <bool>
     * Default: false
     */
    public const USE_ACKNOWLEDGMENT = 'option.use_acknowledgment';

    /**
     * Description: Enable the use of sub-processes to handle messages.
     * Expected value: <bool>
     * Default: false
     */
    public const USE_MESSAGE_SUB_PROCESS = 'option.use_sub_process';

    /**
     * Description: The number of attempts to send an acknowledgment message.
     * Expected value: <int>
     * Default: 3
     */
    public const ACKNOWLEDGMENT_ATTEMPTS = 'option.acknowledgment_attempts';

    /**
     * Description: The time in seconds to wait for an acknowledgment message.
     * Expected value: <int>
     * Default: 3
     */
    public const ACKNOWLEDGMENT_TIMOUT = 'option.acknowledgment_timeout';

    /**
     * Description: Actions registered in Conveyor. This can replace existing
     *              actions or add new ones.
     * Expected value: <array-key, ActionInterface>
     */
    public const ACTIONS = 'actions';

    // Filters

    public const FILTER_PRESENCE_MESSAGE_CONNECT = 'filter.presence_message.connect';
    public const FILTER_PRESENCE_MESSAGE_DISCONNECT = 'filter.presence_message.disconnect';
    public const FILTER_ACTION_PUSH_MESSAGE = 'filter.action.push_message';

    // Events

    public const EVENT_PRE_SERVER_START = 'conveyor.pre_server_start';
    public const EVENT_SERVER_STARTED = 'conveyor.server_started';
    public const EVENT_PRE_SERVER_RELOAD = 'conveyor.pre_server_reload';
    public const EVENT_POST_SERVER_RELOAD = 'conveyor.post_server_reload';
    public const EVENT_MESSAGE_RECEIVED = 'conveyor.message_received';
    public const EVENT_BEFORE_MESSAGE_HANDLED = 'conveyor.before_message_handled';
    public const EVENT_AFTER_MESSAGE_HANDLED = 'conveyor.after_message_handled';

    // Action Events

    public const ACTION_EVENT_CHANNEL_PRESENCE = 'channel-presence';

    // Reactive Actions

    public const ACTION_CONNECTION_INFO = 'connection-info';
}

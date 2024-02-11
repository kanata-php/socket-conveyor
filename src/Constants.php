<?php

namespace Conveyor;

class Constants
{
    // Conveyor Options

    public const TIMER_TICK = 'option.timer';
    public const USE_PRESENCE = 'option.use_presence';

    public const TRACK_PROFILE = 'option.track_profile';

    /**
     * Expected value: <array-key, ActionInterface>
     */
    public const ACTIONS = 'actions';

    // Filters

    public const FILTER_PRESENCE_MESSAGE_CONNECT = 'filter.presence_message.connect';
    public const FILTER_PRESENCE_MESSAGE_DISCONNECT = 'filter.presence_message.disconnect';

    // Events

    public const EVENT_PRE_SERVER_START = 'conveyor.pre_server_start';
    public const EVENT_SERVER_STARTED = 'conveyor.server_started';
    public const EVENT_PRE_SERVER_RELOAD = 'conveyor.pre_server_reload';
    public const EVENT_POST_SERVER_RELOAD = 'conveyor.post_server_reload';
    public const EVENT_MESSAGE_RECEIVED = 'conveyor.message_received';
    public const EVENT_BEFORE_MESSAGE_HANDLED = 'conveyor.before_message_handled';
    public const EVENT_AFTER_MESSAGE_HANDLED = 'conveyor.after_message_handled';
}

<?php

namespace Conveyor\Traits\Constants;

trait Filters
{
    // Filters

    public const FILTER_PRESENCE_MESSAGE_CONNECT = 'filter.presence_message.connect';
    public const FILTER_PRESENCE_MESSAGE_DISCONNECT = 'filter.presence_message.disconnect';
    public const FILTER_PUSH_MESSAGE = 'filter.push_message';
    public const FILTER_REQUEST_HANDLER = 'filter.request_handler';

    // Actions

    public const ACTION_AFTER_PUSH_MESSAGE = 'action.after_push_message';
}

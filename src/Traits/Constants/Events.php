<?php

namespace Conveyor\Traits\Constants;

trait Events
{
    public const EVENT_PRE_SERVER_START = 'conveyor.pre_server_start';
    public const EVENT_SERVER_STARTED = 'conveyor.server_started';
    public const EVENT_MESSAGE_RECEIVED = 'conveyor.message_received';
    public const EVENT_REQUEST_RECEIVED = 'conveyor.request_received';
    public const EVENT_TASK_FINISHED = 'conveyor.task_finished';
    public const EVENT_SERVER_CLOSE = 'conveyor.server_close';
    public const EVENT_BEFORE_MESSAGE_HANDLED = 'conveyor.before_message_handled';
    public const EVENT_AFTER_MESSAGE_HANDLED = 'conveyor.after_message_handled';
}

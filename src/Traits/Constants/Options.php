<?php

namespace Conveyor\Traits\Constants;

trait Options
{
    /**
     * Description: The subprotocol to use for the WebSocket server.
     * Expected value: <string>
     * Default: 'socketconveyor.com'
     */
    public const WEBSOCKET_SUBPROTOCOL = 'option.websocket_subprotocol';

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

    /**
     * Description: The URL to use for websocket authentication. This is
     *              important for protected websocket channels.
     * Expected value: <string>
     * Default: null
     * Example: (for laravel broadcast case) 'http://localhost:8080/api/broadcasting/auth'
     */
    public const WEBSOCKET_AUTH_URL = 'option.websocket_auth_url';

    /**
     * Description: The Token to be used in the websocket authentication URL.
     * Expected value: <string>
     * Default: null
     */
    public const WEBSOCKET_AUTH_TOKEN = 'option.websocket_auth_token';

    /**
     * Description: The Token to be used to open a connection with the websocket server.
     * Expected value: <string>
     * Default: null
     */
    public const WEBSOCKET_SERVER_TOKEN = 'option.websocket_server_token';
}

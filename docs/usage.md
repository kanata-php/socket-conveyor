# Socket Conveyor Usage Guide

Socket Conveyor is a PHP 8.2+ WebSocket message router built on OpenSwoole.
It can run in two modes:

- Native Conveyor protocol, where clients send Conveyor action messages.
- Pusher/Reverb-compatible protocol, where Laravel, Laravel Echo, and
  `pusher-js` use the normal Pusher wire format and REST broadcast API.

## Installation

Install the package with Composer:

```bash
composer require kanata-php/socket-conveyor
```

For local development in this repository, install dependencies from the project
root:

```bash
composer install
```

## Minimum Requirements

- PHP 8.2 or newer.
- `ext-openswoole` version 22.0 or newer.
- Composer.
- A host and port that the WebSocket server can bind to.

The default Conveyor server host is `0.0.0.0` and the default port is `8989`.

## Starting A Conveyor server

Create a small PHP entrypoint, for example `server.php`:

```php
<?php

use Conveyor\Constants;
use Conveyor\ConveyorServer;

require __DIR__ . '/vendor/autoload.php';

(new ConveyorServer())
    ->host('127.0.0.1')
    ->port(8989)
    ->serverOptions([
        'worker_num' => 1,
        'task_worker_num' => 1,
    ])
    ->conveyorOptions([
        Constants::WEBSOCKET_SUBPROTOCOL => Constants::SOCKET_CONVEYOR,
        Constants::USE_PRESENCE => false,
        Constants::USE_ACKNOWLEDGMENT => false,
    ])
    ->start();
```

Run it:

```bash
php server.php
```

`ConveyorServer::start()` initializes default persistence tables, creates an
OpenSwoole WebSocket server, selects the configured subprotocol, and starts the
server.

## Native Conveyor protocol

Native mode is selected by default with:

```php
Constants::WEBSOCKET_SUBPROTOCOL => Constants::SOCKET_CONVEYOR
```

The subprotocol value is `socketconveyor.com`.

On a successful native WebSocket handshake, Conveyor sends a connection info
message:

```json
{
  "action": "connection-info",
  "data": "{\"fd\":1,\"event\":\"connection-info\"}"
}
```

If acknowledgment is enabled, this connection info message also includes an
`id`.

Clients send JSON action messages. Every action message must include `action`.
Most actions also require action-specific fields.

### Built-In Native Actions

`base-action` echoes a message back to the current socket:

```json
{
  "action": "base-action",
  "data": "hello"
}
```

Response:

```json
{
  "action": "base-action",
  "data": "hello",
  "fd": 1
}
```

`fanout-action` sends `data` to every established socket:

```json
{
  "action": "fanout-action",
  "data": "hello everyone"
}
```

If `Constants::WEBSOCKET_SERVER_TOKEN` is configured, `fanout-action` also
requires `auth` with that server token.

`channel-connect` connects the current socket fd to a single channel:

```json
{
  "action": "channel-connect",
  "channel": "orders.1"
}
```

If `Constants::WEBSOCKET_SERVER_TOKEN` is configured, include either the server
token or a channel-scoped temporary auth token:

```json
{
  "action": "channel-connect",
  "channel": "orders.1",
  "auth": "your-token"
}
```

`broadcast-action` sends `data` to the current channel. If the sender is
connected to a channel, Conveyor broadcasts to other sockets in that same
channel. If the sender is not connected to a channel, Conveyor broadcasts to
sockets that are not in channels.

```json
{
  "action": "broadcast-action",
  "data": "order updated"
}
```

`channel-disconnect` removes the current socket fd from its channel:

```json
{
  "action": "channel-disconnect"
}
```

`acknowledge-action` is used by clients to acknowledge receipt of a server
message when acknowledgment is enabled:

```json
{
  "action": "acknowledge-action",
  "data": "message-id"
}
```

### Custom Native Actions

Add custom action handlers through `Constants::ACTIONS`. Each action must
implement `ActionInterface`.

```php
use Conveyor\Constants;
use Conveyor\ConveyorServer;
use Tests\Assets\SampleAction;

(new ConveyorServer())
    ->port(8989)
    ->conveyorOptions([
        Constants::ACTIONS => [
            new SampleAction(),
        ],
    ])
    ->start();
```

An action implements:

```php
public function execute(array $data): mixed;
public function send(string $data, ?int $fd = null, bool $toChannel = false): void;
public function getName(): string;
```

`AbstractAction` validates the base `action` field, runs action-specific
validation, performs optional acknowledgment, then calls `execute()`.

## Channel Connect And Broadcast Flow

In native mode, a normal channel flow is:

1. Client connects to the WebSocket server.
2. Server sends `connection-info`.
3. Client sends `channel-connect` with a `channel`.
4. Server records the fd-to-channel association.
5. Client sends `broadcast-action` with `data`.
6. Server wraps outbound data as `{ "action": "broadcast-action", "data": ..., "fd": senderFd }`.
7. Server delivers the message to other established sockets in the same channel.

Example client messages:

```json
{
  "action": "channel-connect",
  "channel": "chat.room.1"
}
```

```json
{
  "action": "broadcast-action",
  "data": {
    "body": "hello"
  }
}
```

## Presence, Acknowledgment, And Server Token Options

Enable native presence with:

```php
Constants::USE_PRESENCE => true
```

When a socket connects to or disconnects from a channel, Conveyor broadcasts a
presence payload to that channel. The presence event name is
`channel-presence`.

A presence message is sent inside the normal action envelope:

```json
{
  "action": "channel-connect",
  "data": "{\"event\":\"channel-presence\",\"channel\":\"chat.room.1\",\"fds\":[1]}",
  "fd": 1
}
```

The presence payload can be customized with the
`Constants::FILTER_PRESENCE_MESSAGE_CONNECT` and
`Constants::FILTER_PRESENCE_MESSAGE_DISCONNECT` filters.

Enable native acknowledgment with:

```php
Constants::USE_ACKNOWLEDGMENT => true,
Constants::ACKNOWLEDGMENT_ATTEMPTS => 3,
Constants::ACKNOWLEDGMENT_TIMOUT => 0.5,
```

When acknowledgment is enabled, outgoing pushed messages receive an `id`.
Clients should reply with:

```json
{
  "action": "acknowledge-action",
  "data": "the-message-id"
}
```

Protect native WebSocket handshakes and protected actions with:

```php
Constants::WEBSOCKET_SERVER_TOKEN => 'local-server-token'
```

When this option is set, native WebSocket clients must connect with:

```text
ws://127.0.0.1:8989?token=local-server-token
```

`fanout-action` requires `auth` to equal the server token. `channel-connect`
accepts either the server token or a temporary channel token created by the
native auth endpoint.

## Native HTTP Endpoints

Native mode also handles HTTP requests on the same OpenSwoole server.

Create a temporary channel auth token:

```bash
curl -X POST "http://127.0.0.1:8989/conveyor/auth?token=local-server-token" \
  -H "Content-Type: application/json" \
  -d '{"channel":"orders.1"}'
```

Response:

```json
{
  "auth": "temporary-token"
}
```

Use the returned `auth` value in a later `channel-connect` message for the same
channel. Temporary channel tokens are consumed after use.

Force a server-side broadcast to a native channel:

```bash
curl -X POST "http://127.0.0.1:8989/conveyor/message?token=local-server-token" \
  -H "Content-Type: application/json" \
  -d '{"channel":"orders.1","message":"order updated"}'
```

Successful response:

```json
{
  "success": "Message sent successfully!"
}
```

Common native endpoint errors:

- `401` with `Unauthorized!`: missing or invalid `token` when a server token is required.
- `400` with `Channels not enabled!`: channel persistence is unavailable.
- `404` with `Channel not found!`: no socket is currently connected to the requested channel.
- `400` with `Invalid message!`: the request body has no non-empty `message`.

## Pusher/Reverb-Compatible Mode

Use Pusher mode when you want Laravel's stock `reverb` or `pusher` broadcaster,
Laravel Echo, and `pusher-js` to talk to Conveyor.

```php
<?php

use Conveyor\Constants;
use Conveyor\ConveyorServer;

require __DIR__ . '/vendor/autoload.php';

(new ConveyorServer())
    ->host('127.0.0.1')
    ->port(8990)
    ->serverOptions([
        'worker_num' => 1,
        'task_worker_num' => 1,
    ])
    ->conveyorOptions([
        Constants::WEBSOCKET_SUBPROTOCOL => Constants::PUSHER,
        Constants::USE_PRESENCE => true,
        Constants::APPS => [[
            'app_id' => 'local-app',
            'key' => 'local-key',
            'secret' => 'local-secret',
            'enable_client_messages' => true,
            'enabled' => true,
        ]],
    ])
    ->start();
```

The Pusher subprotocol value is `pusher.com`.

WebSocket clients connect to:

```text
ws://127.0.0.1:8990/app/local-key
```

For known and enabled apps, Conveyor sends `pusher:connection_established` with
a `socket_id`. Unknown or disabled apps receive `pusher:error` code `4001` and
the socket is closed.

The included real-client smoke server uses this setup:

```bash
php examples/pusher-real/run-conveyor.php
```

Default local credentials:

```text
PUSHER_APP_ID=local-app
PUSHER_APP_KEY=local-key
PUSHER_APP_SECRET=local-secret
CONVEYOR_HOST=127.0.0.1
CONVEYOR_PORT=8990
```

## Laravel Echo Usage

Configure Laravel to use its built-in Reverb-style values:

```dotenv
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=local-app
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8990
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

If your Laravel app uses the `pusher` broadcaster, use equivalent `PUSHER_*`
values with the same app id, key, secret, host, port, and scheme.

Install Echo and the Pusher client:

```bash
npm install laravel-echo pusher-js
```

Browser setup:

```js
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

window.Echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: import.meta.env.VITE_REVERB_PORT,
  wssPort: import.meta.env.VITE_REVERB_PORT,
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
})
```

For private and presence channels, keep the WebSocket host pointed at Conveyor
but point Echo authorization at your Laravel HTTP application:

```dotenv
VITE_LARAVEL_URL=http://localhost:8000
```

```js
window.Echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: import.meta.env.VITE_REVERB_PORT,
  wssPort: import.meta.env.VITE_REVERB_PORT,
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: `${import.meta.env.VITE_LARAVEL_URL}/broadcasting/auth`,
  auth: {
    withCredentials: true,
  },
})
```

Use normal Echo APIs:

```js
window.Echo.channel('orders')
  .listen('.OrderShipped', event => {
    console.log(event)
  })

window.Echo.private('orders.1')
  .listen('.OrderUpdated', event => {
    console.log(event)
  })

window.Echo.join('room.1')
  .here(users => console.log('here', users))
  .joining(user => console.log('joining', user))
  .leaving(user => console.log('leaving', user))
  .listenForWhisper('typing', event => console.log('typing', event))
```

Laravel continues to own channel authorization in `routes/channels.php`:

```php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{orderId}', function ($user, int $orderId) {
    return true;
});

Broadcast::channel('room.{roomId}', function ($user, int $roomId) {
    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
```

Broadcast normally:

```php
broadcast(new OrderShipped($order))->toOthers();
```

Conveyor receives Laravel's signed REST publish request and honors `socket_id`
exclusion for `toOthers()`.

## Public, Private, And Presence Channels

Public channels do not require authorization. With Echo:

```js
window.Echo.channel('orders')
```

This subscribes to the Pusher channel name `orders`.

Private channels require a valid `/broadcasting/auth` response from Laravel.
With Echo:

```js
window.Echo.private('orders.1')
```

Echo subscribes to the Pusher channel name `private-orders.1`.

Presence channels also require `/broadcasting/auth`, and the auth response must
include `channel_data` with a `user_id` and optional `user_info`. With Echo:

```js
window.Echo.join('room.1')
```

Echo subscribes to the Pusher channel name `presence-room.1`.

In Pusher mode, Conveyor validates private and presence channel signatures
using the configured app key and secret. An invalid signature produces
`pusher:error` with unauthorized code `4009`.

If the browser shows a `403`, or posts to a frontend dev server such as
`http://localhost:8081/broadcasting/auth`, the client did not authorize and is
not subscribed. The auth request must reach Laravel because Laravel owns
`/broadcasting/auth`, `routes/channels.php`, and the authenticated user session.

## Pusher REST Broadcast Endpoints

Pusher/Reverb-compatible mode exposes signed REST endpoints on the same server.

Publish one event:

```text
POST /apps/{app_id}/events
```

Body:

```json
{
  "name": "OrderShipped",
  "channels": ["orders.1"],
  "data": "{\"id\":1,\"total\":99}",
  "socket_id": "123.456"
}
```

You may send either `channels` or a single `channel`. `data` must be a string
and is limited to 10000 bytes. `socket_id` is optional; when present, Conveyor
does not deliver the event to that socket.

Publish a batch:

```text
POST /apps/{app_id}/batch_events
```

Body:

```json
{
  "batch": [
    {
      "name": "OrderShipped",
      "channel": "orders.1",
      "data": "{\"id\":1}"
    }
  ]
}
```

Batches are limited to 10 events.

Inspect channels:

```text
GET /apps/{app_id}/channels
GET /apps/{app_id}/channels/{channel}
GET /apps/{app_id}/channels/{presence-channel}/users
```

REST requests must include Pusher-style signed query parameters:

- `auth_key`
- `auth_timestamp`
- `auth_version=1.0`
- `body_md5` for requests with a body
- `auth_signature`

The timestamp tolerance is 600 seconds. Bad app credentials, body hashes, or
signatures return `401`. Stale timestamps return `400`.

The example router signs a REST publish request with
`Conveyor\SubProtocols\Pusher\PusherSigner` in
`examples/pusher-real/router.php`.

## Local Smoke Testing

Run the Pusher-compatible smoke server from the repository root.

Terminal 1:

```bash
php examples/pusher-real/run-conveyor.php
```

Terminal 2:

```bash
php -S 127.0.0.1:8991 examples/pusher-real/router.php
```

Open the raw Pusher smoke page:

```text
http://127.0.0.1:8991
```

Open the Laravel Echo smoke page:

```text
http://127.0.0.1:8991/echo.html
```

Expected checks:

- The page reaches `connected` and shows a `socket_id`.
- Public, private, and presence subscriptions log `subscribed`.
- The Public, Private, and Presence buttons produce `DemoEvent` logs.
- `Public toOthers()` sends the current `socket_id`, so the current browser tab
  does not receive its own REST-triggered event.
- Opening a second tab and using the whisper button sends a client event to the
  other tab.

## Troubleshooting

`Class OpenSwoole\WebSocket\Server not found` or Composer reports a missing
extension: install and enable `ext-openswoole >=22.0` for the PHP binary running
the server.

The server does not start: check that the configured port is free. Native tests
use port `8989`; Pusher feature tests and the real smoke server use port
`8990`; the example HTTP router uses port `8991`.

Native WebSocket connection closes during handshake: if
`Constants::WEBSOCKET_SERVER_TOKEN` is set, connect with `?token=...`.

Native `channel-connect` fails with `Failed to connect to channel`: the `auth`
field did not match the server token or a valid temporary channel token for that
channel.

Native HTTP broadcast returns `Channel not found!`: at least one socket must be
connected to the requested channel before `/conveyor/message` can send to it.

Laravel Echo private or presence channels fail with `403`: Echo is sending
`/broadcasting/auth` to the wrong HTTP app, the Laravel session is missing, or
the channel callback in `routes/channels.php` denied access.

Pusher REST publish returns `401`: check `app_id`, app key, app secret,
`body_md5`, and `auth_signature`. The request path used to compute the
signature must exactly match the path being requested.

Pusher REST publish returns `400` with `Stale auth_timestamp`: make sure the
client machine clock is close to the server clock.

Events are published but the sender receives its own Laravel broadcast: pass
the current `socket_id` with the REST payload. Laravel's `toOthers()` does this
through the Pusher/Reverb broadcaster.

Client whispers do not work: Pusher client events must use an event name that
starts with `client-`, the app must have `enable_client_messages` set to true,
and another socket must be subscribed to the same channel.

## Testing

Run the full test suite:

```bash
composer test
```

Run PHPUnit directly:

```bash
vendor/bin/phpunit --testsuite All
```

Run the Pusher WebSocket feature tests:

```bash
vendor/bin/phpunit tests/Feature/PusherWebSocketTest.php
```

Run the native message router unit tests:

```bash
vendor/bin/phpunit tests/Unit/MessageRouterTest.php
```

Run static analysis:

```bash
composer phpstan
```

# Using Socket Conveyor with Laravel Echo

Socket Conveyor can run as a Pusher/Reverb-compatible server. In that mode,
Laravel applications do **not** need a custom Conveyor broadcaster package for
normal Laravel Echo usage. Use Laravel's built-in `reverb` or `pusher`
broadcast connection and point it at the Conveyor server.

## 1. Start Conveyor in Pusher mode

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

The included real-client smoke server uses the same setup:

```bash
php examples/pusher-real/run-conveyor.php
```

## 2. Configure Laravel

Use Laravel's built-in Reverb-style environment variables and point them at the
Conveyor host and port:

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

If your app uses the `pusher` connection instead of `reverb`, use equivalent
`PUSHER_*` values with the same app id, key, secret, host, port, and scheme.

## 3. Configure Laravel Echo

Install and use the stock clients:

```bash
npm install laravel-echo pusher-js
```

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

For private and presence channels, keep the WebSocket host pointed at Conveyor,
but point Echo's authorization endpoint at your Laravel HTTP application:

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

If the browser shows a `403` or posts to your frontend dev server, such as
`http://localhost:8081/broadcasting/auth`, the client did not authorize and is
not subscribed. The auth request must reach Laravel, because Laravel owns
`routes/channels.php` and the authenticated user session.

Then use normal Echo APIs:

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

## 4. Use Laravel broadcasting normally

Laravel keeps handling `/broadcasting/auth` for private and presence channels.
Your channel authorization callbacks stay in Laravel:

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

In Laravel 13, install broadcasting if your app has not already done so:

```bash
php artisan install:broadcasting
```

Then confirm Laravel registered the auth route:

```bash
php artisan route:list --name=broadcasting
```

Broadcast events normally:

```php
broadcast(new OrderShipped($order))->toOthers();
```

Conveyor receives Laravel's signed Pusher/Reverb REST publish request at
`/apps/{app_id}/events`, delivers the event to connected Echo clients, and
honors `socket_id` exclusion for `toOthers()`.

## 5. Verify with the real browser smoke

Terminal 1:

```bash
php examples/pusher-real/run-conveyor.php
```

Terminal 2:

```bash
php -S 127.0.0.1:8991 examples/pusher-real/router.php
```

Open the Laravel Echo smoke page:

```text
http://127.0.0.1:8991/echo.html
```

Expected results:

- the page reaches `connected` and shows a `socket_id`;
- `Echo.channel('public-demo')`, `Echo.private('demo')`, and
  `Echo.join('demo')` subscribe successfully;
- the Public, Private, and Presence buttons trigger REST broadcasts through
  Conveyor and log `DemoEvent` in the browser;
- `Public toOthers()` sends the current `socket_id`, so the current tab does
  not receive its own broadcast;
- opening a second tab and clicking the whisper button sends a client event to
  the other tab.

## When do you need conveyor-laravel-broadcaster?

For Pusher/Reverb-compatible Laravel Echo usage, you should not need a custom
Conveyor Laravel broadcaster. Laravel uses its built-in `reverb` or `pusher`
driver, while Conveyor behaves as the compatible WebSocket and REST broadcast
server.

The older custom broadcaster package is only relevant for applications that
intentionally use Conveyor's native protocol instead of Laravel's Pusher/Reverb
protocol.

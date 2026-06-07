
# Socket Conveyor

This package enables PHP 8.2+ applications to work with WebSocket messages
using a routing strategy. Add an action handler implementing `ActionInterface`
to the `SocketMessageRouter`, or run Conveyor in Pusher/Reverb-compatible mode
for Laravel Echo clients.

Socket Conveyor is built on [OpenSwoole](https://openswoole.com/). You can
find out more about using WebSockets with OpenSwoole
[here](https://www.youtube.com/watch?v=Vgw5Ibqc15k).

Built for PHP 8.2+.

## Documentation

- [Complete usage guide](docs/usage.md): installation, native Conveyor mode,
  Pusher/Reverb-compatible mode, Laravel Echo setup, HTTP endpoints, smoke
  testing, and troubleshooting.
- [Laravel Echo / Reverb compatibility guide](docs/laravel-echo-reverb-compatibility.md):
  the shortest path for using Conveyor with Laravel's built-in `reverb` or
  `pusher` broadcaster.
- [Real Pusher client smoke example](examples/pusher-real/README.md): local
  browser smoke test using `pusher-js` and Laravel Echo.
- [Project documentation site](https://socketconveyor.com).

## Using Conveyor as a Pusher/Reverb server

Conveyor can run in a Pusher-compatible mode for Laravel's stock `pusher` or
`reverb` broadcaster and the standard `pusher-js` / Laravel Echo client.

```php
use Conveyor\Constants;
use Conveyor\ConveyorServer;

(new ConveyorServer())
    ->port(8080)
    ->conveyorOptions([
        Constants::WEBSOCKET_SUBPROTOCOL => Constants::PUSHER,
        Constants::USE_PRESENCE => true,
        Constants::APPS => [[
            'app_id' => 'local',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'enable_client_messages' => true,
            'enabled' => true,
        ]],
    ])
    ->start();
```

Point Laravel at the Conveyor host and port with the normal Reverb/Pusher env
values:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

The Pusher mode accepts WebSocket clients at `/app/{key}` and exposes the
signed REST publish API at `/apps/{app_id}/events`,
`/apps/{app_id}/batch_events`, and the channel info endpoints under
`/apps/{app_id}/channels`.


# Socket Conveyor

This package enables you to work with socket messages using routing strategy. For that, you just add an Action Handler implementing the `ActionInterface` to the `SocketMessageRouter` and watch the magic happen!

As an example of how to accomplish that with PHP, you can use the [OpenSwoole](https://openswoole.com/). You can find out more how to use WebSockets with OpenSwoole [here](https://www.youtube.com/watch?v=Vgw5Ibqc15k).

Built for PHP8.2+.

See more at the [Documentation](https://socketconveyor.com).

For Laravel Echo / Reverb-compatible usage, see
[`docs/laravel-echo-reverb-compatibility.md`](docs/laravel-echo-reverb-compatibility.md).

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

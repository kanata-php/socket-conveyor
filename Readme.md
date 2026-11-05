# Socket Conveyor

A PHP WebSocket server and message router built on [OpenSwoole](https://openswoole.com/). Use its native action-based protocol for custom applications, or run its Pusher-compatible mode with Laravel Echo and Laravel's Reverb or Pusher broadcaster.

[Documentation](https://socketconveyor.com) · [Usage guide](docs/usage.md) · [Laravel Echo guide](docs/laravel-echo-reverb-compatibility.md) · [Docker Hub](https://hub.docker.com/r/lotharthesavior/socket-conveyor)

## What it does

- Routes native WebSocket messages to built-in or custom action handlers.
- Supports Pusher-compatible public, private and presence channels for Laravel Echo clients.
- Provides signed HTTP endpoints for publishing events and querying channels.
- Offers configurable resource limits and application isolation for Pusher deployments.
- Runs as a PHP library or as a standalone Docker server.

## Requirements

For the PHP library, you need PHP 8.2 or newer, Composer, and the OpenSwoole extension (version 22.0 or newer). The Docker image includes PHP and OpenSwoole.

```sh
composer require kanata-php/socket-conveyor
```

See the [usage guide](docs/usage.md) for a native server, protocol examples and configuration.

## Quick start with Docker

Export your existing `PUSHER_APP_ID`, `PUSHER_APP_KEY` and `PUSHER_APP_SECRET` in your shell, then run:

```sh
docker run --rm --init -p 127.0.0.1:8080:8080 \
  -e PUSHER_APP_ID -e PUSHER_APP_KEY -e PUSHER_APP_SECRET \
  lotharthesavior/socket-conveyor:2.6.1
```

The image runs a Pusher-compatible server. Match its credentials to your Laravel broadcasting configuration. The port above binds to localhost; use a properly configured reverse proxy and TLS for public access. See the [Docker guide](docker/README.md) for image tags, configuration and deployment details.

## Laravel Echo and Pusher compatibility

To configure a server from PHP instead of using the Docker launcher:

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

Set Laravel's Reverb or Pusher settings to the same application ID, key, secret, host and port. The server accepts WebSocket connections at `/app/{key}` and signed HTTP requests under `/apps/{app_id}`. Follow the [Laravel Echo guide](docs/laravel-echo-reverb-compatibility.md) for a complete client and authorization setup.

## Security and operations

Pusher mode validates inputs, isolates applications and applies configurable resource budgets; review [Pusher security and limits](docs/pusher-security.md) before deployment. The Docker health check reports TCP liveness; the [smoke test](docker/smoke.py) checks actual event delivery.

## More resources

- [Full usage guide](docs/usage.md), including native mode and troubleshooting
- [Real Pusher client example](examples/pusher-real/README.md), using Laravel Echo and `pusher-js`
- [Changelog](CHANGELOG.md)
- [MIT license](LICENSE.md)

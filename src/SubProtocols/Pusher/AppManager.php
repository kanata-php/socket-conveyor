<?php

namespace Conveyor\SubProtocols\Pusher;

use Conveyor\Config\ConveyorOptions;
use Conveyor\Constants;

class AppManager
{
    /**
     * @var array<array-key, PusherApp>
     */
    protected array $apps = [];

    public function __construct(ConveyorOptions $options)
    {
        /** @var array<array-key, array<array-key, mixed>> $apps */
        $apps = $options->{Constants::APPS} ?? [];

        foreach ($apps as $app) {
            $this->apps[] = PusherApp::fromArray($app);
        }
    }

    public function findByKey(string $key): ?PusherApp
    {
        foreach ($this->apps as $app) {
            if (hash_equals($app->key, $key)) {
                return $app;
            }
        }

        return null;
    }

    public function findByAppId(string $appId): ?PusherApp
    {
        foreach ($this->apps as $app) {
            if ($app->appId === $appId) {
                return $app;
            }
        }

        return null;
    }

    /**
     * @return array<array-key, PusherApp>
     */
    public function all(): array
    {
        return $this->apps;
    }
}

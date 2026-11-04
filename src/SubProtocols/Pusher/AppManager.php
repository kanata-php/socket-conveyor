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

        if (!is_array($apps) || count($apps) > 1000) {
            throw new \InvalidArgumentException('Invalid Pusher apps');
        }
        foreach ($apps as $config) {
            if (!is_array($config)) {
                throw new \InvalidArgumentException('Invalid Pusher app');
            }
            $app = PusherApp::fromArray($config);
            if ($this->findByKey($app->key) !== null || $this->findByAppId($app->appId) !== null) {
                throw new \InvalidArgumentException('Duplicate Pusher app');
            }
            $this->apps[] = $app;
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

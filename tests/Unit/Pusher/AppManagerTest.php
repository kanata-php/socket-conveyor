<?php

namespace Tests\Unit\Pusher;

use Conveyor\Config\ConveyorOptions;
use Conveyor\Constants;
use Conveyor\SubProtocols\Pusher\AppManager;
use Tests\TestCase;

class AppManagerTest extends TestCase
{
    /**
     * @return array<array-key, mixed>
     */
    private function twoAppConfig(): array
    {
        return [
            Constants::APPS => [
                [
                    'app_id' => '100',
                    'key' => 'key-one',
                    'secret' => 'secret-one',
                    'enable_client_messages' => true,
                ],
                [
                    'app_id' => '200',
                    'key' => 'key-two',
                    'secret' => 'secret-two',
                ],
            ],
        ];
    }

    public function testFindByKey()
    {
        $manager = new AppManager(ConveyorOptions::fromArray($this->twoAppConfig()));

        $app = $manager->findByKey('key-two');

        $this->assertNotNull($app);
        $this->assertEquals('200', $app->appId);
        $this->assertEquals('secret-two', $app->secret);
        $this->assertFalse($app->enableClientMessages);
        $this->assertTrue($app->enabled);
    }

    public function testFindByAppId()
    {
        $manager = new AppManager(ConveyorOptions::fromArray($this->twoAppConfig()));

        $app = $manager->findByAppId('100');

        $this->assertNotNull($app);
        $this->assertEquals('key-one', $app->key);
        $this->assertTrue($app->enableClientMessages);
    }

    public function testUnknownKeyReturnsNull()
    {
        $manager = new AppManager(ConveyorOptions::fromArray($this->twoAppConfig()));

        $this->assertNull($manager->findByKey('nope'));
        $this->assertNull($manager->findByAppId('999'));
    }

    public function testAllReturnsEveryApp()
    {
        $manager = new AppManager(ConveyorOptions::fromArray($this->twoAppConfig()));

        $this->assertCount(2, $manager->all());
    }

    public function testEmptyConfigYieldsNoApps()
    {
        $manager = new AppManager(ConveyorOptions::fromArray([]));

        $this->assertCount(0, $manager->all());
        $this->assertNull($manager->findByKey('key-one'));
    }
}

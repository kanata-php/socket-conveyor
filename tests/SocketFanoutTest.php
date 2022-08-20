<?php

namespace Tests;

use Conveyor\Actions\ChannelConnectAction;
use Conveyor\Actions\FanoutAction;
use InvalidArgumentException;
use Tests\Assets\SampleChannelPersistence;
use Tests\Assets\SampleSocketServer;

class SocketFanoutTest extends SocketHandlerTestCase
{
    public array $userKeys = [];

    public function testCanExecuteFanoutAction()
    {
        $message = 'some-message';
        $persistence = new SampleChannelPersistence;
        $server = new SampleSocketServer([$this, 'sampleCallback']);
        $server->connections = [1, 2, 3, 4, 5];

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $this->userKeys = []; // refresh userKeys

        $fanoutData = json_encode([
            'action' => FanoutAction::ACTION_NAME,
            'data' => $message,
        ]);
        ($socketRouter)($fanoutData, 1, $server);

        $this->assertCount(5, array_filter(
            $this->userKeys,
            fn($d) => $message === json_decode($d)->data
        ));
    }

    public function testCanExecuteFanoutActionRegardlessOfChannel()
    {
        $message = 'some-message';
        $persistence = new SampleChannelPersistence;
        $server = new SampleSocketServer([$this, 'sampleCallback']);
        $server->connections = [1, 2, 3, 4, 5];

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $this->userKeys = []; // refresh userKeys

        $channelData = json_encode([
            'action' => ChannelConnectAction::ACTION_NAME,
            'channel' => 'some-channel',
        ]);
        ($socketRouter)($channelData, 1, $server);

        $this->userKeys = []; // refresh userKeys

        $fanoutData = json_encode([
            'action' => FanoutAction::ACTION_NAME,
            'data' => $message,
        ]);
        ($socketRouter)($fanoutData, 1, $server);

        $this->assertCount(5, array_filter(
            $this->userKeys,
            fn($d) => $message === json_decode($d)->data
        ));
    }

    public function testCantExecuteFanoutActionWithoutData()
    {
        $this->expectException(InvalidArgumentException::class);

        $persistence = new SampleChannelPersistence;
        $server = new SampleSocketServer([$this, 'sampleCallback']);
        $server->connections = [1, 2, 3, 4, 5];

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $this->userKeys = []; // refresh userKeys

        $fanoutData = json_encode([
            'action' => FanoutAction::ACTION_NAME,
            // missing 'data'
        ]);
        ($socketRouter)($fanoutData, 1, $server);
    }

    public function sampleCallback(int $fd, string $data) {
        $this->userKeys[$fd] = $data;
    }
}

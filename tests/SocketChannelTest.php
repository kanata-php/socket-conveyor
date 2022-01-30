<?php

namespace Tests;

use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use stdClass;
use Tests\Assets\SampleAction;
use Tests\Assets\SamplePersistence;
use Tests\Assets\SampleSocketServer;
use Conveyor\SocketHandlers\SocketMessageRouter;

class SocketChannelTest extends SocketHandlerTestCase
{
    public array $userKeys = [];

    public function testCanAddHandlerForAction()
    {
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();
        
        $this->assertInstanceOf(SocketMessageRouter::class, $socketRouter);
        $this->assertInstanceOf(SampleAction::class, $sampleAction);
    }

    public function testCanExecuteChannelConnectAction()
    {
        $channelName = 'test-channel';
        $persistence = new SamplePersistence;

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $connectionData = json_encode([
            'action' => 'channel-connect',
            'channel' => $channelName,
        ]);
        $result = ($socketRouter)($connectionData, 1, new stdClass());

        $this->assertNull($result);
        $this->assertCount(1, $persistence->getAllConnections());
        $this->assertTrue(in_array($channelName, $persistence->getAllConnections()));
    }

    public function testCanBroadcastToChannel()
    {
        $channelName = 'test-channel';
        $persistence = new SamplePersistence;
        $server = new SampleSocketServer([$this, 'sampleCallback']);

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $connectionData = json_encode([
            'action' => 'channel-connect',
            'channel' => $channelName,
        ]);

        $this->checkSingleSend($socketRouter, $server);

        ($socketRouter)($connectionData, 1, $server); // connect fd 1
        ($socketRouter)($connectionData, 2, $server); // connect fd 2
        ($socketRouter)($connectionData, 3, $server); // connect fd 3

        $this->userKeys = []; // refresh userKeys

        $this->checkBroadcastSend($socketRouter, $server);
    }

    public function testCanListenToAction()
    {
        $channelName = 'test-channel';
        $channelName2 = 'test-channel-two';

        $broadcastActionName = 'sample-broadcast-action';
        $broadcastActionName2 = 'sample-broadcast-action-two';

        $persistence = new SamplePersistence;
        $server = new SampleSocketServer([$this, 'sampleCallback']);

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        // connect users to channels

        $connectionData = json_encode([
            'action' => 'channel-connect',
            'channel' => $channelName,
        ]);

        $connectionData2 = json_encode([
            'action' => 'channel-connect',
            'channel' => $channelName2,
        ]);

        ($socketRouter)($connectionData, 1, $server); // connect fd 1
        ($socketRouter)($connectionData, 2, $server); // connect fd 2
        ($socketRouter)($connectionData, 3, $server); // connect fd 3
        ($socketRouter)($connectionData2, 4, $server); // connect fd 4
        ($socketRouter)($connectionData2, 5, $server); // connect fd 5

        // add listeners

        $listenToActionData = json_encode([
            'action' => 'add-listener',
            'listener' => $broadcastActionName,
        ]);

        $listenToActionData2 = json_encode([
            'action' => 'add-listener',
            'listener' => $broadcastActionName2,
        ]);

        ($socketRouter)($listenToActionData, 1, $server); // connect fd 1
        ($socketRouter)($listenToActionData, 2, $server); // connect fd 2
        ($socketRouter)($listenToActionData2, 3, $server); // connect fd 3
        ($socketRouter)($listenToActionData2, 4, $server); // connect fd 4
        ($socketRouter)($listenToActionData2, 5, $server); // connect fd 5

        // test channel/listeners

        $this->validateActionsByChannelAndListener($server, $socketRouter);
    }

    public function sampleCallback(int $fd) {
        $this->userKeys[] = $fd;
    }

    private function checkSingleSend(
        SocketHandlerInterface $socketRouter,
        SampleSocketServer $server
    ) {
        $broadcastData = json_encode([
            'action' => 'sample-broadcast-action',
        ]);
        ($socketRouter)($broadcastData, 3, $server); // broadcast fd 3

        $this->assertTrue(!in_array(1, $this->userKeys));
        $this->assertTrue(!in_array(2, $this->userKeys));
        $this->assertTrue(in_array(3, $this->userKeys));
    }

    private function checkBroadcastSend(
        SocketHandlerInterface $socketRouter,
        SampleSocketServer $server
    ) {
        $broadcastData = json_encode([
            'action' => 'sample-broadcast-action',
        ]);
        ($socketRouter)($broadcastData, 1, $server); // broadcast to fds 1 and 2

        $this->assertTrue(!in_array(1, $this->userKeys));
        $this->assertTrue(in_array(2, $this->userKeys));
        $this->assertTrue(in_array(3, $this->userKeys));
    }

    private function validateActionsByChannelAndListener(SampleSocketServer $server, SocketMessageRouter $socketRouter)
    {
        $broadcastData = json_encode([
            'action' => 'sample-broadcast-action',
        ]);
        ($socketRouter)($broadcastData, 2, $server); // broadcast fd 2

        $this->assertCount(1, $this->userKeys);

        // listen to actions

        $this->userKeys = []; // refresh userKeys

        $broadcastData = json_encode([
            'action' => 'sample-broadcast-action',
        ]);
        ($socketRouter)($broadcastData, 3, $server); // broadcast fd 3

        $this->assertCount(0, $this->userKeys);
    }
}

<?php

namespace Tests;

use Conveyor\Actions\AddListenerAction;
use Conveyor\Actions\ChannelConnectAction;
use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use stdClass;
use Tests\Assets\SampleAction;
use Tests\Assets\SampleBroadcastAction;
use Tests\Assets\SampleBroadcastAction2;
use Tests\Assets\SampleChannelPersistence;
use Tests\Assets\SampleListenerPersistence;
use Tests\Assets\SamplePersistence;
use Tests\Assets\SampleSocketServer;
use Conveyor\SocketHandlers\SocketMessageRouter;

class SocketChannelTest extends SocketHandlerTestCase
{
    public array $userKeys = [];

    public function testCanExecuteChannelConnectAction()
    {
        $channelName = 'test-channel';
        $persistence = new SampleChannelPersistence;

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $connectionData = json_encode([
            'action' => ChannelConnectAction::ACTION_NAME,
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
        $persistence = new SampleChannelPersistence;
        $server = new SampleSocketServer([$this, 'sampleCallback']);
        $server->connections = [1, 2, 3];

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        ($socketRouter)(json_encode([
            'action' => SampleBroadcastAction::ACTION_NAME,
        ]), 3, $server); // broadcast to none since there are no channel connections

        $this->assertTrue(!in_array(1, $this->userKeys));
        $this->assertTrue(!in_array(2, $this->userKeys));
        $this->assertTrue(!in_array(3, $this->userKeys));

        $connectionData = json_encode([
            'action' => ChannelConnectAction::ACTION_NAME,
            'channel' => $channelName,
        ]);

        ($socketRouter)($connectionData, 1, $server); // connect fd 1
        ($socketRouter)($connectionData, 2, $server); // connect fd 2
        ($socketRouter)($connectionData, 3, $server); // connect fd 3

        $this->userKeys = []; // refresh userKeys

        // need to be refreshed (this is how it is supposed to work - one instantiation per message event)
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        // test broadcast

        $broadcastData = json_encode([
            'action' => SampleBroadcastAction::ACTION_NAME,
            'channel' => $channelName,
        ]);
        ($socketRouter)($broadcastData, 1, $server); // broadcast to fds 2 and 3

        $this->assertTrue(!in_array(1, $this->userKeys));
        $this->assertTrue(in_array(2, $this->userKeys));
        $this->assertTrue(in_array(3, $this->userKeys));
    }

    public function testCanListenToAction()
    {
        $channelName = 'test-channel';
        $channelName2 = 'test-channel-two';

        $listenerPersistence = new SampleListenerPersistence;
        $channelPersistence = new SampleChannelPersistence;
        $server = new SampleSocketServer([$this, 'sampleCallback']);
        $server->connections = [1, 2, 3, 4, 5];

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter([
            $listenerPersistence, $channelPersistence
        ]);

        // connect users to channels

        $connectionData = json_encode([
            'action' => ChannelConnectAction::ACTION_NAME,
            'channel' => $channelName,
        ]);
        ($socketRouter)($connectionData, 1, $server); // connect fd 1
        ($socketRouter)($connectionData, 2, $server); // connect fd 2

        $connectionData2 = json_encode([
            'action' => ChannelConnectAction::ACTION_NAME,
            'channel' => $channelName2,
        ]);
        ($socketRouter)($connectionData2, 3, $server); // connect fd 3
        ($socketRouter)($connectionData2, 4, $server); // connect fd 4
        ($socketRouter)($connectionData2, 5, $server); // connect fd 5

        // add listeners

        $listenToActionData = json_encode([
            'action' => AddListenerAction::ACTION_NAME,
            'listen' => SampleBroadcastAction::ACTION_NAME,
        ]);
        ($socketRouter)($listenToActionData, 1, $server); // connect fd 1
        ($socketRouter)($listenToActionData, 2, $server); // connect fd 2

        $listenToActionData2 = json_encode([
            'action' => AddListenerAction::ACTION_NAME,
            'listen' => SampleBroadcastAction2::ACTION_NAME,
        ]);
        ($socketRouter)($listenToActionData2, 3, $server); // connect fd 3
        ($socketRouter)($listenToActionData2, 4, $server); // connect fd 4
        ($socketRouter)($listenToActionData2, 5, $server); // connect fd 5

        // test $listenToActionData

        $broadcastData = json_encode([
            'action' => SampleBroadcastAction::ACTION_NAME, // this will broadcast to listeners and to channel
        ]);
        ($socketRouter)($broadcastData, 2, $server); // broadcast fd 2

        $this->assertCount(1, $this->userKeys);

        // test $listenToActionData2

        $this->userKeys = []; // refresh userKeys

        $broadcastData = json_encode([
            'action' => SampleBroadcastAction::ACTION_NAME,
        ]);
        ($socketRouter)($broadcastData, 3, $server); // this will broadcast to 0 because this is sending message with an action not listened by its channel

        $this->assertCount(0, $this->userKeys);
    }

    public function sampleCallback(int $fd) {
        $this->userKeys[] = $fd;
    }
}

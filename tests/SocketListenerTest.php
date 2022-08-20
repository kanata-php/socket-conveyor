<?php

namespace Tests;

use Conveyor\Actions\AddListenerAction;
use Conveyor\Actions\ChannelConnectAction;
use Conveyor\Actions\FanoutAction;
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

class SocketListenerTest extends SocketHandlerTestCase
{
    public array $userKeys = [];

    public function testCanExecuteListenerConnectAction()
    {
        $fd = 1;
        $fd2 = 2;
        $channelName = 'test-channel';
        $persistence = [
            'channel' => new SampleChannelPersistence,
            'listen' => new SampleListenerPersistence,
        ];
        $server = new SampleSocketServer([$this, 'sampleCallback']);
        $server->connections = [$fd, $fd2];

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $connectionData = json_encode([
            'action' => ChannelConnectAction::ACTION_NAME,
            'channel' => $channelName,
        ]);
        $result = ($socketRouter)($connectionData, $fd, $server);

        $this->assertNull($result);
        $this->assertCount(1, $persistence['channel']->getAllConnections());
        $this->assertTrue(in_array($channelName, $persistence['channel']->getAllConnections()));

        $connectionData2 = json_encode([
            'action' => AddListenerAction::ACTION_NAME,
            'listen' => SampleBroadcastAction::ACTION_NAME,
        ]);
        $result2 = ($socketRouter)($connectionData2, $fd, $server);

        $this->assertNull($result2);
        $listenersList = $persistence['listen']->getAllListeners();
        $this->assertCount(1, $listenersList[$fd]);
        $this->assertTrue(in_array(SampleBroadcastAction::ACTION_NAME, $listenersList[$fd]));

        $this->userKeys = []; // refresh userKeys

        ($socketRouter)($connectionData, $fd2, $server); // connect user 2 to channel
        ($socketRouter)($connectionData2, $fd2, $server); // user 2 listens to broadcast action
        // message to be considered
        $connectionData3 = json_encode([
            'action' => SampleBroadcastAction::ACTION_NAME,
            'data' => 'message-1',
        ]);
        ($socketRouter)($connectionData3, $fd, $server);
        // message to be ignored
        $connectionData4 = json_encode([
            'action' => SampleBroadcastAction2::ACTION_NAME,
            'data' => 'message-1',
        ]);
        ($socketRouter)($connectionData4, $fd, $server);

        $this->assertCount(1, $this->userKeys);
    }

    public function testCantBroadcastToListenersWhenToChannel()
    {
        $fd = 1;
        $fd2 = 2;
        $channelName = 'test-channel';
        $persistence = [
            'channel' => new SampleChannelPersistence,
            'listen' => new SampleListenerPersistence,
        ];
        $server = new SampleSocketServer([$this, 'sampleCallback']);
        $server->connections = [$fd, $fd2];

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        // connect
        $connectionData = json_encode([
            'action' => AddListenerAction::ACTION_NAME,
            'listen' => SampleBroadcastAction::ACTION_NAME,
        ]);
        ($socketRouter)($connectionData, $fd, $server);

        $this->userKeys = []; // refresh userKeys

        // user 2 listens to broadcast action
        ($socketRouter)($connectionData, $fd2, $server);

        // message to be considered
        $connectionData2 = json_encode([
            'action' => SampleBroadcastAction::ACTION_NAME,
            'data' => 'message-1',
        ]);
        ($socketRouter)($connectionData2, $fd, $server);

        // message to be ignored
        $connectionData3 = json_encode([
            'action' => SampleBroadcastAction2::ACTION_NAME,
            'data' => 'message-1',
        ]);
        ($socketRouter)($connectionData3, $fd, $server);

        $this->assertCount(0, $this->userKeys);
    }

    public function testCanFanoutToListeners()
    {
        $fd = 1;
        $fd2 = 2;
        $fd3 = 2;
        $persistence = new SampleListenerPersistence;
        $server = new SampleSocketServer([$this, 'sampleCallback']);
        $server->connections = [$fd, $fd2, $fd3];

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        // connect
        $connectionData = json_encode([
            'action' => AddListenerAction::ACTION_NAME,
            'listen' => FanoutAction::ACTION_NAME,
        ]);
        ($socketRouter)($connectionData, $fd, $server);

        $this->userKeys = []; // refresh userKeys

        // user 2 listens to broadcast action
        ($socketRouter)($connectionData, $fd2, $server);

        // message to be considered
        $connectionData2 = json_encode([
            'action' => FanoutAction::ACTION_NAME,
            'data' => 'message-1',
        ]);
        ($socketRouter)($connectionData2, $fd, $server);

        $this->assertCount(2, $this->userKeys);
    }

    public function sampleCallback(int $fd) {
        $this->userKeys[] = $fd;
    }
}

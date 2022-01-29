<?php

namespace Tests;

use Conveyor\Actions\ChannelConnectionAction;
use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use stdClass;
use Exception;
use League\Pipeline\Pipeline;
use Tests\Assets\SampleAction;
use Tests\Assets\SampleBroadcastAction;
use Tests\Assets\SamplePersistence;
use Tests\Assets\SampleSocketServer;
use Tests\SocketHandlerTestCase;
use Tests\Assets\SampleMiddleware;
use Tests\Assets\SampleMiddleware2;
use Tests\Assets\SampleExceptionHandler;
use Tests\Assets\SampleCustomException;
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

    public function testCanExecuteChannelConnectionAction()
    {
        $channelName = 'test-channel';
        $persistence = new SamplePersistence;

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $connectionData = json_encode([
            'action' => 'channel-connection',
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
        $server = new SampleSocketServer([$this, 'sampleCallback'], 'newKey');

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $socketRouter->add(new SampleBroadcastAction());

        $connectionData = json_encode([
            'action' => 'channel-connection',
            'channel' => $channelName,
        ]);
        ($socketRouter)($connectionData, 1, $server); // connect fd 1
        ($socketRouter)($connectionData, 2, $server); // connect fd 2

        $socketRouter = new SocketMessageRouter($persistence);
        $socketRouter->add(new SampleBroadcastAction());

        $this->checkSingleSend($socketRouter, $server);

        $this->userKeys = []; // refresh userKeys

        $this->checkBroadcastSend($socketRouter, $server);
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
        ($socketRouter)($broadcastData, 1, $server); // broadcast fd 3

        $this->assertTrue(in_array(1, $this->userKeys));
        $this->assertTrue(in_array(2, $this->userKeys));
        $this->assertTrue(!in_array(3, $this->userKeys));
    }
}

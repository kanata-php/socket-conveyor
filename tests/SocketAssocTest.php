<?php

namespace Tests;

use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use stdClass;
use Tests\Assets\SampleAction;
use Tests\Assets\SamplePersistence;
use Tests\Assets\SampleSocketServer;
use Conveyor\SocketHandlers\SocketMessageRouter;

class SocketAssocTest extends SocketHandlerTestCase
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
        $userId = 10;
        $persistence = new SamplePersistence;

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $this->assertTrue($persistence->getAssoc(1) === null);

        $connectionData = json_encode([
            'action' => 'assoc-user-to-fd-action',
            'userId' => $userId,
        ]);
        ($socketRouter)($connectionData, 1, new stdClass());

        $this->assertTrue($persistence->getAssoc(1) === $userId);
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

<?php

namespace Tests;

use Conveyor\SocketHandlers\SocketChannelPersistenceTable;
use Tests\Assets\SampleReturnAction;
use Tests\Assets\SampleSocketServer;

class PipelineTest extends SocketHandlerTestCase
{
    protected array $userKeys = [];

    public function testCanAddPipeline()
    {
        $check = false;

        $server = new SampleSocketServer(fn($fd) => $this->userKeys[] = $fd);
        $sampleReturnAction = new SampleReturnAction;

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter(new SocketChannelPersistenceTable);
        $socketRouter->middleware($sampleReturnAction->getName(), function($payload) use (&$check) {
            $check = true;
            return $payload;
        });

        $connectionData = json_encode([
            'action' => SampleReturnAction::ACTION_NAME,
        ]);

        ($socketRouter)($connectionData, 1, $server);

        $this->assertTrue($check);
        $this->assertCount(1, $this->userKeys);
    }
}
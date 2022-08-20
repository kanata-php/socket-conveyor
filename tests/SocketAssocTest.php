<?php

namespace Tests;

use stdClass;
use Tests\Assets\SampleAction;
use Conveyor\SocketHandlers\SocketMessageRouter;
use Tests\Assets\SampleUserAssocPersistence;

class SocketAssocTest extends SocketHandlerTestCase
{
    public array $userKeys = [];

    public function testCanAddHandlerForAction()
    {
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();
        
        $this->assertInstanceOf(SocketMessageRouter::class, $socketRouter);
        $this->assertInstanceOf(SampleAction::class, $sampleAction);
    }

    public function testCanExecuteUserAssocAction()
    {
        $userId = 10;
        $persistence = new SampleUserAssocPersistence;

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter($persistence);

        $this->assertTrue($persistence->getAssoc(1) === null);

        $connectionData = json_encode([
            'action' => 'assoc-user-to-fd-action',
            'userId' => $userId,
        ]);
        ($socketRouter)($connectionData, 1, new stdClass());

        $this->assertTrue($persistence->getAssoc(1) === $userId);
    }
}

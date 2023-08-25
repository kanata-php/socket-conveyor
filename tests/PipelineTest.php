<?php

namespace Tests;

use Tests\Assets\SampleReturnAction;

class PipelineTest extends SocketHandlerTestCase
{
    public function testCanAddPipeline()
    {
        $check = false;

        $sampleReturnAction = new SampleReturnAction;

        $this->router->getActionManager()->middleware(
            action: $sampleReturnAction->getName(),
            middleware: function($payload) use (&$check) {
                $check = true;
                return $payload;
            },
        );

        $this->sendData(1, json_encode([
            'action' => SampleReturnAction::ACTION_NAME,
        ]));

        $this->assertTrue($check);
        $this->assertCount(1, $this->userKeys);
    }
}

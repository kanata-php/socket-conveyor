<?php

namespace Tests;

use Conveyor\Actions\BroadcastAction;

class SocketBroadcastTest extends SocketHandlerTestCase
{
    public function testCanBroadcastWithoutChannel()
    {
        $message = 'sample-message';

        $this->server->connections[] = 3;

        $this->sendData(3, json_encode([
            'action' => BroadcastAction::ACTION_NAME,
            'data' => $message,
        ]));

        $this->assertTrue(in_array(1, array_keys($this->userKeys)));
        $this->assertTrue(in_array(2, array_keys($this->userKeys)));
        $this->assertTrue(!in_array(3, array_keys($this->userKeys)));
    }

    public function testCantBroadcastWithoutChannelForFdsConnectedToChannel()
    {
        $message = 'sample-message';

        $this->server->connections[] = 3;

        $this->connectToChannel(2, 'test-channel'); // won't receive

        // test broadcast

        $this->sendData(1, json_encode([
            'action' => BroadcastAction::ACTION_NAME,
            'data' => $message,
        ]));

        $this->assertTrue(!in_array(1, array_keys($this->userKeys)));
        $this->assertTrue(!in_array(2, array_keys($this->userKeys)));
        $this->assertTrue(in_array(3, array_keys($this->userKeys)));
        $this->assertCount(1, array_filter(
            $this->userKeys,
            fn($d) => $message === json_decode($d)->data,
        ));
    }
}

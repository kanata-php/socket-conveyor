<?php

namespace Tests;

use Conveyor\Actions\BroadcastAction;
use Conveyor\Actions\FanoutAction;
use Tests\Assets\SecondaryBroadcastAction;
use Tests\Assets\SecondaryFanoutAction;

class SocketListenerTest extends SocketHandlerTestCase
{
    public function testCanExecuteListenerConnectAction()
    {
        $channelName = 'test-channel';
        $message = 'sample-message';

        $this->connectToChannel(1, $channelName);
        $this->listenToAction(1, BroadcastAction::ACTION_NAME);

        // assert everything in place
        $listenersList = $this->listenerPersistence->getAllListeners();
        $this->assertCount(1, $listenersList[1]);
        $this->assertTrue(in_array(BroadcastAction::ACTION_NAME, $listenersList[1]));

        $this->connectToChannel(2, $channelName);
        $this->listenToAction(2, BroadcastAction::ACTION_NAME);

        // message to be listened
        $this->sendData(1, json_encode([
            'action' => BroadcastAction::ACTION_NAME,
            'data' => $message,
        ]));

        // message to be ignored
        $this->sendData(1, json_encode([
            'action' => SecondaryBroadcastAction::ACTION_NAME,
            'data' => $message,
        ]));

        $this->assertCount(1, $this->userKeys);
    }

    public function testCanBroadcastToListenersWithoutChannel()
    {
        $message = 'sample-message';

        $this->listenToAction(1, BroadcastAction::ACTION_NAME);

        $this->listenToAction(2, BroadcastAction::ACTION_NAME);

        // message to be considered
        $this->sendData(1, json_encode([
            'action' => BroadcastAction::ACTION_NAME,
            'data' => $message,
        ]));

        // message to be ignored
        $this->sendData(1, json_encode([
            'action' => SecondaryBroadcastAction::ACTION_NAME,
            'data' => $message,
        ]));

        $this->assertCount(1, $this->userKeys);
    }

    public function testCanFanoutToListenersCrossRequests()
    {
        $message = 'sample-message';

        $this->server->connections[] = 3; // this won't listen

        $this->listenToAction(1, FanoutAction::ACTION_NAME);

        $this->listenToAction(2, FanoutAction::ACTION_NAME);

        // message to be considered
        $this->sendData(1, json_encode([
            'action' => FanoutAction::ACTION_NAME,
            'data' => $message,
        ]));
        $this->assertCount(2, $this->userKeys);

        $this->router = $this->prepareSocketMessageRouter([
            'channel' => $this->channelPersistence,
            'listen' => $this->listenerPersistence,
            'userAssoc' => $this->userAssocPersistence,
        ]);

        $this->userKeys = [];

        // message to be ignored
        $this->sendData(2, json_encode([
            'action' => SecondaryFanoutAction::ACTION_NAME,
            'data' => $message,
        ]));

        $this->assertCount(1, $this->userKeys);
    }
}

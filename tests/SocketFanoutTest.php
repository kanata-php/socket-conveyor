<?php

namespace Tests;

use Conveyor\Actions\BroadcastAction;
use Conveyor\Actions\FanoutAction;
use InvalidArgumentException;

class SocketFanoutTest extends SocketHandlerTestCase
{
    public array $userKeys = [];

    public function testCanExecuteFanoutAction()
    {
        $message = 'some-message';

        $this->server->connections[] = 3;
        $this->server->connections[] = 4;
        $this->server->connections[] = 5;

        $this->sendData(1, json_encode([
            'action' => FanoutAction::ACTION_NAME,
            'data' => $message,
        ]));

        $this->assertCount(5, array_filter(
            $this->userKeys,
            fn($d) => $message === json_decode($d)->data
        ));
    }

    public function testCanExecuteFanoutActionRegardlessOfChannel()
    {
        $message = 'some-message';
        $this->server->connections[] = 3;
        $this->server->connections[] = 4;
        $this->server->connections[] = 5;

        $this->connectToChannel(1, 'some-channel');

        $this->sendData(1, json_encode([
            'action' => FanoutAction::ACTION_NAME,
            'data' => $message,
        ]));

        $this->assertCount(5, array_filter(
            $this->userKeys,
            fn($d) => $message === json_decode($d)->data
        ));
    }

    public function testFanoutRespectListeners()
    {
        $message = 'some-message';
        $this->server->connections[] = 3;
        $this->server->connections[] = 4;
        $this->server->connections[] = 5;


        $this->connectToChannel(1, 'some-channel');
        $this->listenToAction(4, BroadcastAction::ACTION_NAME);

        $this->sendData(1, json_encode([
            'action' => FanoutAction::ACTION_NAME,
            'data' => $message,
        ]));

        $this->assertCount(4, array_filter(
            $this->userKeys,
            fn($d) => $message === json_decode($d)->data
        ));
    }

    public function testCantExecuteFanoutActionWithoutData()
    {
        $this->server->connections[] = 3;
        $this->server->connections[] = 4;
        $this->server->connections[] = 5;

        $this->expectException(InvalidArgumentException::class);

        $this->sendData(1, json_encode([
            'action' => FanoutAction::ACTION_NAME,
            // missing 'data'
        ]));
    }
}

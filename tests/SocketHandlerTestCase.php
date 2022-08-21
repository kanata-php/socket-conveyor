<?php

namespace Tests;

use Conveyor\Actions\AddListenerAction;
use Conveyor\Actions\AssocUserToFdAction;
use Conveyor\Actions\BroadcastAction;
use Conveyor\Actions\ChannelConnectAction;
use Conveyor\Actions\FanoutAction;
use Conveyor\SocketHandlers\Interfaces\GenericPersistenceInterface;
use PHPUnit\Framework\TestCase;
use Conveyor\SocketHandlers\SocketMessageRouter;
use Tests\Assets\SampleChannelPersistence;
use Tests\Assets\SampleListenerPersistence;
use Tests\Assets\SampleReturnAction;
use Tests\Assets\SampleSocketServer;
use Tests\Assets\SampleUserAssocPersistence;
use Tests\Assets\SecondaryBroadcastAction;
use Tests\Assets\SecondaryFanoutAction;

class SocketHandlerTestCase extends TestCase
{
    public array $userKeys = [];

    public SampleChannelPersistence $channelPersistence;
    public SampleListenerPersistence $listenerPersistence;
    public SampleUserAssocPersistence $userAssocPersistence;
    public SocketMessageRouter $router;
    public SampleSocketServer $server;

    /**
     * @before
     */
    public function setUpRouter()
    {
        $this->channelPersistence = new SampleChannelPersistence;
        $this->listenerPersistence = new SampleListenerPersistence;
        $this->userAssocPersistence = new SampleUserAssocPersistence;

        $this->router = $this->prepareSocketMessageRouter([
            'channel' => $this->channelPersistence,
            'listen' => $this->listenerPersistence,
            'userAssoc' => $this->userAssocPersistence,
        ]);
    }

    /**
     * @before
     */
    public function setUpServer()
    {
        $this->server = new SampleSocketServer([$this, 'sampleCallback']);
        $this->server->connections = [1, 2];
    }

    protected function prepareSocketMessageRouter(null|array|GenericPersistenceInterface $persistence = null): SocketMessageRouter
    {
        $socketRouter = new SocketMessageRouter($persistence);

        $resultOfAddMethod = $socketRouter->add(new ChannelConnectAction);
        $this->assertInstanceOf(SocketMessageRouter::class, $resultOfAddMethod);

        // sample actions
        $socketRouter->add(new SecondaryBroadcastAction);
        $socketRouter->add(new SampleReturnAction);
        $socketRouter->add(new SecondaryFanoutAction);

        return $socketRouter;
    }

    public function sampleCallback(int $fd, string $data): void
    {
        $this->userKeys[$fd] = $data;
    }

    public function connectToChannel(int $fd, string $channel): void
    {
        ($this->router)(json_encode([
            'action' => ChannelConnectAction::ACTION_NAME,
            'channel' => $channel,
        ]), $fd, $this->server);
    }

    public function listenToAction(int $fd, string $action): void
    {
        ($this->router)(json_encode([
            'action' => AddListenerAction::ACTION_NAME,
            'listen' => $action,
        ]), $fd, $this->server);
    }

    public function assocUser(int $fd, int $userId): void
    {
        ($this->router)(json_encode([
            'action' => 'assoc-user-to-fd-action',
            'userId' => $userId,
        ]), $fd, $this->server);
    }

    public function sendData(int $fd, string $data): void
    {
        ($this->router)($data, $fd, $this->server);
    }
}

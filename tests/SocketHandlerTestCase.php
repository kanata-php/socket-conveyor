<?php

namespace Tests;

use Conveyor\Actions\ActionManager;
use Conveyor\Actions\AddListenerAction;
use Conveyor\Actions\ChannelConnectAction;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\Persistence\WebSockets\AssociationsPersistence;
use Conveyor\Persistence\WebSockets\ChannelsPersistence;
use Conveyor\Persistence\WebSockets\ListenersPersistence;
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

    public ChannelsPersistence $channelPersistence;
    public ListenersPersistence $listenerPersistence;
    public AssociationsPersistence $userAssocPersistence;
    public SocketMessageRouter $router;
    public SampleSocketServer $server;

    /** @var null|callable */
    public $callbackVerification = null;

    /**
     * @before
     */
    public function setUpRouter()
    {
        $this->channelPersistence = new ChannelsPersistence($this->getDatabaseOptions());
        $this->listenerPersistence = new ListenersPersistence($this->getDatabaseOptions());
        $this->userAssocPersistence = new AssociationsPersistence($this->getDatabaseOptions());

        $this->router = $this->prepareSocketMessageRouter([
            $this->channelPersistence,
            $this->listenerPersistence,
            $this->userAssocPersistence,
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
        $socketRouter = new SocketMessageRouter();
        $socketRouter->persistence($persistence);
        $actionManager = $socketRouter->getActionManager();

        $resultOfAddMethod = $actionManager->add(new ChannelConnectAction);
        $this->assertInstanceOf(ActionManager::class, $resultOfAddMethod);

        // sample actions
        $actionManager->add(new SecondaryBroadcastAction);
        $actionManager->add(new SampleReturnAction);
        $actionManager->add(new SecondaryFanoutAction);

        return $socketRouter;
    }

    public function sampleCallback(int $fd, string $data): void
    {
        $this->userKeys[$fd] = $data;

        if (null !== $this->callbackVerification) {
            ($this->callbackVerification)($data);
        }
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

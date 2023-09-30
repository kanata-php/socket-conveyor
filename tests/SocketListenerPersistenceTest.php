<?php

namespace Tests;

use Conveyor\Persistence\WebSockets\ListenersPersistence;
use Tests\Assets\SampleAction;

class SocketListenerPersistenceTest extends TestCase
{
    protected ?ListenersPersistence $data = null;

    /**
     * @before
     */
    public function freeMemory()
    {
        $this->data = new ListenersPersistence($this->getDatabaseOptions());
        $this->data->refresh(true);
    }

    private function listen(int $fd, string $action)
    {
        $this->data->listen($fd, $action);
    }

    public function testCanlisten()
    {
        $action = 'sample-action';

        $this->assertFalse(
            in_array($action, $this->data->getAllListeners())
        );

        $this->listen(1, $action);

        $this->assertCount(1, $this->data->getAllListeners());
        $this->assertCount(1, $this->data->getListener(1));
    }

    public function testCanGetAllListeners()
    {
        $action = 'sample-action';
        $action2 = 'sample-action-two';

        $this->assertEmpty($this->data->getAllListeners());

        $this->listen(1, $action);
        $this->listen(1, $action2);
        $this->listen(2, $action);

        $this->assertCount(2, $this->data->getAllListeners());
        $this->assertCount(2, $this->data->getListener(1));
        $this->assertCount(1, $this->data->getListener(2));
    }

    public function testCanGetListener()
    {
        $this->assertNull($this->data->getListener(1));
        $this->listen(1, SampleAction::ACTION_NAME);
        $this->assertCount(1, $this->data->getListener(1));
    }

    public function testCanStopListening()
    {
        $action = 'sample-action';

        $this->assertFalse(
            in_array($action, $this->data->getAllListeners())
        );

        $this->listen(1, $action);

        $this->assertCount(1, $this->data->getAllListeners());
        $this->assertCount(1, $this->data->getListener(1));

        $this->data->stopListener(1, $action);

        $this->assertCount(0, $this->data->getAllListeners());
        $this->assertNull($this->data->getListener(1));
    }
}

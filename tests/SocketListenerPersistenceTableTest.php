<?php

namespace Tests;

use Conveyor\SocketHandlers\SocketListenerPersistenceTable;
use PHPUnit\Framework\TestCase;

class SocketListenerPersistenceTableTest extends TestCase
{
    protected ?SocketListenerPersistenceTable $data = null;

    /**
     * @before
     */
    public function freeMemory()
    {
        $this->data = new SocketListenerPersistenceTable;
    }

    private function listen(int $fd, string $action)
    {
        $this->data->listen($fd, $action);
    }

    public function testCanlisten()
    {
        $fd = 1;
        $action = 'sample-action';

        $this->assertFalse(
            in_array($action, $this->data->getAllListeners())
        );

        $this->listen($fd, $action);

        $this->assertCount(1, $this->data->getAllListeners());
        $this->assertCount(1, $this->data->getListener($fd));
    }

    public function testCanGetAllListeners()
    {
        $fd1 = 1;
        $fd2 = 2;
        $action = 'sample-action';
        $action2 = 'sample-action-two';

        $this->assertEmpty($this->data->getAllListeners());

        $this->listen($fd1, $action);
        $this->listen($fd1, $action2);
        $this->listen($fd2, $action);

        $this->assertCount($fd2, $this->data->getAllListeners());
        $this->assertCount(2, $this->data->getListener($fd1));
        $this->assertCount(1, $this->data->getListener($fd2));
    }

    public function testCanStopListening()
    {
        $fd = 1;
        $action = 'sample-action';

        $this->assertFalse(
            in_array($action, $this->data->getAllListeners())
        );

        $this->listen($fd, $action);


        $this->assertCount(1, $this->data->getAllListeners());
        $this->assertCount(1, $this->data->getListener($fd));

        $this->data->stopListener($fd, $action);

        $this->assertCount(1, $this->data->getAllListeners());
        $this->assertCount(0, $this->data->getListener($fd));
    }
}

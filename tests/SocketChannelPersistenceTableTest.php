<?php

namespace Tests;

use Conveyor\Models\SocketChannelPersistenceTable;
use PHPUnit\Framework\TestCase;

class SocketChannelPersistenceTableTest extends TestCase
{
    protected ?SocketChannelPersistenceTable $data = null;

    /**
     * @before
     */
    public function freeMemory()
    {
        $this->data = new SocketChannelPersistenceTable;
    }

    private function connectToChannel(int $fd, string $channel)
    {
        $this->data->connect($fd, $channel);
    }

    public function testCanSetChannelConnect()
    {
        $channel = 'my-channel';

        $this->assertFalse(
            in_array($channel, $this->data->getAllConnections())
        );

        $this->connectToChannel(1, $channel);

        $this->assertTrue(
            in_array($channel, $this->data->getAllConnections())
        );
    }

    public function testCanGetAllChannelConnections()
    {
        $channel = 'my-channel';

        $this->assertEmpty($this->data->getAllConnections());

        $this->connectToChannel(1, $channel);
        $this->connectToChannel(2, $channel);

        $this->assertCount(2, $this->data->getAllConnections());
    }

    public function testCanDisconnectFromChannel()
    {
        $fd = 1;
        $channel = 'my-channel';

        $this->assertFalse(
            in_array($channel, $this->data->getAllConnections())
        );

        $this->connectToChannel($fd, $channel);

        $this->assertTrue(
            in_array($channel, $this->data->getAllConnections())
        );

        $this->data->disconnect($fd);

        $this->assertFalse(
            in_array($channel, $this->data->getAllConnections())
        );
    }
}

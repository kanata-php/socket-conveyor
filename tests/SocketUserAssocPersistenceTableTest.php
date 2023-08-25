<?php

namespace Tests;

use Conveyor\Models\SocketUserAssocPersistenceTable;
use PHPUnit\Framework\TestCase;

class SocketUserAssocPersistenceTableTest extends TestCase
{
    protected ?SocketUserAssocPersistenceTable $data = null;

    /**
     * @before
     */
    public function freeMemory()
    {
        $this->data = new SocketUserAssocPersistenceTable;
    }

    private function assocUser(int $fd, int $userId)
    {
        $this->data->assoc($fd, $userId);
    }

    public function testCanAssocUser()
    {
        $userId = 2;

        $this->assertFalse(
            in_array($userId, $this->data->getAllAssocs())
        );

        $this->assocUser(1, $userId);

        $this->assertTrue(
            in_array($userId, $this->data->getAllAssocs())
        );
    }

    public function testCanGetAllAssocs()
    {
        $userId = 2;

        $this->assertEmpty($this->data->getAllAssocs());

        $this->assocUser(1, $userId);
        $this->assocUser(2, $userId);

        $this->assertCount(2, $this->data->getAllAssocs());
    }

    public function testCanDisassoc()
    {
        $userId = 2;

        $this->assertFalse(
            in_array($userId, $this->data->getAllAssocs())
        );

        $this->assocUser(1, $userId);

        $this->assertTrue(
            in_array($userId, $this->data->getAllAssocs())
        );

        $this->data->disassoc(2);

        $this->assertFalse(
            in_array($userId, $this->data->getAllAssocs())
        );
    }
}

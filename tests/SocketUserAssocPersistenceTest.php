<?php

namespace Tests;

use Conveyor\Persistence\WebSockets\AssociationsPersistence;

class SocketUserAssocPersistenceTest extends TestCase
{
    protected ?AssociationsPersistence $data = null;

    /**
     * @before
     */
    public function freeMemory()
    {
        $this->data = new AssociationsPersistence($this->getDatabaseOptions());
        $this->data->refresh(true);
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
        $userId_1 = 2;
        $userId_2 = 3;

        $this->assertEmpty($this->data->getAllAssocs());

        $this->assocUser(1, $userId_1);
        $this->assocUser(2, $userId_2);

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

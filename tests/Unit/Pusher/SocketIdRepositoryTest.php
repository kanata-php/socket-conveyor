<?php

namespace Tests\Unit\Pusher;

use Conveyor\SubProtocols\Pusher\SocketIdRepository;
use Tests\TestCase;

class SocketIdRepositoryTest extends TestCase
{
    public function testRegisterIsIdempotent()
    {
        $repository = new SocketIdRepository();

        $first = $repository->register(42);
        $second = $repository->register(42);

        $this->assertEquals($first, $second);
    }

    public function testForwardAndReverseResolutionRoundTrips()
    {
        $repository = new SocketIdRepository();

        $socketId = $repository->register(42);

        $this->assertEquals($socketId, $repository->socketIdFor(42));
        $this->assertEquals(42, $repository->fdFor($socketId));
    }

    public function testUnknownLookupsReturnNull()
    {
        $repository = new SocketIdRepository();

        $this->assertNull($repository->socketIdFor(999));
        $this->assertNull($repository->fdFor('999.999'));
    }

    public function testForgetClearsBothDirections()
    {
        $repository = new SocketIdRepository();

        $socketId = $repository->register(42);
        $repository->forget(42);

        $this->assertNull($repository->socketIdFor(42));
        $this->assertNull($repository->fdFor($socketId));
    }

    public function testSocketIdsAreStablePerConnectionAndCollisionFree()
    {
        $repository = new SocketIdRepository();

        $idA = $repository->register(1);
        $idB = $repository->register(2);

        $this->assertNotEquals($idA, $idB);
        $this->assertEquals(1, $repository->fdFor($idA));
        $this->assertEquals(2, $repository->fdFor($idB));
    }
}

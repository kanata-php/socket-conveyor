<?php

namespace Tests\Unit\Pusher;

use Conveyor\SubProtocols\Pusher\PusherChannelRepository;
use Tests\TestCase;

class PusherChannelRepositoryTest extends TestCase
{
    public function testOneFdCanSubscribeToManyChannels(): void
    {
        $repository = new PusherChannelRepository();

        $repository->subscribe(42, 'orders.1');
        $repository->subscribe(42, 'private-orders.1');

        $this->assertTrue($repository->isSubscribed(42, 'orders.1'));
        $this->assertTrue($repository->isSubscribed(42, 'private-orders.1'));
        $this->assertSame([42 => 'orders.1'], $repository->subscribersOf('orders.1'));
        $this->assertSame([42 => 'private-orders.1'], $repository->subscribersOf('private-orders.1'));

        $repository->destroyTable();
    }

    public function testUnsubscribeRemovesOnlyOneChannel(): void
    {
        $repository = new PusherChannelRepository();

        $repository->subscribe(42, 'orders.1');
        $repository->subscribe(42, 'private-orders.1');
        $repository->unsubscribe(42, 'orders.1');

        $this->assertFalse($repository->isSubscribed(42, 'orders.1'));
        $this->assertTrue($repository->isSubscribed(42, 'private-orders.1'));

        $repository->destroyTable();
    }

    public function testUnsubscribeAllReturnsRemovedChannels(): void
    {
        $repository = new PusherChannelRepository();

        $repository->subscribe(42, 'orders.1');
        $repository->subscribe(42, 'private-orders.1');
        $repository->subscribe(99, 'orders.1');

        $removed = $repository->unsubscribeAll(42);
        sort($removed);

        $this->assertSame(['orders.1', 'private-orders.1'], $removed);
        $this->assertFalse($repository->isSubscribed(42, 'orders.1'));
        $this->assertFalse($repository->isSubscribed(42, 'private-orders.1'));
        $this->assertTrue($repository->isSubscribed(99, 'orders.1'));

        $repository->destroyTable();
    }
}

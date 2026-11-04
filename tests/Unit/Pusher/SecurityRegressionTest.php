<?php

namespace Tests\Unit\Pusher;

use Conveyor\SubProtocols\Pusher\PusherChannelRepository;
use Conveyor\SubProtocols\Pusher\SocketIdRepository;
use Tests\TestCase;
use OpenSwoole\Table;

/** Independent regressions for the public 2.6.0 security findings. */
class SecurityRegressionTest extends TestCase
{
    public function testEqualExternalNamesAreIsolatedAcrossApplicationNamespaces(): void
    {
        $repository = new PusherChannelRepository();
        try {
            $repository->subscribe(11, 'private-orders', 'app-a');
            $repository->subscribe(22, 'private-orders', 'app-b');

            $this->assertSame([11 => 'private-orders'], $repository->subscribersOf('private-orders', 'app-a'));
            $this->assertSame([22 => 'private-orders'], $repository->subscribersOf('private-orders', 'app-b'));
            $this->assertFalse($repository->isSubscribed(11, 'private-orders', 'app-b'));
        } finally {
            $repository->destroyTable();
        }
    }

    public function testCleanupCannotDeleteAnotherApplicationMembershipOnTheSameFd(): void
    {
        $repository = new PusherChannelRepository();
        try {
            $repository->subscribe(11, 'orders', 'app-a');
            $repository->subscribe(11, 'orders', 'app-b');

            $this->assertSame(['orders'], $repository->unsubscribeAll(11, 'app-a'));
            $this->assertFalse($repository->isSubscribed(11, 'orders', 'app-a'));
            $this->assertTrue($repository->isSubscribed(11, 'orders', 'app-b'));
        } finally {
            $repository->destroyTable();
        }
    }

    public function testMaximumProtocolChannelRoundTripsWithoutStorageKeyTruncation(): void
    {
        $repository = new PusherChannelRepository();
        $channel = 'presence-' . str_repeat('x', 155);
        try {
            $repository->subscribe(11, $channel, 'app-a');

            $this->assertTrue($repository->isSubscribed(11, $channel, 'app-a'));
            $this->assertSame([11 => $channel], $repository->subscribersOf($channel, 'app-a'));
            $this->assertSame([$channel], $repository->unsubscribeAll(11, 'app-a'));
        } finally {
            $repository->destroyTable();
        }
    }

    public function testSameFdGetsFreshAuthorizationContextAfterReconnect(): void
    {
        $repository = new SocketIdRepository();
        $first = $repository->register(11, 'app-a');
        $repository->forget(11, $first);

        $second = $repository->register(11, 'app-a');

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{0,18}\.[1-9][0-9]{0,18}$/D', $second);
        $this->assertLessThanOrEqual(39, strlen($second));
        $this->assertNull($repository->fdFor($first));
        $this->assertSame(11, $repository->fdFor($second));
    }

    public function testLateCleanupCannotRemoveANewerConnectionGeneration(): void
    {
        $repository = new SocketIdRepository();
        $first = $repository->register(11, 'app-a');
        $repository->forget(11, $first);
        $current = $repository->register(11, 'app-b');

        $repository->forget(11, $first);

        $this->assertSame($current, $repository->socketIdFor(11));
        $this->assertSame(11, $repository->fdFor($current));
        $this->assertSame('app-b', $repository->appKeyFor(11));
    }

    public function testFailedForwardRegistrationRollsBackTheReverseReservation(): void
    {
        $repository = new SocketIdRepository();
        $forwardProperty = new \ReflectionProperty($repository, 'forward');
        $forwardProperty->setAccessible(true);
        $forwardProperty->getValue($repository)->destroy();
        $forward = new Table(64);
        $forward->column('socket_id', Table::TYPE_STRING, 40);
        $forward->column('app_key', Table::TYPE_STRING, 64);
        $forward->create();
        $forwardProperty->setValue($repository, $forward);
        $reverseProperty = new \ReflectionProperty($repository, 'reverse');
        $reverseProperty->setAccessible(true);
        $reverse = $reverseProperty->getValue($repository);
        $accepted = 0;
        $failedFd = null;
        for ($fd = 1; $fd <= 1000; $fd++) {
            try {
                $repository->register($fd, 'app-a');
                $accepted++;
            } catch (\Throwable $exception) {
                $failedFd = $fd;
                break;
            }
        }
        $this->assertNotNull($failedFd, 'Forward storage failure was not exercised');
        $this->assertSame($accepted, count($forward));
        $this->assertSame($accepted, count($reverse), 'Failed forward insert stranded a reverse identity');
        $this->assertNull($repository->socketIdFor($failedFd));
        $this->assertNull($repository->appKeyFor($failedFd));
        $repository->forget(1);
        $id = $repository->register($failedFd, 'app-a');
        $this->assertSame($failedFd, $repository->fdFor($id));
    }

    public function testFreshRepositoryDoesNotRecreateThePreviousProcessesAuthorizationContext(): void
    {
        $before = new SocketIdRepository();
        $after = new SocketIdRepository();

        $this->assertNotSame($before->register(11, 'app-a'), $after->register(11, 'app-a'));
    }
}

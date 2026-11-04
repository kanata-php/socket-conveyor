<?php

namespace Tests\Unit\Pusher;

use Conveyor\SubProtocols\Pusher\PusherAdmission;
use Conveyor\SubProtocols\Pusher\PusherChannelRepository;
use Conveyor\SubProtocols\Pusher\PusherLimits;
use OpenSwoole\Process;
use OpenSwoole\Table;
use Tests\TestCase;

class SecurityBudgetTest extends TestCase
{
    /** @dataProvider invalidLimitProvider */
    public function testInvalidLimitConfigurationFailsClosed(array $limits): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PusherLimits($limits);
    }

    public function invalidLimitProvider(): array
    {
        return [
            'string' => [['memberships_per_socket' => '2']],
            'zero' => [['memberships_per_socket' => 0]],
            'negative' => [['connections_total' => -1]],
            'float' => [['client_burst_socket' => 1.5]],
            'boolean' => [['pending_total' => true]],
            'unknown' => [['unknown_limit' => 1]],
            'physical capacity' => [['memberships_total' => 40001]],
            'contradictory memberships' => [['memberships_per_app' => 2]],
            'contradictory pending' => [['pending_total' => 1]],
            'contradictory heartbeat' => [['idle_seconds' => 1]],
        ];
    }

    public function testConcurrentApplicationBudgetCannotOvershootAndRecoversAfterCleanup(): void
    {
        $repository = new PusherChannelRepository(null, new PusherLimits([
            'memberships_per_socket' => 2,
            'memberships_per_app' => 3,
            'memberships_total' => 4,
        ]));
        $children = [];
        try {
            // One shared pre-fork repository, independent process attempts.
            for ($fd = 1; $fd <= 12; $fd++) {
                $child = new Process(function (Process $process) use ($repository, $fd): void {
                    try {
                        $repository->subscribe($fd, 'concurrent-' . $fd, 'app-a');
                    } catch (\OverflowException $exception) {
                        // Expected quota outcome; parent verifies exact shared accounting.
                    }
                    $process->exit(0);
                });
                $children[] = $child->start();
            }
            foreach ($children as $unused) {
                $exit = Process::wait();
                $this->assertIsArray($exit);
                $this->assertSame(0, $exit['code']);
            }
            $this->assertCount(3, $repository->allSubscriptions('app-a'));
            $repository->subscribe(50, 'other-app', 'app-b');
            $this->assertCount(1, $repository->allSubscriptions('app-b'));
            for ($fd = 1; $fd <= 12; $fd++) {
                $repository->unsubscribeAll($fd, 'app-a');
            }
            $repository->subscribe(51, 'recovered', 'app-a');
            $this->assertSame([51 => 'recovered'], $repository->subscribersOf('recovered', 'app-a'));
            $this->assertSame([50 => 'other-app'], $repository->subscribersOf('other-app', 'app-b'));
        } finally {
            $repository->destroyTable();
        }
    }

    public function testPendingWorkLimitsIncludeClaimedWorkAndReleaseRestoresCapacity(): void
    {
        $admission = new PusherAdmission(new PusherLimits(['pending_per_socket' => 1, 'pending_total' => 2]));
        $first = $admission->enqueue('generation-a', 'app-a', 10);
        $this->assertTrue($admission->claim($first, 'generation-a'));
        try {
            $admission->enqueue('generation-a', 'app-a', 10);
            $this->fail('Executing task did not occupy its socket budget');
        } catch (\OverflowException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $second = $admission->enqueue('generation-b', 'app-b', 10);
        try {
            $admission->enqueue('generation-c', 'app-c', 10);
            $this->fail('Global pending budget was exceeded');
        } catch (\OverflowException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $admission->release($first);
        $third = $admission->enqueue('generation-a', 'app-a', 10);
        $this->assertNotSame($first, $third);
        $this->assertFalse($admission->claim($second, 'wrong-generation'));
        $this->assertTrue($admission->claim($second, 'generation-b'));
    }

    public function testCompletedAndFailedTasksDoNotLeavePendingReservations(): void
    {
        $admission = new PusherAdmission(new PusherLimits(['pending_per_socket' => 1, 'pending_total' => 1]));
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $lease = $admission->enqueue('generation-a', 'app-a', 10);
            try {
                $this->assertTrue($admission->claim($lease, 'generation-a'));
                throw new \RuntimeException('Disposable task failure');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Disposable task failure', $exception->getMessage());
            } finally {
                $admission->release($lease);
            }
        }
        $admission->forget('generation-a');
        $this->assertIsString($admission->enqueue('generation-b', 'app-b', 10));
    }

    public function testPhysicalStorageFailureDoesNotCreatePhantomMembershipAndCapacityRecovers(): void
    {
        $repository = new PusherChannelRepository();
        $property = new \ReflectionProperty($repository, 'subscriptions');
        $property->setAccessible(true);
        $property->getValue($repository)->destroy();
        $table = new Table(64);
        $table->column('fd', Table::TYPE_INT, 8);
        $table->column('channel', Table::TYPE_STRING, 164);
        $table->column('app', Table::TYPE_STRING, 64);
        $table->column('generation', Table::TYPE_STRING, 40);
        $table->column('state', Table::TYPE_INT, 1);
        $table->column('pid', Table::TYPE_INT, 8);
        $table->create();
        $property->setValue($repository, $table);
        $failedFd = null;
        try {
            for ($fd = 1; $fd <= 1000; $fd++) {
                try {
                    $repository->subscribe($fd, 'storage', 'app-a');
                } catch (\Throwable $exception) {
                    $failedFd = $fd;
                    break;
                }
            }
            $this->assertNotNull($failedFd, 'Physical table failure was not exercised');
            $this->assertFalse($repository->isSubscribed($failedFd, 'storage', 'app-a'));
            $this->assertTrue($repository->isSubscribed(1, 'storage', 'app-a'));
            $repository->unsubscribe(1, 'storage', 'app-a');
            // Reusing the exact released identity avoids hash-bucket distribution assumptions.
            $repository->subscribe(1, 'storage', 'app-a');
            $this->assertTrue($repository->isSubscribed(1, 'storage', 'app-a'));
        } finally {
            $repository->destroyTable();
        }
    }

    public function testOversizedFrameDoesNotConsumePendingCapacity(): void
    {
        $admission = new PusherAdmission(new PusherLimits(['frame_bytes' => 128, 'pending_per_socket' => 1]));
        try {
            $admission->enqueue('generation-a', 'app-a', 129);
            $this->fail('Oversized frame was admitted');
        } catch (\OverflowException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertIsString($admission->enqueue('generation-a', 'app-a', 128));
    }
}

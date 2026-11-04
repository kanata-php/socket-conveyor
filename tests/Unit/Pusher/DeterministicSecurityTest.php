<?php

namespace Tests\Unit\Pusher;

use Conveyor\SubProtocols\Pusher\PusherAdmission;
use Conveyor\SubProtocols\Pusher\PusherLimits;
use Conveyor\SubProtocols\Pusher\SocketIdRepository;
use OpenSwoole\Process;
use Tests\Assets\PusherDeterministicSources;
use Tests\TestCase;

class DeterministicSecurityTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTokenBurstAndExactRefillBoundaryUseMonotonicTime(): void
    {
        require __DIR__ . '/../../Assets/PusherDeterministicSources.php';
        $admission = new PusherAdmission(new PusherLimits([
            'client_rate_socket' => 2, 'client_burst_socket' => 2,
        ]));
        $admission->event('app-a', 'socket-a');
        $admission->event('app-a', 'socket-a');
        $this->assertRateDenied($admission);
        PusherDeterministicSources::$nanoseconds += 499999999;
        $this->assertRateDenied($admission);
        PusherDeterministicSources::$nanoseconds++;
        $admission->event('app-a', 'socket-a');
        $this->assertRateDenied($admission);
        $admission->event('app-b', 'socket-b');
        $this->assertTrue(true, 'A depleted bucket did not affect another socket/app');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLiveIdentityCollisionRetriesWithoutOverwritingExistingMapping(): void
    {
        require __DIR__ . '/../../Assets/PusherDeterministicSources.php';
        PusherDeterministicSources::$randomValues = [10, 20, 10, 20, 30, 40];
        $repository = new SocketIdRepository();
        $this->assertSame('10.20', $repository->register(1, 'app-a'));
        $this->assertSame('30.40', $repository->register(2, 'app-b'));
        $this->assertSame(1, $repository->fdFor('10.20'));
        $this->assertSame(2, $repository->fdFor('30.40'));
        $this->assertSame(6, PusherDeterministicSources::$randomCalls);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testExhaustedCollisionRetriesAndEntropyFailureLeaveNoPartialIdentity(): void
    {
        require __DIR__ . '/../../Assets/PusherDeterministicSources.php';
        $repository = new SocketIdRepository();
        $this->assertSame('42.42', $repository->register(1, 'app-a'));
        try {
            $repository->register(2, 'app-b');
            $this->fail('Eight live collisions were accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame(18, PusherDeterministicSources::$randomCalls);
        }
        $this->assertNull($repository->socketIdFor(2));
        $this->assertNull($repository->appKeyFor(2));
        $this->assertSame(1, $repository->fdFor('42.42'));
        PusherDeterministicSources::$randomFails = true;
        try {
            $repository->register(3, 'app-b');
            $this->fail('Entropy failure was accepted');
        } catch (\RuntimeException $exception) {
            $this->assertNull($repository->socketIdFor(3));
            $this->assertNull($repository->appKeyFor(3));
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testKilledExecutingWorkerReservationIsReclaimedAfterItsLeaseBoundary(): void
    {
        require __DIR__ . '/../../Assets/PusherDeterministicSources.php';
        $admission = new PusherAdmission(new PusherLimits([
            'pending_per_socket' => 1, 'pending_total' => 1, 'task_timeout_seconds' => 1,
        ]));
        $lease = $admission->enqueue('generation-a', 'app-a', 10);
        $worker = new Process(function (Process $process) use ($admission, $lease): void {
            $process->write($admission->claim($lease, 'generation-a') ? 'claimed' : 'failed');
            sleep(30);
        });
        $pid = $worker->start();
        $this->assertIsInt($pid);
        $this->assertGreaterThan(0, $pid);
        try {
            $worker->setTimeout(2.0);
            $this->assertSame('claimed', $worker->read());
            Process::kill($pid, 9);
            Process::wait();
            PusherDeterministicSources::$nanoseconds += 1000000001;
            $this->assertIsString($admission->enqueue('generation-a', 'app-a', 10));
        } finally {
            if (is_dir('/proc/' . $pid)) {
                Process::kill($pid, 9);
                Process::wait();
            }
        }
    }

    private function assertRateDenied(PusherAdmission $admission): void
    {
        try {
            $admission->event('app-a', 'socket-a');
            $this->fail('Depleted token bucket accepted an event');
        } catch (\OverflowException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }
}

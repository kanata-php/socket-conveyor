<?php

namespace Tests\Unit\Pusher;

use Conveyor\Config\ConveyorOptions;
use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table\SocketPresenceChannelPersistenceTable;
use Conveyor\SubProtocols\Pusher\AppManager;
use Conveyor\SubProtocols\Pusher\PusherAdmission;
use Conveyor\SubProtocols\Pusher\PusherChannelRepository;
use Conveyor\SubProtocols\Pusher\PusherDiagnostics;
use Conveyor\SubProtocols\Pusher\PusherEventRouter;
use Conveyor\SubProtocols\Pusher\PusherLimits;
use Conveyor\SubProtocols\Pusher\PusherSigner;
use Conveyor\SubProtocols\Pusher\PusherValidation;
use Conveyor\SubProtocols\Pusher\SocketIdRepository;
use OpenSwoole\WebSocket\Server;
use Tests\TestCase;

class CloseFailureRecoveryTest extends TestCase
{
    /** @return array<string, array{string}> */
    public function faults(): array
    {
        return array_combine(
            $faults = ['enumerate', 'read', 'parse', 'mark', 'remove', 'readback', 'membership', 'roster'],
            array_map(fn(string $fault): array => [$fault], $faults),
        );
    }

    /**
     * @dataProvider faults
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEveryCloseStageRevokesIdentityAndReleasesAdmission(string $fault): void
    {
        ini_set('error_log', '/tmp/pusher-close-recovery.log');
        $limits = new PusherLimits([
            'connections_per_app' => 1, 'connections_total' => 2,
            'memberships_per_socket' => 1, 'memberships_per_app' => 1, 'memberships_total' => 2,
            'pending_per_socket' => 1, 'pending_total' => 2,
            'frame_burst_socket' => 1,
        ]);
        $ids = new SocketIdRepository($limits);
        $channels = new class ($ids, $limits) extends PusherChannelRepository {
            public string $fault = '';
            public function allSubscriptions(?string $appKey = null): array
            {
                $this->inject('enumerate');
                return parent::allSubscriptions($appKey);
            }
            public function mark(int $fd, string $channel, ?string $app, int $state): void
            {
                $this->inject('mark');
                parent::mark($fd, $channel, $app, $state);
            }
            public function remove(int $fd, string $channel, ?string $appKey = null): void
            {
                $this->inject('membership');
                parent::remove($fd, $channel, $appKey);
            }
            private function inject(string $stage): void
            {
                if ($this->fault === $stage) {
                    $this->fault = '';
                    throw new \RuntimeException('Injected repository close failure');
                }
            }
        };
        $presence = new class extends SocketPresenceChannelPersistenceTable {
            public string $fault = '';
            public int $reads = 0;
            public bool $blockCleanup = false;
            public function getMembers(string $channel): array
            {
                $this->reads++;
                if (
                    ($this->fault === 'read' && $this->reads === 1)
                    || ($this->fault === 'readback' && $this->reads === 2)
                    || ($this->fault === 'roster' && $this->reads === 3)
                ) {
                    throw new \RuntimeException('Injected adapter close read failure');
                }
                $members = parent::getMembers($channel);
                if ($this->fault === 'parse' && $this->reads === 1) {
                    $members[11] = 'invalid-json';
                }
                return $members;
            }
            public function remove(int $fd, string $channel): void
            {
                if ($this->fault === 'remove' || $this->blockCleanup) {
                    throw new \RuntimeException('Injected adapter close removal failure');
                }
                parent::remove($fd, $channel);
            }
        };
        $channels->usePresencePersistence($presence);
        $admission = new PusherAdmission($limits);
        $server = \Mockery::mock(Server::class);
        $server->shouldReceive('isEstablished')->andReturnTrue();
        $server->shouldReceive('push')->andReturnTrue();
        $router = new PusherEventRouter(
            $server,
            [Constants::PRESENCE_CHANNELS => $presence],
            $ids,
            $channels,
            new AppManager(ConveyorOptions::fromArray([Constants::APPS => [[
                'app_id' => 'local-app', 'key' => 'local-key', 'secret' => 'local-secret',
            ]]])),
            $admission,
        );
        $subscribe = function (int $fd, string $id) use ($router): void {
            $data = '{"user_id":"probe"}';
            $router->handle($fd, json_encode(['event' => 'pusher:subscribe', 'data' => [
                'channel' => 'presence-close', 'channel_data' => $data,
                'auth' => (new PusherSigner())->channelAuth(
                    'local-key',
                    'local-secret',
                    $id,
                    'presence-close',
                    $data,
                ),
            ]]), $id);
        };
        $id = $ids->register(11, 'local-key');
        $other = $ids->register(21, 'other-app');
        $channels->subscribe(21, 'public-other', 'other-app');
        $subscribe(11, $id);
        $lease = $admission->enqueue($id, 'local-key', 1);
        $otherLease = $admission->enqueue($other, 'other-app', 1);
        $journal = (new \ReflectionProperty($channels, 'subscriptions'))->getValue($channels);
        $counts = array_sum(PusherDiagnostics::counts());
        $channels->fault = $presence->fault = $fault;
        $presence->reads = 0;
        $router->handleClose(11, $id);
        $this->assertNull($ids->socketIdFor(11), $fault . ' must revoke the closed generation');
        $this->assertNull($ids->fdFor($id));
        $this->assertSame(1, (new \ReflectionProperty($admission, 'pending'))->getValue($admission)->count());
        $this->assertSame(3, (new \ReflectionProperty($admission, 'rates'))->getValue($admission)->count());
        $this->assertFalse($admission->claim($lease, $id), 'Closed generation cannot execute a queued task');
        $this->assertTrue($admission->claim($otherLease, $other), 'Unrelated admission is preserved');
        $this->assertGreaterThan($counts, array_sum(PusherDiagnostics::counts()), 'Fault remains diagnostic');
        $channels->fault = $presence->fault = '';
        $presence->blockCleanup = true;
        $channels->transaction(fn() => null);
        $this->assertSame([], $channels->allSubscriptions('local-key'));
        $this->assertSame(0, $router->roster('presence-close', 'local-key')['count']);
        $replacement = $ids->register(11, 'local-key');
        $replacementLease = $admission->enqueue($replacement, 'local-key', 1);
        $router->handleClose(11, $id);
        $this->assertSame($replacement, $ids->socketIdFor(11), 'Stale close cannot revoke replacement');
        $this->assertTrue($admission->claim($replacementLease, $replacement));
        $this->assertSame($other, $ids->socketIdFor(21));
        $this->assertTrue($channels->isSubscribed(21, 'public-other', 'other-app'));
        $presence->blockCleanup = false;
        $channels->transaction(fn() => null);
        $this->assertCount(0, $presence->getMembers(PusherValidation::scope('local-key', 'presence-close')));
        $this->assertSame(1, $journal->count(), 'Only unrelated membership remains charged');
        $subscribe(11, $replacement);
        $this->assertSame(2, $journal->count(), 'Exactly one membership slot is reusable');
        $this->assertSame(1, $router->roster('presence-close', 'local-key')['count']);
        $router->handleClose(11, $id);
        $this->assertSame($replacement, $ids->socketIdFor(11));
        $this->assertSame(1, $router->roster('presence-close', 'local-key')['count']);
        try {
            $ids->register(12, 'local-key');
            $this->fail('Reused connection capacity must still enforce its limit');
        } catch (\OverflowException $expected) {
            $this->assertNotEmpty($expected->getMessage());
        }
        // Socket-specific rate state is removed even when close did not finish its reads.
        $admission->release($otherLease);
        $admission->release($replacementLease);
        $resetLease = $admission->enqueue($id, 'local-key', 1);
        $this->assertTrue($admission->claim($resetLease, $id));
        $admission->release($resetLease);
        \Mockery::close();
    }
}

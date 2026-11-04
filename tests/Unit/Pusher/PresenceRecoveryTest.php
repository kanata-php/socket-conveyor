<?php

namespace Tests\Unit\Pusher;

use Conveyor\Config\ConveyorOptions;
use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table\SocketPresenceChannelPersistenceTable;
use Conveyor\SubProtocols\Pusher\AppManager;
use Conveyor\SubProtocols\Pusher\PusherChannelRepository;
use Conveyor\SubProtocols\Pusher\PusherEventRouter;
use Conveyor\SubProtocols\Pusher\PusherLimits;
use Conveyor\SubProtocols\Pusher\PusherSigner;
use Conveyor\SubProtocols\Pusher\PusherValidation;
use Conveyor\SubProtocols\Pusher\SocketIdRepository;
use OpenSwoole\Process;
use OpenSwoole\WebSocket\Server;
use Tests\TestCase;

class PresenceRecoveryTest extends TestCase
{
    /** @return array<string, array{string}> */
    public function failures(): array
    {
        return ['close' => ['close'], 'rollback' => ['rollback'], 'killed rollback' => ['kill']];
    }

    /**
     * @dataProvider failures
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFailedCleanupRetainsJournalUntilVerifiedRecovery(string $mode): void
    {
        ini_set('error_log', '/tmp/pusher-presence-recovery.log');
        $limits = new PusherLimits([
            'memberships_total' => 1, 'memberships_per_app' => 1, 'memberships_per_socket' => 1,
        ]);
        $ids = new SocketIdRepository();
        $channels = new PusherChannelRepository($ids, $limits);
        $presence = new class extends SocketPresenceChannelPersistenceTable {
            public bool $failAdd = false;
            public bool $failRemove = false;
            public bool $killRemove = false;
            public function add(int $fd, string $channel, string $channelData): void
            {
                parent::add($fd, $channel, $channelData);
                if ($this->failAdd) {
                    throw new \RuntimeException('Injected partial add');
                }
            }
            public function remove(int $fd, string $channel): void
            {
                if ($this->killRemove) {
                    posix_kill(getmypid(), SIGKILL);
                }
                if ($this->failRemove) {
                    throw new \RuntimeException('Injected remove');
                }
                parent::remove($fd, $channel);
            }
        };
        $channels->usePresencePersistence($presence);
        $server = \Mockery::mock(Server::class);
        $sent = [];
        $server->shouldReceive('isEstablished')->andReturnTrue();
        $server->shouldReceive('push')->andReturnUsing(function ($fd, $data) use (&$sent): bool {
            $sent[] = json_decode($data, true)['event'];
            return true;
        });
        $manager = new AppManager(ConveyorOptions::fromArray([Constants::APPS => [[
            'app_id' => 'local-app', 'key' => 'local-key', 'secret' => 'local-secret',
        ]]]));
        $router = new PusherEventRouter(
            $server,
            [Constants::PRESENCE_CHANNELS => $presence],
            $ids,
            $channels,
            $manager,
        );
        $subscribe = function (int $fd) use ($router, $ids): string {
            $id = $ids->register($fd, 'local-key');
            $data = '{"user_id":"probe"}';
            $router->handle($fd, json_encode(['event' => 'pusher:subscribe', 'data' => [
                'channel' => 'presence-recovery', 'channel_data' => $data,
                'auth' => (new PusherSigner())->channelAuth(
                    'local-key',
                    'local-secret',
                    $id,
                    'presence-recovery',
                    $data,
                ),
            ]]), $id);
            return $id;
        };
        $scope = PusherValidation::scope('local-key', 'presence-recovery');
        $journal = (new \ReflectionProperty($channels, 'subscriptions'))->getValue($channels);
        if ($mode === 'close') {
            $id = $subscribe(11);
            $sent = [];
            $presence->failRemove = true;
            $router->handleClose(11, $id);
            $this->assertNull($ids->socketIdFor(11));
        } else {
            $presence->failAdd = true;
            if ($mode === 'kill') {
                $child = new Process(function () use ($presence, $subscribe): void {
                    $presence->killRemove = true;
                    $subscribe(11);
                });
                $child->start();
                $exit = Process::wait();
                $this->assertSame(SIGKILL, $exit['signal']);
                $presence->failRemove = true;
            } else {
                $presence->failRemove = true;
                $subscribe(11);
            }
        }
        $this->assertSame(1, $journal->count(), 'Cleanup failure must retain its quota charge');
        $this->assertCount(1, $presence->getMembers($scope));
        $channels->transaction(fn() => null);
        $this->assertSame(1, $journal->count(), 'Repeated failure must keep the journal');
        $this->assertSame([], $channels->allSubscriptions('local-key'));
        $this->assertSame(0, $router->roster('presence-recovery', 'local-key')['count']);
        $this->assertNotContains('pusher_internal:subscription_succeeded', $sent);
        $ids->forget(11);
        $replacement = $ids->register(11, 'local-key');
        $subscribe(11);
        $this->assertSame(1, $journal->count(), 'Replacement cannot overwrite unresolved fd/scope');
        $presence->failAdd = $presence->failRemove = false;
        $channels->transaction(fn() => null);
        $this->assertCount(0, $presence->getMembers($scope));
        $this->assertSame(0, $journal->count());
        $this->assertSame($replacement, $ids->socketIdFor(11));
        $subscribe(11);
        $this->assertSame(1, $journal->count());
        $this->assertSame(1, $router->roster('presence-recovery', 'local-key')['count']);
        $this->assertSame($replacement, $ids->socketIdFor(11));
        $router->handleClose(11, 'old-generation');
        $this->assertCount(1, $presence->getMembers($scope));
        $this->assertTrue($channels->isSubscribed(11, 'presence-recovery', 'local-key'));
    }
}

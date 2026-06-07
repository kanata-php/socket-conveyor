<?php

namespace Tests\Feature;

use Conveyor\Constants;
use Conveyor\ConveyorServer;
use Conveyor\SubProtocols\Conveyor\Conveyor;
use Conveyor\SubProtocols\Pusher\Frame\PusherEvent;
use Conveyor\SubProtocols\Pusher\PusherSigner;
use Exception;
use GuzzleHttp\Client as HttpClient;
use Hook\Action;
use OpenSwoole\Process;
use Tests\TestCase;
use WebSocket\Client as WsClient;
use WebSocket\TimeoutException;

class PusherWebSocketTest extends TestCase
{
    public int $port = 8990;

    private string $appKey = '278d425bdf160c739803';
    private string $appSecret = '7ad3773142a6692b25b8';
    private string $appId = '1';

    protected function getServerProcesses(): false|string
    {
        return exec('lsof -i -P -n | grep LISTEN | grep ' . $this->port);
    }

    /**
     * @before
     * @after
     * @throws Exception
     */
    public function tearServerDown(): void
    {
        exec('lsof -i -P -n '
            . '| grep LISTEN '
            . '| grep ' . $this->port . ' '
            . '| awk \'{print $2}\' '
            . '| xargs -I {} kill -9 {} > /dev/null  2>&1');

        $output = $this->getServerProcesses();
        if (!empty($output)) {
            throw new Exception('Failed to kill server. Output: ' . $output);
        }
    }

    /**
     * @throws Exception
     */
    protected function startServer(?callable $beforeStart = null): int
    {
        Conveyor::refresh();

        $httpServer = new Process(function (Process $worker) use ($beforeStart) {
            if ($beforeStart !== null) {
                $beforeStart();
            }

            (new ConveyorServer())
                ->port($this->port)
                ->serverOptions([
                    'worker_num' => 1,
                    'task_worker_num' => 1,
                ])
                ->conveyorOptions([
                    Constants::WEBSOCKET_SUBPROTOCOL => Constants::PUSHER,
                    Constants::USE_PRESENCE => true,
                    Constants::APPS => [[
                        'app_id' => '1',
                        'key' => $this->appKey,
                        'secret' => $this->appSecret,
                        'enable_client_messages' => true,
                        'enabled' => true,
                    ]],
                ])
                ->start();
        });

        $pid = $httpServer->start();

        $counter = 0;
        $threshold = 10;
        while (empty($this->getServerProcesses()) && $counter < $threshold) {
            $counter++;
            sleep(1);
        }

        return $pid;
    }

    private function newClient(): WsClient
    {
        return new WsClient(
            'ws://127.0.0.1:' . $this->port . '/app/' . $this->appKey,
            ['timeout' => 5],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function receiveFrame(WsClient $client): array
    {
        $decoded = json_decode($client->receive(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Connect, read the connection_established frame and return the socket_id.
     */
    private function connect(WsClient $client): string
    {
        $frame = $this->receiveFrame($client);
        $this->assertEquals(PusherEvent::CONNECTION_ESTABLISHED, $frame['event']);

        $data = json_decode($frame['data'], true);
        $this->assertArrayHasKey('socket_id', $data);
        $this->assertNotEmpty($data['socket_id']);

        return $data['socket_id'];
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array{status: int, body: string}
     */
    private function signedPost(string $path, array $body): array
    {
        $signer = new PusherSigner();
        $rawBody = json_encode($body);
        $params = [
            'auth_key' => $this->appKey,
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
            'body_md5' => $signer->bodyMd5($rawBody),
        ];
        $params['auth_signature'] = $signer->requestSignature($this->appSecret, 'POST', $path, $params);

        $response = (new HttpClient(['http_errors' => false]))->post(
            'http://127.0.0.1:' . $this->port . $path,
            [
                'query' => $params,
                'body' => $rawBody,
                'headers' => ['Content-Type' => 'application/json'],
            ],
        );

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * @throws Exception
     */
    public function testConnectionEstablishedAndPublicSubscribe(): void
    {
        $serverPid = $this->startServer();

        $client = $this->newClient();
        $this->connect($client);

        $client->send(json_encode([
            'event' => PusherEvent::SUBSCRIBE,
            'data' => ['channel' => 'my-channel'],
        ]));

        $frame = $this->receiveFrame($client);

        $this->assertEquals(PusherEvent::SUBSCRIPTION_SUCCEEDED, $frame['event']);
        $this->assertEquals('my-channel', $frame['channel']);
        $this->assertEquals('{}', $frame['data']);

        $client->close();
        Process::kill($serverPid);
    }

    /**
     * @throws Exception
     */
    public function testPusherIncomingMessageHookReceivesRawFrames(): void
    {
        $hookLog = tempnam(sys_get_temp_dir(), 'conveyor-pusher-hook-');
        $this->assertIsString($hookLog);

        $serverPid = $this->startServer(function () use ($hookLog): void {
            Action::addAction(
                Constants::ACTION_PUSHER_MESSAGE_RECEIVED,
                function (int $fd, string $raw, array $frame) use ($hookLog): void {
                    file_put_contents($hookLog, json_encode([
                        'fd' => $fd,
                        'raw' => $raw,
                        'event' => $frame['event'] ?? null,
                    ]) . PHP_EOL, FILE_APPEND);
                },
            );
        });

        $client = $this->newClient();
        $this->connect($client);

        $client->send(json_encode([
            'event' => PusherEvent::SUBSCRIBE,
            'data' => ['channel' => 'hook-check'],
        ]));

        $this->assertEquals(PusherEvent::SUBSCRIPTION_SUCCEEDED, $this->receiveFrame($client)['event']);

        $lines = file($hookLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);

        $last = json_decode((string) end($lines), true);
        $this->assertIsArray($last);
        $this->assertEquals(PusherEvent::SUBSCRIBE, $last['event']);
        $this->assertStringContainsString('hook-check', $last['raw']);

        $client->close();
        Process::kill($serverPid);
        unlink($hookLog);
    }

    /**
     * @throws Exception
     */
    public function testPusherRestEventHookReceivesPublishedEvents(): void
    {
        $hookLog = tempnam(sys_get_temp_dir(), 'conveyor-pusher-rest-hook-');
        $this->assertIsString($hookLog);

        $serverPid = $this->startServer(function () use ($hookLog): void {
            Action::addAction(
                Constants::ACTION_PUSHER_REST_EVENT_RECEIVED,
                function (
                    array $payload,
                    string $name,
                    array $channels,
                    string $data,
                    ?string $socketId,
                ) use ($hookLog): void {
                    file_put_contents($hookLog, json_encode([
                        'name' => $name,
                        'channels' => $channels,
                        'data' => $data,
                        'socket_id' => $socketId,
                    ]) . PHP_EOL, FILE_APPEND);
                },
            );
        });

        $response = $this->signedPost('/apps/' . $this->appId . '/events', [
            'name' => 'DemoEvent',
            'channel' => 'presence-room.1',
            'data' => '{"message":"hello from tinker"}',
        ]);

        $this->assertEquals(200, $response['status']);

        $lines = file($hookLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);

        $last = json_decode((string) end($lines), true);
        $this->assertIsArray($last);
        $this->assertEquals('DemoEvent', $last['name']);
        $this->assertEquals(['presence-room.1'], $last['channels']);
        $this->assertEquals('{"message":"hello from tinker"}', $last['data']);

        Process::kill($serverPid);
        unlink($hookLog);
    }

    /**
     * @throws Exception
     */
    public function testPrivateSubscribeSignatureValidation(): void
    {
        $serverPid = $this->startServer();
        $signer = new PusherSigner();

        // Valid signature -> subscription succeeds.
        $good = $this->newClient();
        $socketId = $this->connect($good);
        $auth = $signer->channelAuth($this->appKey, $this->appSecret, $socketId, 'private-room');

        $good->send(json_encode([
            'event' => PusherEvent::SUBSCRIBE,
            'data' => ['channel' => 'private-room', 'auth' => $auth],
        ]));

        $frame = $this->receiveFrame($good);
        $this->assertEquals(PusherEvent::SUBSCRIPTION_SUCCEEDED, $frame['event']);
        $this->assertEquals('private-room', $frame['channel']);

        // Bad signature -> pusher:error 4009.
        $bad = $this->newClient();
        $this->connect($bad);

        $bad->send(json_encode([
            'event' => PusherEvent::SUBSCRIBE,
            'data' => ['channel' => 'private-room', 'auth' => $this->appKey . ':deadbeef'],
        ]));

        $frame = $this->receiveFrame($bad);
        $this->assertEquals(PusherEvent::ERROR, $frame['event']);
        $error = json_decode($frame['data'], true);
        $this->assertEquals(PusherEvent::ERROR_UNAUTHORIZED, $error['code']);

        $good->close();
        $bad->close();
        Process::kill($serverPid);
    }

    /**
     * @throws Exception
     */
    public function testPresenceMembershipAndClientEvents(): void
    {
        $serverPid = $this->startServer();
        $signer = new PusherSigner();
        $channel = 'presence-room';

        // First member: Alice.
        $alice = $this->newClient();
        $aliceSocket = $this->connect($alice);
        $aliceData = json_encode(['user_id' => '100', 'user_info' => ['name' => 'Alice']]);
        $alice->send(json_encode([
            'event' => PusherEvent::SUBSCRIBE,
            'data' => [
                'channel' => $channel,
                'auth' => $signer->channelAuth($this->appKey, $this->appSecret, $aliceSocket, $channel, $aliceData),
                'channel_data' => $aliceData,
            ],
        ]));

        $frame = $this->receiveFrame($alice);
        $this->assertEquals(PusherEvent::SUBSCRIPTION_SUCCEEDED, $frame['event']);
        $roster = json_decode($frame['data'], true);
        $this->assertEquals(1, $roster['presence']['count']);
        $this->assertContains('100', $roster['presence']['ids']);

        // Second member: Bob.
        $bob = $this->newClient();
        $bobSocket = $this->connect($bob);
        $bobData = json_encode(['user_id' => '200', 'user_info' => ['name' => 'Bob']]);
        $bob->send(json_encode([
            'event' => PusherEvent::SUBSCRIBE,
            'data' => [
                'channel' => $channel,
                'auth' => $signer->channelAuth($this->appKey, $this->appSecret, $bobSocket, $channel, $bobData),
                'channel_data' => $bobData,
            ],
        ]));

        $frame = $this->receiveFrame($bob);
        $this->assertEquals(PusherEvent::SUBSCRIPTION_SUCCEEDED, $frame['event']);
        $roster = json_decode($frame['data'], true);
        $this->assertEquals(2, $roster['presence']['count']);

        // Alice is told about Bob joining.
        $frame = $this->receiveFrame($alice);
        $this->assertEquals(PusherEvent::MEMBER_ADDED, $frame['event']);
        $added = json_decode($frame['data'], true);
        $this->assertEquals('200', (string) $added['user_id']);

        // Bob whispers a client event; Alice receives it, Bob does not.
        $bob->send(json_encode([
            'event' => 'client-typing',
            'channel' => $channel,
            'data' => ['typing' => true],
        ]));

        $frame = $this->receiveFrame($alice);
        $this->assertEquals('client-typing', $frame['event']);
        $this->assertEquals($channel, $frame['channel']);

        $bob->setTimeout(1);
        $echoed = false;
        try {
            $bob->receive();
            $echoed = true;
        } catch (TimeoutException $e) {
            // Expected: senders never receive their own client events.
        }
        $this->assertFalse($echoed, 'Sender must not receive its own client event.');

        // Bob disconnects; Alice is told he left.
        $bob->close();

        $frame = $this->receiveFrame($alice);
        $this->assertEquals(PusherEvent::MEMBER_REMOVED, $frame['event']);
        $removed = json_decode($frame['data'], true);
        $this->assertEquals('200', (string) $removed['user_id']);

        $alice->close();
        Process::kill($serverPid);
    }

    /**
     * @throws Exception
     */
    public function testSignedRestEventPublishesToSubscribersAndHonorsSocketExclusion(): void
    {
        $serverPid = $this->startServer();
        $channel = 'orders.1';

        $alice = $this->newClient();
        $aliceSocket = $this->connect($alice);
        $alice->send(json_encode([
            'event' => PusherEvent::SUBSCRIBE,
            'data' => ['channel' => $channel],
        ]));
        $this->assertEquals(PusherEvent::SUBSCRIPTION_SUCCEEDED, $this->receiveFrame($alice)['event']);

        $bob = $this->newClient();
        $this->connect($bob);
        $bob->send(json_encode([
            'event' => PusherEvent::SUBSCRIBE,
            'data' => ['channel' => $channel],
        ]));
        $this->assertEquals(PusherEvent::SUBSCRIPTION_SUCCEEDED, $this->receiveFrame($bob)['event']);

        $response = $this->signedPost('/apps/' . $this->appId . '/events', [
            'name' => 'OrderShipped',
            'channels' => [$channel],
            'data' => '{"id":1,"total":99}',
            'socket_id' => $aliceSocket,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals('{}', $response['body']);

        $frame = $this->receiveFrame($bob);
        $this->assertEquals('OrderShipped', $frame['event']);
        $this->assertEquals($channel, $frame['channel']);
        $this->assertEquals(['id' => 1, 'total' => 99], json_decode($frame['data'], true));

        $alice->setTimeout(1);
        $excluded = false;
        try {
            $alice->receive();
            $excluded = true;
        } catch (TimeoutException $e) {
            // Expected: REST socket_id exclusion implements Laravel toOthers().
        }
        $this->assertFalse($excluded, 'Excluded socket must not receive REST-triggered event.');

        $alice->close();
        $bob->close();
        Process::kill($serverPid);
    }

    /**
     * @throws Exception
     */
    public function testOneSocketCanStaySubscribedToMultipleChannels(): void
    {
        $serverPid = $this->startServer();

        $client = $this->newClient();
        $this->connect($client);

        foreach (['orders.1', 'orders.2'] as $channel) {
            $client->send(json_encode([
                'event' => PusherEvent::SUBSCRIBE,
                'data' => ['channel' => $channel],
            ]));

            $frame = $this->receiveFrame($client);
            $this->assertEquals(PusherEvent::SUBSCRIPTION_SUCCEEDED, $frame['event']);
            $this->assertEquals($channel, $frame['channel']);
        }

        foreach (['orders.1' => 1, 'orders.2' => 2] as $channel => $id) {
            $response = $this->signedPost('/apps/' . $this->appId . '/events', [
                'name' => 'OrderShipped',
                'channels' => [$channel],
                'data' => json_encode(['id' => $id]),
            ]);

            $this->assertEquals(200, $response['status']);

            $frame = $this->receiveFrame($client);
            $this->assertEquals('OrderShipped', $frame['event']);
            $this->assertEquals($channel, $frame['channel']);
            $this->assertEquals(['id' => $id], json_decode($frame['data'], true));
        }

        $client->close();
        Process::kill($serverPid);
    }
}

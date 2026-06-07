<?php

namespace Tests\Feature;

use Conveyor\Constants;
use Conveyor\ConveyorServer;
use Conveyor\SubProtocols\Conveyor\Actions\ChannelConnectAction;
use Conveyor\SubProtocols\Conveyor\Conveyor;
use Exception;
use GuzzleHttp\Client as HttpClient;
use OpenSwoole\Process;
use Tests\TestCase;
use WebSocket\Client as WsClient;

class NativeAuthTokenTest extends TestCase
{
    private int $port = 8991;

    private string $serverToken = 'local-server-token';

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
    private function startServer(): int
    {
        Conveyor::refresh();

        $httpServer = new Process(function (Process $worker) {
            (new ConveyorServer())
                ->port($this->port)
                ->serverOptions([
                    'worker_num' => 1,
                    'task_worker_num' => 1,
                ])
                ->conveyorOptions([
                    Constants::WEBSOCKET_SERVER_TOKEN => $this->serverToken,
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

    /**
     * @return array{status: int, body: string}
     */
    private function post(string $path, array $body): array
    {
        $response = (new HttpClient(['http_errors' => false]))->post(
            'http://127.0.0.1:' . $this->port . $path,
            [
                'body' => json_encode($body),
                'headers' => ['Content-Type' => 'application/json'],
            ],
        );

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receiveFrame(WsClient $client): array
    {
        $decoded = json_decode($client->receive(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function newClient(string $token): WsClient
    {
        return new WsClient(
            'ws://127.0.0.1:' . $this->port . '/?token=' . urlencode($token),
            ['timeout' => 5],
        );
    }

    /**
     * @throws Exception
     */
    public function testTemporaryChannelTokenAllowsNativeClientAndIsConsumedAfterUse(): void
    {
        $serverPid = $this->startServer();

        try {
            $authResponse = $this->post('/conveyor/auth?token=' . $this->serverToken, [
                'channel' => 'orders.1',
            ]);

            $this->assertEquals(200, $authResponse['status']);

            $authBody = json_decode($authResponse['body'], true);
            $this->assertIsArray($authBody);
            $this->assertArrayHasKey('auth', $authBody);
            $this->assertNotEmpty($authBody['auth']);

            $client = $this->newClient($authBody['auth']);
            $connection = $this->receiveFrame($client);
            $this->assertEquals(Constants::ACTION_CONNECTION_INFO, $connection['action']);

            $client->send(json_encode([
                'action' => ChannelConnectAction::NAME,
                'channel' => 'orders.1',
                'auth' => $authBody['auth'],
            ]));

            sleep(1);

            $messageResponse = $this->post('/conveyor/message?token=' . $this->serverToken, [
                'channel' => 'orders.1',
                'message' => 'order updated',
            ]);

            $this->assertEquals(200, $messageResponse['status']);

            $message = $this->receiveFrame($client);
            $this->assertEquals('broadcast-action', $message['action']);
            $this->assertEquals('order updated', $message['data']);

            $secondClient = $this->newClient($this->serverToken);
            $this->assertEquals(Constants::ACTION_CONNECTION_INFO, $this->receiveFrame($secondClient)['action']);

            $secondClient->send(json_encode([
                'action' => ChannelConnectAction::NAME,
                'channel' => 'orders.1',
                'auth' => $authBody['auth'],
            ]));

            $failure = $this->receiveFrame($secondClient);
            $this->assertEquals(ChannelConnectAction::NAME, $failure['action']);
            $this->assertEquals('Failed to connect to channel', $failure['data']);

            $client->close();
            $secondClient->close();
        } finally {
            Process::kill($serverPid);
        }
    }
}

<?php

namespace Tests\Feature;

use Conveyor\Constants;
use Conveyor\ConveyorServer;
use Conveyor\SubProtocols\Conveyor\Actions\ChannelConnectAction;
use Conveyor\SubProtocols\Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\SubProtocols\Conveyor\Conveyor;
use Exception;
use Hook\Filter;
use JetBrains\PhpStorm\NoReturn;
use Kanata\ConveyorServerClient\Client;
use OpenSwoole\Atomic;
use OpenSwoole\Process;
use Tests\Assets\SampleAction;
use Tests\TestCase;

class OpenSwooleSocketTest extends TestCase
{
    use ServerProcessCleanup;

    public int $port = 8989;

    private function waitForCounter(Atomic $counter, int $expected, float $timeout): void
    {
        // Atomic::wait()/wakeup() use the value as a semaphore on OpenSwoole 25;
        // they must not share storage with a numeric message count.
        $deadline = microtime(true) + $timeout;
        while ($counter->get() < $expected && microtime(true) < $deadline) {
            usleep(10000);
        }
    }

    /**
     * @param array<array-key, ActionInterface> $actions
     * @return int
     * @throws Exception
     */
    protected function startServer(
        array $actions = [],
        bool $usePresence = false,
    ): int {
        Conveyor::refresh();

        $httpServer = new Process(function (Process $worker) use (
            $actions,
            $usePresence
        ) {
            (new ConveyorServer())
                ->port($this->port)
                ->conveyorOptions([
                    Constants::ACTIONS => $actions,
                    Constants::USE_PRESENCE => $usePresence,
                ])
                ->start();
        });

        $pid = $httpServer->start();
        $this->serverPid = $pid;

        $counter = 0;
        $threshold = 10; // seconds
        while (
            empty($this->getServerProcesses())
            && $counter < $threshold
        ) {
            $counter++;
            sleep(1);
        }

        echo "Server started..." . PHP_EOL . PHP_EOL;

        return $pid;
    }

    /**
     * @throws Exception
     */
    #[NoReturn]
    public function testCanInitializeRouter()
    {
        $serverPid = $this->startServer();

        $clientsNumber = 10;
        $totalMessages = 10;
        $totalMessagesTimes = 5;
        $expectedTotal = 500;
        $workers = [];
        $counter = new Atomic(0);

        $electedProcess = null;
        for ($i = 0; $i < $clientsNumber; $i++) {
            $process = new Process(function (Process $worker) use (
                $totalMessages,
                $totalMessagesTimes,
                $electedProcess,
                $counter,
                $expectedTotal,
            ) {
                $client = new Client([
                    'port' => $this->port,
                    'channel' => 'test',
                    'onReadyCallback' => function (Client $currentClient) use ($totalMessagesTimes) {
                        sleep(1);
                        for ($i = 0; $i < $totalMessagesTimes; $i++) {
                            $currentClient->send('test-' . $i);
                        }
                    },
                    'onMessageCallback' => function (
                        Client $currentClient,
                        string $message
                    ) use (
                        $counter,
                    ) {
                        $parsedData = json_decode($message, true);
                        if (substr($parsedData['data'], 0, 5) === 'test-') {
                            $counter->add(1);
                        }
                    },
                ]);
                $client->connect();
            });
            $process->start();
            if ($electedProcess === null) {
                $electedProcess = $process;
            }
            $workers[$process->pid] = $process;
        }

        $this->waitForCounter($counter, $expectedTotal, 10);

        foreach ($workers as $pid => $worker) {
            Process::kill($pid);
        }
        Process::kill($serverPid);

        $this->assertEquals($expectedTotal, $counter->get());
    }

    /**
     * @throws Exception
     */
    #[NoReturn]
    public function testCanAddCustomActionThroughServer()
    {
        $serverPid = $this->startServer([new SampleAction()]);

        $expectedTotal = 1;
        $counter = new Atomic(0);

        $this->assertEquals(0, $counter->get());

        $process = new Process(function (Process $worker) use ($counter) {
            $client = new Client([
                'port' => $this->port,
                'onReadyCallback' => function (Client $currentClient) {
                    sleep(1);
                    $currentClient->sendRaw(json_encode([
                        'action' => SampleAction::NAME,
                        'data' => 'done',
                    ]));
                },
                'onMessageCallback' => function (
                    Client $currentClient,
                    string $message,
                ) use ($counter) {
                    $parsedData = json_decode($message, true);

                    if ($parsedData['action'] === Constants::ACTION_CONNECTION_INFO) {
                        return;
                    }

                    $counter->add(1);
                    if ($parsedData['data'] === 'done') {
                        $counter->add(1);
                    }
                },
            ]);
            $client->connect();
        });
        $pid = $process->start();

        $this->waitForCounter($counter, $expectedTotal, 2);
        Process::kill($pid);
        Process::kill($serverPid);

        $this->assertEquals($expectedTotal, $counter->get());
    }

    /**
     * @throws Exception
     */
    #[NoReturn]
    public function testCanFilterPresenceMessageConnect()
    {
        $expectedName = 'John';

        Filter::addFilter(
            tag: Constants::FILTER_PRESENCE_MESSAGE_CONNECT,
            functionToAdd: function (array $data) use ($expectedName) {
                $parsedData = json_decode($data['data'], true);
                $parsedData['userIds'] = [1];
                $parsedData['users'] = [
                    1 => $expectedName,
                ];
                $data['data'] = $parsedData;
                return $data;
            },
        );

        $serverPid = $this->startServer(
            usePresence: true,
        );

        $expectedTotal = 2;
        $counter = new Atomic(0);


        $this->assertEquals(0, $counter->get());

        $process = new Process(function (Process $worker) use ($counter, $expectedName) {
            $client = new Client([
                'port' => $this->port,
                'onReadyCallback' => function (Client $currentClient) {
                    $currentClient->sendRaw(json_encode([
                        'action' => ChannelConnectAction::NAME,
                        'channel' => 'sample-channel',
                    ]));
                },
                'onMessageCallback' => function (
                    Client $currentClient,
                    string $message,
                ) use (
                    $counter,
                    $expectedName
                ) {
                    $parsedData = json_decode($message, true);

                    if ($parsedData['action'] === Constants::ACTION_CONNECTION_INFO) {
                        return;
                    }

                    $counter->add(1);
                    if ($parsedData['data']['users'][1] === $expectedName) {
                        $counter->add(1);
                    }
                },
            ]);
            $client->connect();
        });
        $pid = $process->start();

        $this->waitForCounter($counter, $expectedTotal, 2);
        Process::kill($pid);
        Process::kill($serverPid);

        $this->assertEquals($expectedTotal, $counter->get());
    }

    /**
     * @throws Exception
     * @todo
     */
    // #[NoReturn]
    // public function testCanFilterPresenceMessageDisconnect()
    // {
    //     $expectedName = 'John';
    //
    //     Filter::addFilter(
    //         tag: Constants::FILTER_PRESENCE_MESSAGE_DISCONNECT,
    //         functionToAdd: function (array $data) use ($expectedName) {
    //             $data['data']['userIds'] = [1];
    //             $data['data']['users'] = [
    //                 1 => $expectedName,
    //             ];
    //             return $data;
    //         },
    //     );
    //
    //     $serverPid = $this->startServer(
    //         usePresence: true,
    //     );
    //
    //     $expectedTotal = 2;
    //     $counter = new Atomic(0);
    //
    //
    //     $this->assertEquals(0, $counter->get());
    //
    //     $process = new Process(function (Process $worker) use ($counter, $expectedName) {
    //         $client = new Client([
    //             'port' => $this->port,
    //             'onReadyCallback' => function (Client $currentClient) {
    //                 $currentClient->sendRaw(json_encode([
    //                     'action' => ChannelConnectAction::NAME,
    //                     'channel' => 'sample-channel',
    //                 ]));
    //             },
    //             'onMessageCallback' => function (
    //                 Client $currentClient,
    //                 string $message,
    //             ) use ($counter, $expectedName) {
    //                 $parsedData = json_decode($message, true);
    //                 $counter->add(1);
    //                 if ($parsedData['data']['users'][1] === $expectedName) {
    //                     $counter->add(1);
    //                 }
    //                 $counter->wakeup();
    //             },
    //         ]);
    //         $client->connect();
    //     });
    //     $pid = $process->start();
    //
    //     $counter->wait(2);
    //     Process::kill($pid);
    //     Process::kill($serverPid);
    //
    //     $this->assertEquals($expectedTotal, $counter->get());
    // }
}

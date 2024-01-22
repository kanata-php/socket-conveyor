<?php

namespace Tests\Feature;

use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\Conveyor;
use Conveyor\ConveyorServer;
use Conveyor\ConveyorWorker;
use Exception;
use JetBrains\PhpStorm\NoReturn;
use Kanata\ConveyorServerClient\Client;
use OpenSwoole\Atomic;
use OpenSwoole\Process;
use Tests\Assets\SampleAction;
use Tests\TestCase;
use OpenSwoole\Table;

class OpenSwooleSocketTest extends TestCase
{
    public int $port = 8989;

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
        // kill processes
        exec('lsof -i -P -n '
            . '| grep LISTEN '
            . '| grep ' . $this->port . ' '
            . '| awk \'{print $2}\' '
            . '| xargs -I {} kill -9 {} > /dev/null  2>&1');

        // verify
        $output2 = $this->getServerProcesses();
        if (!empty($output2)) {
            throw new Exception('Failed to kill server. Output: ' . $output2);
        }
    }

    /**
     * @param array<array-key, ActionInterface> $actions
     * @return int
     * @throws Exception
     */
    protected function startServer(array $actions = []): int
    {
        Conveyor::refresh();

        $httpServer = new Process(function (Process $worker) use ($actions) {
            ConveyorServer::start(
                port: $this->port,
                conveyorOptions: [
                    ConveyorWorker::ACTIONS => $actions,
                ],
            );
        });

        $pid = $httpServer->start();

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
        $expectedTotal = 450;
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
                        $expectedTotal,
                    ) {
                        $parsedData = json_decode($message, true);
                        if (substr($parsedData['data'], 0, 5) === 'test-') {
                            $counter->add(1);
                        }

                        if ($counter->get() >= $expectedTotal) {
                            $counter->wakeup();
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

        while ($counter->get() < $expectedTotal) {
            $counter->wait(0.5);
        }

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
                    $counter->add(1);
                    if ($parsedData['data'] === 'done') {
                        $counter->add(1);
                    }
                    $counter->wakeup();
                },
            ]);
            $client->connect();
        });
        $pid = $process->start();

        $counter->wait(2);
        Process::kill($pid);
        Process::kill($serverPid);

        $this->assertEquals($expectedTotal, $counter->get());
    }
}

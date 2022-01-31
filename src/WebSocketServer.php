<?php

namespace Conveyor;

use Swoole\Process;
use Swoole\Timer;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine\System;
use Swoole\WebSocket\Frame;
use Swoole\Table;
use function Co\run;

/**
 * WebSocketServer
 *
 * This is a managed WebSocket Server. With it, it is possible to control via UI whether the
 * server is on of off.
 *
 * This class us customizable so depending on the project you can have a different UI or
 * different configurations.
 *
 * This is for Linux only. For this to work properly the following packages are necessary:
 *   - PHP 8
 *   - OpenSwoole PHP Extension
 */

class WebSocketServer
{
    const SERVER_DEAD = 'dead';
    const SERVER_ALIVE = 'alive';

    /**
     * Key for http status.
     *
     * @var string
     */
    protected string $ws_status_table_key = 'ws_status';

    /**
     * Actions table with which commands are sent.
     *
     * @var Table
     */
    protected Table $actions_table;

    /**
     * Key for ws communication.
     *
     * @var string
     */
    protected string $ws_table_key = 'ws_input';

    /**
     * WS/HTTP interface  for server control.
     *
     * @var Process
     */
    protected Process $ws_monitor_process;

    protected Server $ws_monitor_server;

    /**
     * WS Server process.
     *
     * @var Process
     */
    protected Process $ws_server_process;

    /**
     * Server Status tick process.
     *
     * @var Process
     */
    protected Process $status_tick_process;

    /**
     * Communication process.
     *
     * @var Process
     */
    protected Process $communication_process;

    /**
     * Possible options:
     *
     * - 'command'                - which command this service executes to start your server. If
     *                              not specified, it will run the sample server.
     * - 'monitor-uri'            - the uri for the monitor server to be available from.
     * - 'monitor-port'           - the port for the monitor to be available from.
     * - 'monitor-html'           - the html file to be presented by the monitor server.
     * - 'monitor-timeout'        - the timeout for the monitor tick to get the updated status.
     * - 'ws-server-process-name' - the name of the linux process of the ws server, so the
     *                              server can monitor and control it.
     *
     * @var array
     */
    protected array $options;

    public function __construct(array $options = [])
    {
        $defaults = [
            'command' => '/usr/bin/php ' . __DIR__ . '/ws-server',
            'monitor-uri' => '0.0.0.0',
            'monitor-port' => 8080,
            'monitor-html' => __DIR__ . '/ws-monitor.html',
            'monitor-timeout' => 1000,
            'ws-server-process-name' => 'swoole-ws-server',
        ];
        $this->options = array_merge($defaults, $options);
    }

    public function run()
    {
        $this->start_actions_table();

        $this->start_server_monitor_interface();

        $this->start_server();

        $this->start_status_tick();

        $this->start_communication_process();

        $this->listen_kill_signals();
    }

    public function start_actions_table(): void
    {
        $this->actions_table = new Table(1024);
        $this->actions_table->column('data', Table::TYPE_STRING, 64);
        $this->actions_table->create();
    }

    public function listen_kill_signals(): void
    {
        run(function() {
            System::waitSignal(SIGKILL, -1);
        });
    }

    public function get_ws_server_pid(): int|null {
        // list processes by name (first)
        $pid = System::exec('/usr/bin/ps -aux | grep ' . $this->options['ws-server-process-name'] . ' | grep -v \'grep ' . $this->options['ws-server-process-name'] . '\' | /usr/bin/awk \'{ print $2; }\' | /usr/bin/sed -n \'1,1p\'');
        $clean_pid = trim($pid['output']);
        return (int) $clean_pid && !empty($clean_pid) ? (int) $clean_pid : null;
    }

    public function start_server_monitor_interface(): void
    {
        // web socket server where we can see and control the status
        $this->ws_monitor_process = new Process(function(Process $worker) {
            $timers = [];

            $worker->name('swoole-ws-monitor-server');

            $ws_server = new Server($this->options['monitor-uri'], $this->options['monitor-port'], SWOOLE_BASE);

            $ws_server->set([
                'document_root' => getcwd(),
                'enable_static_handler' => true,
            ]);

            $ws_server->on('open', function(Server $ws_server, Request $request) use (
                $worker, &$timers
            ) {
                echo 'Connection open: ' . $request->fd . PHP_EOL;

                $timers[$request->fd] = Timer::tick(1000, function() use (
                    $ws_server, $request
                ) {
                    $status = $this->actions_table->get($this->ws_status_table_key);
                    $this->actions_table->del($this->ws_status_table_key);

                    if (!isset($status['data'])) {
                        return;
                    }

                    if($ws_server->isEstablished($request->fd)) {
                        $ws_server->push($request->fd, json_encode([
                            'id' => $request->fd,
                            'data' => $status['data'],
                        ]));
                    }
                });
            });

            $ws_server->on('request', function (Request $request, Response $response) {
                $html_file = $this->options['monitor-html'];
                $response->header("Content-Type", "text/html");
                $response->header("Charset", "UTF-8");
                $response->end(file_get_contents($html_file));
            });

            $ws_server->on('message', function(Server $ws_server, Frame $frame) {
                echo 'Received message: ' . $frame->data . PHP_EOL;
                $this->actions_table->set($this->ws_table_key, [
                    'data' => $frame->data,
                ]);
            });

            $ws_server->on('close', function(Server $ws_server, $fd) use (&$timers) {
                echo 'Connection close: ' . $fd . PHP_EOL;
                if (isset($timers[$fd])) {
                    Timer::clear($timers[$fd]);
                    unset($timers[$fd]);
                }
            });

            $ws_server->start();

            $this->ws_monitor_server = $ws_server;
        });
        $this->ws_monitor_process->start();
    }

    public function start_server(): void
    {
        $this->ws_server_process = new Process(function(Process $worker) {
            run(function() {
                $this->start_server_command();
            });
        });
        $this->ws_server_process->start();
    }

    public function start_server_command(): void
    {
        System::exec($this->options['command']);
    }

    public function start_status_tick(): void
    {
        $this->status_tick_process = new Process(function(Process $worker) {
            Timer::tick(1000, function() {
                $clean_pid = $this->get_ws_server_pid();
                $status = $clean_pid === null ? self::SERVER_DEAD : self::SERVER_ALIVE;
                $this->actions_table->set($this->ws_status_table_key, [
                    'data' => $status,
                ]);
            });
        });
        $this->status_tick_process->start();
    }

    public function start_communication_process(): void
    {
        $this->communication_process = new Process(function() {
            Timer::tick($this->options['monitor-timeout'], function() {
                $action = $this->actions_table->get($this->ws_table_key);
                $this->actions_table->del($this->ws_table_key);

                if (!isset($action['data'])) {
                    return;
                }

                if ($action['data'] === 'start') {
                    echo 'Starting...' . PHP_EOL;
                    $clean_pid = $this->get_ws_server_pid();
                    if ($clean_pid === null) {
                        $this->start_server_command();
                    }
                } elseif ($action['data'] === 'stop') {
                    echo 'Stopping...' . PHP_EOL;
                    $clean_pid = $this->get_ws_server_pid();
                    if ($clean_pid !== null && $clean_pid !== 0) {
                        Process::kill($clean_pid, SIGKILL);
                    }
                }
            });
        });

        $this->communication_process->start();
    }
}

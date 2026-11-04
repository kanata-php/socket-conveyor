<?php

namespace Tests\Feature;

use Exception;
use OpenSwoole\Process;

trait ServerProcessCleanup
{
    private ?int $serverPid = null;

    protected function getServerProcesses(): false|string
    {
        return exec('lsof -nP -iTCP:' . $this->port . ' -sTCP:LISTEN 2>/dev/null');
    }

    /**
     * @before
     * @after
     * @throws Exception
     */
    public function tearServerDown(): void
    {
        // Keep native/third-party diagnostics in a file; stderr is PHPUnit child-process IPC.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', '/tmp/conveyor-feature-diagnostics.log');
        if ($this->serverPid === null) {
            if (!empty($this->getServerProcesses())) {
                throw new Exception('Test server port is already occupied: ' . $this->port);
            }
            return;
        }

        // Signal only our child. Delivery of a signal does not mean it has exited.
        $pid = $this->serverPid;
        if (Process::kill($pid, 0)) {
            Process::kill($pid, SIGTERM);
        }

        $deadline = microtime(true) + 5;
        $reaped = false;
        do {
            if (!$reaped) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                $reaped = $result === $pid || $result === -1;
            }
            $output = $this->getServerProcesses();
            if ($reaped && empty($output)) {
                $this->serverPid = null;
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new Exception('Test server did not exit within 5 seconds. PID: ' . $pid . '. Output: ' . $output);
    }
}

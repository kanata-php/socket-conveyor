#!/usr/bin/env python3
"""Exercise runner process cleanup with disposable process trees."""
from pathlib import Path
import subprocess
import tempfile
import unittest


class CleanupTest(unittest.TestCase):
    def test_reparented_child_is_terminated(self):
        for name in ('run-conveyor-with-resources.sh', 'laravel-reverb/run-reverb-with-resources.sh'):
            with self.subTest(runner=name), tempfile.TemporaryDirectory() as root:
                script = (Path(__file__).parent / name).read_text()
                functions = script[script.index('collect_descendants()'):script.index('wait_for_port_free()')]
                pidfile = Path(root) / 'child.pid'
                check = functions + '''
BENCHMARK_PROCESS_STOP_TIMEOUT=0
bash -c 'sleep 60 & echo $! > "$1"; wait' _ "$1" &
root=$!
trap 'kill "$root" "$(cat "$1")" 2>/dev/null || true' EXIT
for attempt in {1..50}; do test -s "$1" && break; sleep .02; done
child=$(cat "$1")
terminate_process_tree "$root"
! process_is_running "$child"
'''
                result = subprocess.run(['bash', '-c', check, '_', str(pidfile)], capture_output=True, text=True, timeout=10)
                self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == '__main__':
    unittest.main()

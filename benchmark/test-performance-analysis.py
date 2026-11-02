#!/usr/bin/env python3
"""Regression checks for trial validity; uses temporary synthetic artifacts."""
import csv
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('performance_analysis', Path(__file__).with_name('analyze-performance-results.py'))
analysis = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = analysis
spec.loader.exec_module(analysis)


class TrialValidityTest(unittest.TestCase):
    def fixture(self, root):
        trial = Path(root) / 'trial-1-conveyor'
        trial.mkdir()
        (trial / 'status.txt').write_text('0\n')
        (trial / 'server.log').write_text('server listening\n')
        (trial / 'run.log').write_text('completed\n')
        for name in ('failures', 'exceptions', 'stats_history'):
            (trial / f'stats_{name}.csv').write_text('header\n')
        with (trial / 'stats_stats.csv').open('w') as handle:
            writer = csv.DictWriter(handle, fieldnames=['Name', 'Request Count', 'Requests/s', '50%'])
            writer.writeheader()
            for name in ('REST publish', 'fanout receive'):
                writer.writerow({'Name': name, 'Request Count': 100, 'Requests/s': 10, '50%': 7})
        for name in ('server', 'locust'):
            (trial / f'{name}-resources.csv').write_text('timestamp,pids,cpu_percent,rss_kb,vsz_kb\nnow,7,250,1024,2048\n')
        return trial

    def test_aggregate_worker_cpu_is_not_single_core_saturation(self):
        with tempfile.TemporaryDirectory() as root:
            trial = analysis.analyze_trial(self.fixture(root), True)
            self.assertTrue(trial.valid, trial.invalid_reason)
            self.assertEqual(trial.rest_p50_ms, 7)

    def test_worker_warning_invalidates_trial(self):
        with tempfile.TemporaryDirectory() as root:
            path = self.fixture(root)
            (path / 'run.log').write_text('CPU usage above 90%')
            self.assertIn('cpu saturation', analysis.analyze_trial(path, True).invalid_reason)

    def test_missing_run_log_invalidates_trial(self):
        with tempfile.TemporaryDirectory() as root:
            path = self.fixture(root)
            (path / 'run.log').unlink()
            self.assertIn('missing run.log', analysis.analyze_trial(path, True).invalid_reason)

    def test_missing_resource_samples_invalidate_trial(self):
        with tempfile.TemporaryDirectory() as root:
            path = self.fixture(root)
            (path / 'locust-resources.csv').unlink()
            self.assertIn('missing or empty locust-resources.csv', analysis.analyze_trial(path, True).invalid_reason)


if __name__ == '__main__':
    unittest.main()

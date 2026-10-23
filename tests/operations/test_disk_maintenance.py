import datetime as dt
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import time
import unittest
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[2] / 'scripts' / 'disk_maintenance.py'
spec = importlib.util.spec_from_file_location('disk_maintenance', MODULE)
maintenance = importlib.util.module_from_spec(spec)
spec.loader.exec_module(maintenance)


class DiskMaintenanceTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.site = Path(self.temporary.name)
        (self.site / 'releases').mkdir()
        for name in ('80000001', '80000002', '80000003', '80000004'):
            release = self.site / 'releases' / name
            release.mkdir()
            (release / 'artisan').write_text('protected application')
            timestamp = time.time() - (40 - int(name[-1])) * 86400
            os.utime(release / 'artisan', (timestamp, timestamp))
            os.utime(release, (timestamp, timestamp))
        (self.site / 'current').symlink_to(self.site / 'releases' / '80000004', target_is_directory=True)
        (self.site / 'storage' / 'logs').mkdir(parents=True)
        self.config = {'site': str(self.site), 'database': False}
        self.cache_patch = patch.object(maintenance, 'NODE_CACHE', self.site / 'absent-node-cache')
        self.cache_patch.start()

    def tearDown(self):
        self.cache_patch.stop()
        self.temporary.cleanup()

    def old_file(self, name, days=40):
        path = self.site / 'storage' / 'logs' / name
        path.write_text('diagnostic evidence')
        old = time.time() - days * 86400
        os.utime(path, (old, old))
        return path

    def test_protects_current_and_two_rollback_releases(self):
        names = [Path(item['path']).name for item in maintenance.filesystem_candidates(self.site)]
        self.assertEqual(['80000001'], names)

    def test_preserves_recorded_previous_release_even_if_older(self):
        audit = self.site / '.release-audits'
        audit.mkdir()
        (audit / 'deployment.json').write_text(json.dumps({'old_release': str(self.site / 'releases' / '80000001')}))
        self.assertEqual([], maintenance.filesystem_candidates(self.site))

    def test_logs_preserve_active_recent_and_unknown_files(self):
        old = self.old_file('queue-monitor-2026-01-01.log.1.gz')
        self.old_file('laravel.log')
        self.old_file('queue-monitor-2026-10-20.log', days=1)
        self.old_file('customer-upload.log.1')
        paths = [item['path'] for item in maintenance.filesystem_candidates(self.site)]
        self.assertIn(str(old), paths)
        self.assertEqual(2, len(paths))

    def test_symlink_log_and_release_are_never_deleted(self):
        victim = self.site / 'customer-data'
        victim.write_text('important')
        (self.site / 'storage' / 'logs' / 'laravel.log.5').symlink_to(victim)
        (self.site / 'releases' / '80000000').symlink_to(self.site / 'releases' / '80000001', target_is_directory=True)
        paths = [item['path'] for item in maintenance.filesystem_candidates(self.site)]
        self.assertNotIn(str(self.site / 'storage' / 'logs' / 'laravel.log.5'), paths)
        self.assertNotIn(str(self.site / 'releases' / '80000000'), paths)

    def test_revalidates_when_candidate_becomes_current_release(self):
        candidate = next(item for item in maintenance.filesystem_candidates(self.site) if item['kind'] == 'old_release')
        (self.site / 'current').unlink()
        (self.site / 'current').symlink_to(Path(candidate['path']), target_is_directory=True)
        with self.assertRaises(RuntimeError):
            maintenance.delete_candidate(self.site, candidate)
        self.assertTrue(Path(candidate['path']).exists())

    def test_release_removal_unlinks_shared_symlinks_without_touching_targets(self):
        release = self.site / 'releases' / '80000001'
        (self.site / 'private-environment').write_text('credentials')
        shared = release / '.env'
        shared.symlink_to(self.site / 'private-environment')
        old = time.time() - 40 * 86400
        os.utime(shared, (old, old), follow_symlinks=False)
        os.utime(release, (old, old))
        candidate = next(item for item in maintenance.filesystem_candidates(self.site) if item['path'] == str(release))
        maintenance.delete_candidate(self.site, candidate)
        self.assertFalse(release.exists())
        self.assertEqual('credentials', (self.site / 'private-environment').read_text())

    def test_preview_never_deletes_even_under_pressure(self):
        candidate = self.old_file('laravel.log.5')
        full = {'used_percent': 95, 'free_bytes': maintenance.GIB, 'total_bytes': 20 * maintenance.GIB}
        with patch.object(maintenance, 'disk_usage', return_value=full):
            report = maintenance.perform(self.config)
        self.assertEqual('attention', report['status'])
        self.assertEqual([], report['actions'])
        self.assertTrue(candidate.exists())

    def test_healthy_run_does_not_remove_eligible_files(self):
        candidate = self.old_file('laravel.log.5')
        healthy = {'used_percent': 50, 'free_bytes': 20 * maintenance.GIB, 'total_bytes': 40 * maintenance.GIB}
        with patch.object(maintenance, 'disk_usage', return_value=healthy):
            report = maintenance.perform(self.config, apply=True)
        self.assertEqual([], report['actions'])
        self.assertTrue(candidate.exists())

    def test_application_only_removes_preview_eligible_paths(self):
        candidate = self.old_file('laravel.log.5')
        protected = self.old_file('laravel.log')
        full = {'used_percent': 95, 'free_bytes': maintenance.GIB, 'total_bytes': 20 * maintenance.GIB}
        with patch.object(maintenance, 'disk_usage', return_value=full):
            report = maintenance.perform(self.config, apply=True)
        self.assertFalse(candidate.exists())
        self.assertTrue(protected.exists())
        self.assertGreater(len(report['actions']), 0)

    def test_stale_statistics_cannot_invent_reclaimable_bytes(self):
        table = self.table(live_bytes=9 * maintenance.GIB, file_bytes=maintenance.GIB, reusable_bytes=8 * maintenance.GIB)
        plan = maintenance.compaction_plan(table, 40 * maintenance.GIB)
        self.assertEqual(0, plan['estimated_reclaim_bytes'])
        self.assertFalse(plan['eligible'])

    def test_compaction_requires_working_space_plus_reserve(self):
        table = self.table()
        plan = maintenance.compaction_plan(table, 5 * maintenance.GIB)
        self.assertEqual('insufficient_working_space', plan['reason'])
        self.assertTrue(maintenance.compaction_plan(table, plan['required_free_bytes'])['eligible'])

    def test_compaction_rejects_unapproved_or_fulltext_tables(self):
        for table in (self.table(table_name='users'), self.table(fulltext_indexes=1), self.table(space_type='General')):
            self.assertEqual('unsupported_table', maintenance.compaction_plan(table, 100 * maintenance.GIB)['reason'])

    def test_daily_gate_handles_daylight_saving_and_duplicate_invocations(self):
        for stamp in ('2026-10-21T08:00:00+00:00', '2026-12-21T09:00:00+00:00'):
            now = dt.datetime.fromisoformat(stamp)
            self.assertTrue(maintenance.scheduled_today({}, now))
            self.assertFalse(maintenance.scheduled_today({'scheduled_date': maintenance.local_time(now).date().isoformat()}, now))
        self.assertFalse(maintenance.scheduled_today({}, dt.datetime.fromisoformat('2026-10-21T09:00:00+00:00')))

    def test_busy_database_prevents_compaction_subprocess(self):
        metadata = {'local_database': True, 'active_transactions': 1, 'long_queries': 0, 'pending_metadata_locks': 0}
        with patch.object(maintenance, 'bridge', return_value=metadata), patch.object(maintenance.subprocess, 'Popen') as process:
            result = maintenance.compact(self.config, self.site, {'table': 'option_snapshots'})
        self.assertEqual('database_busy', result['result'])
        process.assert_not_called()

    def test_compaction_is_not_started_during_market_hours(self):
        metadata = {'local_database': True, 'datadir': str(self.site), 'tmpdir': str(self.site), 'tables': [self.table()]}
        full = {'used_percent': 85, 'free_bytes': 20 * maintenance.GIB, 'total_bytes': 130 * maintenance.GIB}
        with patch.object(maintenance, 'bridge', return_value=metadata), \
                patch.object(maintenance, 'disk_usage', return_value=full), \
                patch.object(maintenance, 'local_time', return_value=dt.datetime(2026, 10, 21, 13)), \
                patch.object(maintenance, 'compact') as compact:
            report = maintenance.perform({**self.config, 'database': True}, apply=True)
        compact.assert_not_called()
        self.assertTrue(any('deferred' in warning for warning in report['warnings']))

    def test_main_preview_does_not_write_report_or_send_email(self):
        state = self.site / '.disk-maintenance'
        state.mkdir(mode=0o700)
        config = state / 'config.json'
        config.write_text(json.dumps(self.config))
        healthy = {'status': 'healthy', 'apply': False}
        with patch.object(maintenance.sys, 'argv', ['disk_maintenance.py', '--config', str(config)]), \
                patch.object(maintenance, 'perform', return_value=healthy), \
                patch.object(maintenance, 'bridge') as bridge:
            self.assertEqual(0, maintenance.main())
        bridge.assert_not_called()
        self.assertFalse((state / 'latest.json').exists())

    def test_attention_sends_direct_email_and_persists_result(self):
        state = self.site / '.disk-maintenance'
        state.mkdir(mode=0o700)
        config = state / 'config.json'
        config.write_text(json.dumps({**self.config, 'email': 'ops@example.test'}))
        with patch.object(maintenance.sys, 'argv', ['disk_maintenance.py', '--config', str(config), '--apply']), \
                patch.object(maintenance, 'perform', return_value={'status': 'attention', 'apply': True}), \
                patch.object(maintenance, 'bridge', return_value={'sent': True}) as bridge:
            self.assertEqual(1, maintenance.main())
        self.assertEqual('notify', bridge.call_args.args[2])
        self.assertTrue(json.loads((state / 'latest.json').read_text())['email']['sent'])

    def test_failed_email_is_recorded_without_claiming_delivery(self):
        state = self.site / '.disk-maintenance'
        state.mkdir(mode=0o700)
        config = state / 'config.json'
        config.write_text(json.dumps({**self.config, 'email': 'ops@example.test'}))
        with patch.object(maintenance.sys, 'argv', ['disk_maintenance.py', '--config', str(config), '--apply']), \
                patch.object(maintenance, 'perform', return_value={'status': 'attention', 'apply': True}), \
                patch.object(maintenance, 'bridge', side_effect=RuntimeError('mail unavailable')):
            self.assertEqual(1, maintenance.main())
        self.assertFalse(json.loads((state / 'latest.json').read_text())['email']['sent'])

    def test_scheduled_duplicate_does_not_sample_disk(self):
        state = self.site / '.disk-maintenance'
        state.mkdir(mode=0o700)
        config = state / 'config.json'
        config.write_text(json.dumps(self.config))
        (state / 'latest.json').write_text(json.dumps({'scheduled_date': '2026-10-21'}))
        with patch.object(maintenance.sys, 'argv', ['disk_maintenance.py', '--config', str(config), '--apply', '--scheduled']), \
                patch.object(maintenance, 'local_time', return_value=dt.datetime(2026, 10, 21, 4)), \
                patch.object(maintenance, 'perform') as perform:
            self.assertEqual(0, maintenance.main())
        perform.assert_not_called()

    def archive(self, days=40, status='committed', digest=None):
        root = self.site / 'storage/app/private/maintenance/calculator-prune'
        root.mkdir(parents=True, exist_ok=True)
        archive = root / '20260101-040000-12345678-1234-1234-1234-123456789abc.jsonl.gz'
        archive.write_bytes(b'archived expired calculator data')
        manifest = archive.with_name(archive.name + '.manifest.json')
        stamp = time.time() - days * 86400
        manifest.write_text(json.dumps({'status': status, 'committed_at': dt.datetime.fromtimestamp(stamp, dt.timezone.utc).isoformat(),
                                       'path': str(archive.resolve()), 'bytes': archive.stat().st_size,
                                       'sha256': digest or maintenance.hashlib.sha256(archive.read_bytes()).hexdigest()}))
        for path in (archive, manifest):
            os.utime(path, (stamp, stamp))
        return archive, manifest

    def test_archive_retention_only_removes_old_committed_checksum_verified_pairs(self):
        archive, manifest = self.archive()
        item = next(c for c in maintenance.filesystem_candidates(self.site) if c['kind'] == 'calculator_prune_archive')
        maintenance.delete_candidate(self.site, item)
        self.assertFalse(archive.exists())
        self.assertFalse(manifest.exists())

    def test_recent_and_uncommitted_archives_are_preserved(self):
        for days, status in ((1, 'committed'), (40, 'prepared')):
            archive, manifest = self.archive(days, status)
            self.assertFalse(any(c['kind'] == 'calculator_prune_archive' for c in maintenance.filesystem_candidates(self.site)))
            self.assertTrue(archive.exists())

    def test_expired_prune_archives_are_cleaned_daily_without_disk_pressure(self):
        archive, manifest = self.archive()
        healthy = {'used_percent': 50, 'free_bytes': 20 * maintenance.GIB, 'total_bytes': 40 * maintenance.GIB}
        with patch.object(maintenance, 'disk_usage', return_value=healthy):
            result = maintenance.perform(self.config, apply=True)
        self.assertFalse(archive.exists())
        self.assertFalse(manifest.exists())
        self.assertEqual(['calculator_prune_archive'], [a['kind'] for a in result['actions']])

    def test_corrupt_archive_is_preserved_and_cleanup_stops(self):
        archive, manifest = self.archive(digest='0' * 64)
        item = next(c for c in maintenance.filesystem_candidates(self.site) if c['kind'] == 'calculator_prune_archive')
        with self.assertRaises(RuntimeError):
            maintenance.delete_candidate(self.site, item)
        self.assertTrue(archive.exists())
        self.assertTrue(manifest.exists())

    def test_failed_or_missing_daily_retention_report_requires_attention_even_with_free_disk(self):
        healthy = {'used_percent': 50, 'free_bytes': 20 * maintenance.GIB, 'total_bytes': 40 * maintenance.GIB}
        report = self.site / 'retention.json'
        for value in (None, {'at': dt.datetime.now(dt.timezone.utc).isoformat(), 'status': 'error'}):
            if value:
                report.write_text(json.dumps(value))
            with patch.object(maintenance, 'disk_usage', return_value=healthy):
                result = maintenance.perform({**self.config, 'calculator_retention_report': str(report)})
            self.assertEqual('attention', result['status'])
            self.assertTrue(result['warnings'])

    def test_manual_run_preserves_daily_gate_and_records_space_change(self):
        state = self.site / '.disk-maintenance'
        state.mkdir(mode=0o700)
        config = state / 'config.json'
        config.write_text(json.dumps(self.config))
        (state / 'latest.json').write_text(json.dumps({'at': '2026-10-21T08:00:00+00:00', 'scheduled_date': '2026-10-21', 'after': {'free_bytes': 10}}))
        with patch.object(maintenance.sys, 'argv', ['disk_maintenance.py', '--config', str(config), '--apply']), \
                patch.object(maintenance, 'perform', return_value={'status': 'healthy', 'after': {'free_bytes': 20}}):
            self.assertEqual(0, maintenance.main())
        result = json.loads((state / 'latest.json').read_text())
        self.assertEqual('2026-10-21', result['scheduled_date'])
        self.assertEqual(10, result['since_previous_check']['free_change_bytes'])

    @staticmethod
    def table(**overrides):
        return {'table_name': 'option_snapshots', 'engine': 'InnoDB', 'space_type': 'Single',
                'fulltext_indexes': 0, 'live_bytes': maintenance.GIB, 'file_bytes': 5 * maintenance.GIB,
                'reusable_bytes': 3 * maintenance.GIB, **overrides}


if __name__ == '__main__':
    unittest.main()

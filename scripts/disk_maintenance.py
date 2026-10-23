#!/usr/bin/env python3
"""Daily, host-local disk maintenance. Preview is the default."""
import argparse
import hashlib
import datetime as dt
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import time
from zoneinfo import ZoneInfo

GIB = 1024 ** 3
TABLES = ('option_snapshots', 'intraday_option_volumes', 'calculator_expiry_publication_rows')
LOG_NAME = re.compile(r'(?:laravel|scheduler|queue-monitor|eod-repair)(?:-\d{4}-\d{2}-\d{2})?\.log(?:\.\d+)?(?:\.gz)?$')
RELEASE_NAME = re.compile(r'(?:\d{6,12}|manual-\d{8}-[0-9a-f]{7,40})$')
NODE_CACHE = Path('/tmp/node-compile-cache')
PRUNE_ARCHIVE = re.compile(r'\d{8}-\d{6}-[0-9a-f-]{36}\.jsonl\.gz$')


def pressured(disk):
    return disk['used_percent'] >= 80 or disk['free_bytes'] < 10 * GIB


def disk_usage(path):
    value = shutil.disk_usage(path)
    # Use f_bavail/free available to Forge, including the filesystem reserve.
    return {'free_bytes': value.free, 'total_bytes': value.total,
            'used_percent': round(100 * (value.total - value.free) / value.total, 2)}


def local_time(now=None):
    return (now or dt.datetime.now(dt.timezone.utc)).astimezone(ZoneInfo('America/New_York'))


def scheduled_today(previous, now=None):
    current = local_time(now)
    return current.hour == 4 and previous.get('scheduled_date') != current.date().isoformat()


def atomic_json(path, value):
    temporary = path.with_suffix('.tmp')
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as handle:
        json.dump(value, handle, indent=2)
        handle.write('\n')
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temporary, path)


def protected_releases(site):
    releases = site / 'releases'
    current = (site / 'current').resolve(strict=True)
    if current.parent != releases or current.is_symlink():
        raise RuntimeError('Current release must resolve directly inside the site releases directory.')
    candidates = [p for p in releases.iterdir() if RELEASE_NAME.fullmatch(p.name)
                  and not p.is_symlink() and p.is_dir() and p != current]
    # Current plus at least two rollback releases, irrespective of their age.
    protected = {current, *sorted(candidates, key=lambda p: p.stat().st_mtime, reverse=True)[:2]}
    audits = site / '.release-audits'
    records = sorted(audits.glob('*.json'), key=lambda p: p.stat().st_mtime, reverse=True) if audits.is_dir() else []
    if records:
        record = json.loads(records[0].read_text())
        old = Path(record.get('old_release', str(current)))
        if old.parent == releases:
            protected.add(old)
    return protected


def tree_is_old(path, cutoff):
    if path.is_symlink() or path.stat().st_uid != os.getuid() or path.stat().st_mtime >= cutoff:
        return False
    device = path.stat().st_dev
    for root, directories, files in os.walk(path, followlinks=False):
        for name in directories + files:
            child = Path(root) / name
            info = child.lstat()
            if info.st_uid != os.getuid() or info.st_dev != device or info.st_mtime >= cutoff:
                return False
            if not (stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode)):
                return False
    return path.stat().st_mtime < cutoff


def filesystem_candidates(site, now=None):
    now = now or time.time()
    candidates = []
    logs = site / 'storage' / 'logs'
    if logs.is_dir() and not logs.is_symlink():
        for path in logs.iterdir():
            info = path.lstat()
            # Preserve active undated logs and the last 30 days of diagnostics.
            rotated = re.search(r'-\d{4}-\d{2}-\d{2}\.log|\.log\.\d', path.name)
            if LOG_NAME.fullmatch(path.name) and rotated and stat.S_ISREG(info.st_mode) \
                    and info.st_uid == os.getuid() and info.st_nlink == 1 and info.st_mtime < now - 30 * 86400:
                candidates.append({'kind': 'rotated_log', 'path': str(path), 'bytes': info.st_size})
    protected = protected_releases(site)
    for path in (site / 'releases').iterdir():
        if RELEASE_NAME.fullmatch(path.name) and path not in protected and path.is_dir() \
                and tree_is_old(path, now - 14 * 86400):
            size = sum((Path(root) / name).lstat().st_size for root, _, files in os.walk(path, followlinks=False)
                       for name in files if not (Path(root) / name).is_symlink())
            candidates.append({'kind': 'old_release', 'path': str(path), 'bytes': size})
    # Only this known regenerable Node cache; never clear Laravel locks, uploads or sessions.
    cache = NODE_CACHE
    if cache.is_dir() and not cache.is_symlink() and cache.stat().st_uid == os.getuid():
        for root, _, files in os.walk(cache, followlinks=False):
            if Path(root).resolve() != cache and cache not in Path(root).resolve().parents:
                continue
            for name in files:
                path = Path(root) / name
                info = path.lstat()
                if stat.S_ISREG(info.st_mode) and info.st_uid == os.getuid() and info.st_nlink == 1 \
                        and info.st_mtime < now - 7 * 86400:
                    candidates.append({'kind': 'node_compile_cache', 'path': str(path), 'bytes': info.st_size})
    # Only verified, committed calculator-retention archives have a 30-day expiry.
    archive_root = site / 'storage/app/private/maintenance/calculator-prune'
    expected_root = (site / 'storage').resolve() / 'app/private/maintenance/calculator-prune'
    if archive_root.is_dir() and not archive_root.is_symlink() and archive_root.resolve() == expected_root:
        for manifest in archive_root.glob('*.jsonl.gz.manifest.json'):
            if manifest.is_symlink() or not manifest.is_file():
                continue
            archive = manifest.with_name(manifest.name.removesuffix('.manifest.json'))
            if not PRUNE_ARCHIVE.fullmatch(archive.name) or archive.is_symlink() or not archive.is_file():
                continue
            info, meta = archive.stat(), manifest.stat()
            if any(s.st_uid != os.getuid() or s.st_nlink != 1 or s.st_mtime >= now - 30 * 86400 for s in (info, meta)):
                continue
            try:
                value = json.loads(manifest.read_text())
                committed = dt.datetime.fromisoformat(value['committed_at']).timestamp()
                if value.get('status') != 'committed' or committed >= now - 30 * 86400 \
                        or value.get('path') != str(archive.resolve()) or value.get('bytes') != info.st_size \
                        or not re.fullmatch('[0-9a-f]{64}', value.get('sha256', '')):
                    continue
            except (ValueError, KeyError, TypeError):
                continue
            candidates.append({'kind': 'calculator_prune_archive', 'path': str(archive),
                               'manifest': str(manifest), 'sha256': value['sha256'], 'bytes': info.st_size + meta.st_size})
    return sorted(candidates, key=lambda item: item['bytes'], reverse=True)


def delete_candidate(site, candidate):
    # Re-discover immediately before each mutation; reject stale or changed candidates.
    matching = next((item for item in filesystem_candidates(site) if item == candidate), None)
    if matching is None:
        raise RuntimeError('Cleanup candidate changed; refusing deletion.')
    path = Path(candidate['path'])
    if candidate['kind'] == 'calculator_prune_archive':
        if hashlib.sha256(path.read_bytes()).hexdigest() != candidate['sha256']:
            raise RuntimeError('Calculator retention archive checksum changed; preserving it for review.')
        path.unlink()
        Path(candidate['manifest']).unlink()
    elif candidate['kind'] == 'old_release':
        if path.parent != site / 'releases' or path in protected_releases(site) or path.is_symlink():
            raise RuntimeError('Refusing protected release deletion.')
        if not shutil.rmtree.avoids_symlink_attacks:
            raise RuntimeError('This platform cannot safely remove release directories.')
        shutil.rmtree(path)
    else:
        path.unlink()


def compaction_plan(table, free_bytes):
    name = table['table_name']
    live = int(table['live_bytes'])
    physical = int(table['file_bytes'])
    # DATA_FREE can be stale; never claim more savings than actual file minus live data.
    savings = max(0, min(int(table['reusable_bytes']), physical - live))
    required = 2 * max(live, 1) + 128 * 1024 ** 2 + 5 * GIB
    reason = None
    if name not in TABLES or table['space_type'] != 'Single' or table['engine'] != 'InnoDB' or table['fulltext_indexes']:
        reason = 'unsupported_table'
    elif savings < GIB or savings < physical * .30:
        reason = 'little_reclaimable_space'
    elif free_bytes < required:
        reason = 'insufficient_working_space'
    return {'table': name, 'estimated_reclaim_bytes': savings, 'required_free_bytes': required,
            'eligible': reason is None, 'reason': reason}


def bridge(config, site, operation, *args, timeout=30):
    process = subprocess.run([config['php'], str(Path(__file__).with_name('disk-maintenance-db.php')),
                              str(site / 'current'), operation, *args], capture_output=True, text=True, timeout=timeout)
    if process.returncode:
        try:
            detail = json.loads(process.stdout.strip()).get('error_class', 'unknown')
        except (ValueError, AttributeError):
            detail = 'unknown'
        raise RuntimeError('Maintenance bridge failed: ' + operation + ' (' + detail + ')')
    return json.loads(process.stdout)


def compact(config, site, plan):
    # Re-evaluate the table immediately before DDL, not from yesterday's report.
    fresh = bridge(config, site, 'inspect')
    if not fresh['local_database'] or fresh['active_transactions'] or fresh['long_queries'] or fresh['pending_metadata_locks']:
        return {'table': plan['table'], 'result': 'database_busy'}
    table = next(t for t in fresh['tables'] if t['table_name'] == plan['table'])
    checked = compaction_plan(table, min(disk_usage(fresh['datadir'])['free_bytes'], disk_usage(fresh['tmpdir'])['free_bytes']))
    if not checked['eligible']:
        return {'table': plan['table'], 'result': checked['reason']}
    command = [config['php'], str(Path(__file__).with_name('disk-maintenance-db.php')),
               str(site / 'current'), 'compact', plan['table'], str(checked['required_free_bytes'])]
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    # The bridge emits connection ownership before starting DDL; selector bounds startup waits.
    import selectors
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ)
    if not selector.select(30):
        process.terminate()
        raise RuntimeError('Compaction startup timed out.')
    first = json.loads(process.stdout.readline())
    selector.close()
    if first.get('result') == 'skipped':
        process.communicate(timeout=10)
        return first
    connection_id = int(first['connection_id'])
    started = time.monotonic()
    cancelled = False
    while process.poll() is None:
        free = min(disk_usage(fresh['datadir'])['free_bytes'], disk_usage(fresh['tmpdir'])['free_bytes'])
        if free < 5 * GIB or time.monotonic() - started > 600:
            bridge(config, site, 'cancel', str(connection_id))
            cancelled = True
            break
        time.sleep(1)
    try:
        stdout, _ = process.communicate(timeout=120)
    except subprocess.TimeoutExpired:
        # Do not launch another table while rollback/DDL may still be active.
        raise RuntimeError('Cancelled compaction is still finishing; operator inspection required.')
    if cancelled or process.returncode:
        raise RuntimeError('Compaction cancelled or failed; no further tables attempted.')
    return json.loads(stdout.strip())


def perform(config, apply=False):
    site = Path(config['site']).resolve(strict=True)
    before = disk_usage(site)
    report = {'at': dt.datetime.now(dt.timezone.utc).isoformat(), 'host': os.uname().nodename,
              'apply': apply, 'before': before, 'actions': [], 'warnings': [], 'compactions': []}
    candidates = filesystem_candidates(site)
    report['filesystem_candidates'] = candidates
    if apply:
        for candidate in candidates:
            if candidate['kind'] != 'calculator_prune_archive' and not pressured(disk_usage(site)):
                continue
            delete_candidate(site, candidate)
            report['actions'].append(candidate)
    report['database'] = {'enabled': config.get('database', False)}
    if config.get('database', False):
        metadata = bridge(config, site, 'inspect')
        if not metadata['local_database']:
            raise RuntimeError('Database maintenance must run on the actual MySQL host.')
        available = min(disk_usage(metadata['datadir'])['free_bytes'], disk_usage(metadata['tmpdir'])['free_bytes'])
        plans = [compaction_plan(table, available) for table in metadata['tables']]
        report['database']['plans'] = plans
        # Avoid routine churn: compact only under disk pressure, outside trading hours.
        if apply and pressured(disk_usage(site)):
            if not 3 <= local_time().hour < 6:
                report['warnings'].append('Compaction deferred to the 3–6 AM ET maintenance window.')
            else:
                for plan in sorted(plans, key=lambda p: p['required_free_bytes']):
                    if not pressured(disk_usage(site)):
                        break
                    if plan['eligible']:
                        report['compactions'].append(compact(config, site, plan))
    report['after'] = disk_usage(site)
    report['reclaimed_bytes'] = max(0, report['after']['free_bytes'] - before['free_bytes'])
    report['status'] = 'attention' if pressured(report['after']) else 'healthy'
    retention_path = config.get('calculator_retention_report')
    if retention_path:
        try:
            retention = json.loads(Path(retention_path).read_text())
            age = dt.datetime.now(dt.timezone.utc).timestamp() - dt.datetime.fromisoformat(retention['at']).timestamp()
            report['calculator_retention'] = retention
            if age < -300 or age > 26 * 3600 or retention['status'] != 'complete':
                report['status'] = 'attention'
                report['warnings'].append('Daily calculator retention is stale, incomplete or failed; inspect its private report.')
        except (OSError, ValueError, KeyError, TypeError):
            report['status'] = 'attention'
            report['warnings'].append('Daily calculator retention report is unavailable; inspect the worker schedule.')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--scheduled', action='store_true')
    parser.add_argument('--test-email', action='store_true')
    args = parser.parse_args()
    config_path = Path(args.config).resolve(strict=True)
    config = json.loads(config_path.read_text())
    state = config_path.parent
    if state.is_symlink() or state.stat().st_uid != os.getuid() or stat.S_IMODE(state.stat().st_mode) & 0o077:
        raise RuntimeError('Maintenance directory must be private and owned by the executing user.')
    import fcntl
    descriptor = os.open(state / 'maintenance.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0
        latest = state / 'latest.json'
        previous = json.loads(latest.read_text()) if latest.exists() else {}
        if args.test_email:
            result = bridge(config, Path(config['site']), 'notify', config['email'],
                            'GEX disk maintenance: test', 'Daily disk maintenance email delivery is configured for ' + os.uname().nodename + '.')
            print(json.dumps(result))
            return 0
        if args.scheduled and not scheduled_today(previous):
            return 0
        try:
            report = perform(config, args.apply)
        except Exception as error:
            report = {'at': dt.datetime.now(dt.timezone.utc).isoformat(), 'status': 'error', 'apply': args.apply,
                      'error': str(error)[:300]}
        if args.scheduled:
            report['scheduled_date'] = local_time().date().isoformat()
        elif previous.get('scheduled_date'):
            report['scheduled_date'] = previous['scheduled_date']
        if previous.get('after') and report.get('after'):
            report['since_previous_check'] = {'at': previous.get('at'),
                                            'free_change_bytes': report['after']['free_bytes'] - previous['after']['free_bytes']}
        if args.apply:
            needs_email = report['status'] != 'healthy' or previous.get('status') in ('attention', 'error')
            if needs_email:
                try:
                    result = bridge(config, Path(config['site']), 'notify', config['email'],
                                    'GEX disk maintenance: ' + report['status'], json.dumps(report, indent=2))
                    report['email'] = result
                except Exception:
                    report['email'] = {'sent': False, 'error': 'Direct email failed; inspect server mail configuration.'}
            atomic_json(latest, report)
            # One bounded report per day, 30-day retention. Preview never alters state.
            atomic_json(state / ('report-' + local_time().date().isoformat() + '.json'), report)
            for old in state.glob('report-????-??-??.json'):
                if not old.is_symlink() and old.stat().st_mtime < time.time() - 30 * 86400:
                    old.unlink()
        print(json.dumps(report))
        return 0 if report['status'] == 'healthy' else 1


if __name__ == '__main__':
    sys.exit(main())

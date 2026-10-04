#!/usr/bin/python3
"""Live authority check immediately before a transport's external action.

No lease cache, heartbeat election, or fallback to local authority is allowed.
Successful begin is permanently uncertain until its receipt is committed.
"""
import importlib.util
import json
import os
import subprocess
import sys
import time
from pathlib import Path

sys.dont_write_bytecode = True
CONFIG = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config')
HELPER = Path('/usr/local/bin/sls_mass_notify/sls_mass_notify_cluster_effect.php')
_spec = importlib.util.spec_from_file_location('sls_cluster_config_crypto', Path(__file__).resolve().with_name('sls_config_crypto.py'))
_crypto = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_crypto)


class ClusterFenced(RuntimeError):
    pass


def runtime_running(settings):
    value = settings.get('runtime_enabled', True)
    if type(value) is not bool:
        raise ClusterFenced('Invalid SLS runtime policy; notification admission is blocked')
    return value


def require_running(settings):
    if not runtime_running(settings):
        raise ClusterFenced('SLS notifications are stopped; run sudo slsconsole start to resume')


def enabled(settings=None):
    if settings is None:
        # Existing unit tools may exercise a transport without a PBX config.
        # A real configured PBX always has its protected file; malformed or
        # unreadable existing files never cause an authorization bypass.
        if not CONFIG.exists() and not CONFIG.is_symlink():
            if HELPER.is_file() or Path(__file__).resolve().parent == Path('/usr/local/bin/sls_mass_notify'):
                raise ClusterFenced('Protected cluster policy is missing; transport was fenced')
            return False
        settings = _crypto.read_config(CONFIG)
    require_running(settings)
    value = settings.get('enterprise_cluster', {})
    return isinstance(value, dict) and value.get('enabled') in (True, '1')


def _call(value):
    helper = HELPER
    if not helper.is_file():
        helper = Path(__file__).resolve().parent / 'sls_mass_notify_cluster_effect.php'
        if not helper.is_file():
            helper = Path(__file__).resolve().parents[1] / 'sls_mass_notify_cluster_effect.php'
    body = json.dumps(value, separators=(',', ':'), ensure_ascii=False).encode()
    if len(body) > 262144:
        raise ClusterFenced('Cluster effect exceeds its immutable intent limit')
    try:
        result = subprocess.run(['/usr/bin/php', str(helper)], input=body,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=14, check=False, close_fds=True,
                                env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C'})
        if result.returncode != 0 or len(result.stdout) > 65536:
            raise ClusterFenced('Cluster witness or protected state blocked this effect')
        reply = json.loads(result.stdout)
        if reply.get('ok') is not True:
            raise ClusterFenced('Cluster authority blocked this effect')
        return reply
    except (OSError, subprocess.TimeoutExpired, ValueError) as exc:
        raise ClusterFenced('Cluster effect authorization is unavailable or uncertain') from exc


def begin(channel, target, payload, *, delivery_id='', created_at=None, expires_at=None, settings=None):
    active_enabled = enabled()
    if not active_enabled and (settings is None or not enabled(settings)):
        return None
    delivery_id = os.environ.get('SLS_CLUSTER_DELIVERY_ID') or delivery_id
    created_at = int(os.environ.get('SLS_CLUSTER_CREATED_AT') or created_at or 0)
    expires_at = int(os.environ.get('SLS_CLUSTER_EXPIRES_AT') or expires_at or (created_at + 900))
    if not delivery_id or created_at <= 0 or time.time() >= expires_at:
        raise ClusterFenced('Cluster effect lacks a stable unexpired delivery identity')
    request = {'delivery_id': delivery_id, 'created_at': created_at, 'expires_at': expires_at, 'payload': payload}
    return _call({'action': 'begin', 'channel': channel, 'target': str(target), 'request': request}).get('claim')


def finish(claim, *, uncertain=False, category='accepted'):
    if claim is None:
        return
    _call({'action': 'finish', 'claim': claim, 'receipt': {'uncertain': bool(uncertain), 'category': category, 'completed_at': int(time.time())}})


def fence_legacy(settings=None):
    """Legacy producers lacking immutable full-job provenance cannot enter HA."""
    snapshots = [settings] if settings is not None else []
    if CONFIG.exists() or CONFIG.is_symlink():
        snapshots.append(_crypto.read_config(CONFIG))
    elif settings is None or HELPER.is_file() or Path(__file__).resolve().parent == Path('/usr/local/bin/sls_mass_notify'):
        enabled()  # A missing policy on an installed runtime fails closed.
    for snapshot in snapshots:
        require_running(snapshot)
        cluster = snapshot.get('enterprise_cluster', {})
        if enabled(snapshot) and (cluster.get('mode') in ('notification_ha', 'edge') or cluster.get('role') == 'witness'):
            raise ClusterFenced('Notification HA does not qualify this legacy transport; no external action was submitted. Use durable announcement or owned-site dispatch.')


if __name__ == '__main__':
    import sys
    if len(sys.argv) != 3 or sys.argv[1] != '--check-legacy':
        raise SystemExit(64)
    try:
        fence_legacy(_crypto.read_config(Path(sys.argv[2])))
    except Exception:
        print('SLS notification admission is blocked. Check slsconsole status, protected configuration and cluster authority; no external action was submitted.', file=sys.stderr)
        raise SystemExit(79)

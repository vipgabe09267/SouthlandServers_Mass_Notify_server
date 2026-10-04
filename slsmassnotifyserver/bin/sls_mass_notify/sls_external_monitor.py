#!/usr/bin/python3
"""Read-only readiness check for a separate monitoring host. Sends no alerts."""
import argparse
import datetime as dt
import email.utils
import http.client
import json
import os
from pathlib import Path
import re
import ssl
import stat
import sys
import time
from urllib.parse import urlsplit

MAX_REPORT_BYTES = 1024 * 1024


class MonitorError(RuntimeError):
    pass


def endpoint(value):
    try:
        parsed = urlsplit(value)
        if (parsed.scheme != 'https' or not parsed.hostname or parsed.username is not None
                or parsed.password is not None or parsed.query or parsed.fragment
                or parsed.path not in ('', '/') or any(c.isspace() or ord(c) < 32 for c in value)):
            raise ValueError()
        port = parsed.port or 443
        if not 1 <= port <= 65535:
            raise ValueError()
        parsed.hostname.encode('ascii')
        return parsed.hostname, port
    except (ValueError, UnicodeError):
        raise MonitorError('Use the PBX HTTPS origin, optionally with its forwarded port, without credentials, a path or query.') from None


def credential(path):
    parent = Path(path).parent
    if not parent.is_absolute() or parent.resolve() != parent:
        raise MonitorError('The monitoring credential requires an absolute path without linked parents.')
    handle = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    try:
        meta = os.fstat(handle)
        if (not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_mode & 0o077
                or meta.st_uid not in (0, os.geteuid()) or not 1 <= meta.st_size <= 4096):
            raise MonitorError('Keep the read-only monitoring credential in an owner-only regular file.')
        token = os.read(handle, 4097).decode('ascii').strip()
        current = os.stat(path, follow_symlinks=False)
        if ((meta.st_dev, meta.st_ino, meta.st_size, meta.st_mtime_ns) !=
                (current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns)
                or not re.fullmatch(r'[A-Za-z0-9_.~-]{16,4096}', token)):
            raise MonitorError('The monitoring credential changed or has an invalid format.')
        return token
    except UnicodeError:
        raise MonitorError('The monitoring credential must be an ASCII bearer token.') from None
    finally:
        os.close(handle)


def aware_timestamp(value):
    if not isinstance(value, str) or len(value) > 64:
        raise MonitorError('The PBX report timestamp is missing or invalid.')
    try:
        value = dt.datetime.fromisoformat(value.replace('Z', '+00:00'))
        if value.tzinfo is None:
            raise ValueError()
        return value.timestamp()
    except ValueError:
        raise MonitorError('The PBX report timestamp must include its timezone.') from None


def evaluate(body, headers, now=None, max_age=120):
    now = time.time() if now is None else now
    try:
        report = json.loads(body)
    except (ValueError, UnicodeError):
        raise MonitorError('The PBX returned an unreadable readiness report.') from None
    if (not isinstance(report, dict) or report.get('ok') is not True or report.get('resource') != 'readiness'
            or report.get('schema') != 'sls-deployment-readiness-v1'
            or type(report.get('operational_ready')) is not bool
            or any(type(report.get(k)) is not int or report[k] < 0 for k in ('attention_count', 'unknown_count'))
            or not isinstance(report.get('checks'), list) or not 1 <= len(report['checks']) <= 200
            or not isinstance(report.get('version'), str) or len(report['version']) > 64):
        raise MonitorError('The PBX readiness report has an unsupported or incomplete structure.')
    age = now - aware_timestamp(report.get('generated_at'))
    if age < -30 or age > max_age:
        raise MonitorError('The PBX report timestamp is stale or its clock differs from the monitoring host.')
    try:
        date = email.utils.parsedate_to_datetime(headers.get('Date', ''))
        if date.tzinfo is None or abs(now - date.timestamp()) > max_age:
            raise ValueError()
    except (TypeError, ValueError, OverflowError):
        raise MonitorError('The PBX HTTP Date is missing, invalid or stale.') from None
    if 'no-store' not in headers.get('Cache-Control', '').lower():
        raise MonitorError('The readiness route does not prohibit cached responses.')
    warnings = []
    for check in report['checks']:
        if (not isinstance(check, dict) or check.get('state') not in ('ok', 'warning', 'unknown')
                or not isinstance(check.get('id'), str) or len(check['id']) > 200
                or not isinstance(check.get('label'), str) or len(check['label']) > 500):
            raise MonitorError('The PBX readiness report contains an invalid check.')
        if check['state'] != 'ok':
            # Deliberately omit device observations, alert bodies, destinations
            # and raw diagnostic details from the monitor's output.
            warnings.append({'id': check['id'], 'label': check['label'], 'state': check['state']})
    consistent = (report['attention_count'] == sum(c['state'] == 'warning' for c in report['checks'])
                  and report['unknown_count'] == sum(c['state'] == 'unknown' for c in report['checks']))
    if not consistent or report['operational_ready'] != (not warnings):
        raise MonitorError('The PBX readiness aggregates disagree with its checks.')
    return {'schema': 'sls-external-monitor-v1', 'ok': report['operational_ready'],
            'state': 'healthy' if report['operational_ready'] else 'attention_required',
            'version': report['version'], 'generated_at': report['generated_at'],
            'report_age_seconds': round(max(0, age), 3), 'checks_requiring_attention': warnings}


def probe(origin, token, timeout=8, max_age=120, connection_factory=None):
    host, port = endpoint(origin)
    factory = connection_factory or http.client.HTTPSConnection
    connection = factory(host, port, timeout=timeout, context=ssl.create_default_context())
    began = time.monotonic()
    try:
        connection.connect()
        remaining = timeout - (time.monotonic() - began)
        if remaining <= 0:
            raise MonitorError('The readiness request exceeded its total deadline.')
        connection.sock.settimeout(remaining)
        connection.request('GET', '/api/sls-mass-notify/?resource=readiness', headers={
            'Authorization': 'Bearer ' + token, 'Accept': 'application/json', 'Cache-Control': 'no-cache',
            'User-Agent': 'SLS-External-Monitor/1'})
        remaining = timeout - (time.monotonic() - began)
        if remaining <= 0:
            raise MonitorError('The readiness request exceeded its total deadline.')
        transport = connection.sock
        transport.settimeout(remaining)
        response = connection.getresponse()
        if response.status != 200:
            retry = response.getheader('Retry-After', '')
            suffix = (' Retry after ' + retry + ' seconds.') if re.fullmatch(r'[0-9]{1,5}', retry) else ''
            raise MonitorError('Readiness request returned HTTP ' + str(response.status) + '.' + suffix)
        if response.getheader('Content-Encoding', 'identity').lower() != 'identity':
            raise MonitorError('The readiness route returned an unsupported compressed response.')
        if not response.getheader('Content-Type', '').lower().startswith('application/json'):
            raise MonitorError('The readiness route returned an unexpected content type.')
        length = response.getheader('Content-Length')
        if length is not None and (not re.fullmatch(r'[0-9]{1,10}', length) or int(length) > MAX_REPORT_BYTES):
            raise MonitorError('The readiness report exceeds the monitoring download limit.')
        body = bytearray()
        while True:
            remaining = timeout - (time.monotonic() - began)
            if remaining <= 0:
                raise MonitorError('The readiness request exceeded its total deadline.')
            transport.settimeout(remaining)
            chunk = response.read1(min(65536, MAX_REPORT_BYTES + 1 - len(body)))
            if not chunk:
                break
            body.extend(chunk)
            if len(body) > MAX_REPORT_BYTES:
                raise MonitorError('The readiness report exceeds the monitoring download limit.')
        if length is not None and len(body) != int(length):
            raise MonitorError('The readiness report was truncated.')
        return evaluate(body, response.headers, max_age=max_age)
    finally:
        connection.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--origin', required=True, help='Configured PBX HTTPS origin; forwarded ports are supported.')
    parser.add_argument('--token-file', required=True, help='Owner-only file containing a named, unrestricted read-only Control API token.')
    parser.add_argument('--timeout', type=int, choices=range(2, 31), default=8, metavar='2..30')
    parser.add_argument('--max-age', type=int, choices=range(30, 301), default=120, metavar='30..300')
    args = parser.parse_args()
    try:
        report = probe(args.origin, credential(args.token_file), args.timeout, args.max_age)
        code = 0 if report['ok'] else 1
    except (MonitorError, OSError, http.client.HTTPException) as error:
        # Provider error bodies and credentials never enter stdout/stderr.
        detail = str(error) if isinstance(error, MonitorError) else 'The monitoring host could not complete a verified HTTPS connection to the PBX.'
        report = {'schema': 'sls-external-monitor-v1', 'ok': False, 'state': 'unavailable', 'message': detail}; code = 2
    print(json.dumps(report, separators=(',', ':'), ensure_ascii=True))
    return code


if __name__ == '__main__':
    raise SystemExit(main())

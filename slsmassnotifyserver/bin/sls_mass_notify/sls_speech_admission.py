#!/usr/bin/python3
"""Shared speech queue, CPU admission and conservative media-space reservations."""
import argparse
import ctypes
import fcntl
import json
import math
import os
from pathlib import Path
import pwd
import re
import resource
import signal
import secrets
import selectors
import stat
import subprocess
import sys
import time
import wave

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_phone_admission import read_private_json
from sls_storage_maintenance import DATA, WEB, MAX_GENERATED_FILES, open_directory, generated_inventory, generated_cache_limit

PIPER = '/usr/local/bin/sls_mass_notify/piper/venv/bin/piper'
MAX_SLOTS = 4
MAX_WAITERS = 32
MIB = 1048576
WAV_HEADER_BYTES = 65536
MAX_DIAGNOSTIC_BYTES = 65536


class SpeechAdmissionError(RuntimeError):
    def __init__(self, message, code=75):
        super().__init__(message)
        self.code = code


def setting(config, name, default, minimum, maximum):
    value = config.get(name, default)
    if type(value) is not int or not minimum <= value <= maximum:
        raise SpeechAdmissionError('Invalid speech admission setting: ' + name, 78)
    return value


class Admission:
    def __init__(self, config, seconds, directory=DATA, web=WEB, clock=time.monotonic):
        self.directory, self.web = Path(directory), Path(web)
        self.limit = setting(config, 'speech_synthesis_limit', 1, 1, MAX_SLOTS)
        self.queue = setting(config, 'speech_queue_limit', 8, 1, MAX_WAITERS)
        self.wait = setting(config, 'speech_queue_wait_seconds', 15, 0, 60)
        self.reserve = setting(config, 'generated_media_min_free_mib', 128, 64, 4096) * MIB
        self.budget = generated_cache_limit(config)
        # Maximum catalog voice rate is below48kHz; reserve raw and two PBX
        # copies plus temporary composition. Conversion holds this for60s.
        self.bytes = seconds * (48000 * 2 + 8000 * 2 * 4) + MIB
        self.clock = clock
        self.fd = open_directory(self.directory)
        self.handles = []
        self.names = {}
        self.active = None
        self.waiter = None
        self.control = None
        self.token = secrets.token_hex(16)
        self.slot_number = 0

    def file(self, name):
        fd = os.open(name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o640, dir_fd=self.fd)
        meta = os.fstat(fd)
        if not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_uid not in (0, os.geteuid()) or meta.st_mode & 0o027 or meta.st_size > 16384:
            os.close(fd)
            raise SpeechAdmissionError('Speech admission storage is unsafe; repair data permissions.', 73)
        self.handles.append(fd)
        self.names[fd] = name
        return fd

    @staticmethod
    def available(fd):
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return True
        except BlockingIOError:
            return False

    def record(self, fd):
        name = self.names[fd].removesuffix('.lock') + '.json'
        try:
            source = os.open(name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=self.fd)
        except FileNotFoundError:
            return []
        try:
            meta = os.fstat(source)
            if not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_size > 16384 or meta.st_uid not in (0, os.geteuid()) or meta.st_mode & 0o027:
                raise SpeechAdmissionError('Speech space reservation metadata is unsafe.', 73)
            raw = os.read(source, 16385)
        finally:
            os.close(source)
        if not raw:
            return []
        try:
            value = json.loads(raw)
            if not isinstance(value, list) or len(value) > 64:
                raise ValueError()
            for row in value:
                if not isinstance(row, dict) or type(row.get('bytes')) is not int or not 0 <= row['bytes'] <= 512 * MIB or type(row.get('expires')) not in (int, float) or not math.isfinite(row['expires']) or not isinstance(row.get('token'), str) or re.fullmatch('[a-f0-9]{32}',row['token']) is None:
                    raise ValueError()
            return [row for row in value if row['expires'] > time.time()]
        except (ValueError, UnicodeError):
            raise SpeechAdmissionError('Speech space reservation is invalid; inspect admission storage.', 73)

    def write(self, fd, value):
        raw = json.dumps(value, separators=(',', ':')).encode()
        name = self.names[fd].removesuffix('.lock') + '.json'
        temporary = '.speech-budget-' + secrets.token_hex(12)
        try:
            output = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o640, dir_fd=self.fd)
            with os.fdopen(output, 'wb') as handle:
                if handle.write(raw) != len(raw):
                    raise SpeechAdmissionError('Speech space reservation could not be fully saved.', 73)
                handle.flush(); os.fsync(handle.fileno())
            os.replace(temporary, name, src_dir_fd=self.fd, dst_dir_fd=self.fd)
            os.fsync(self.fd)
        finally:
            try: os.unlink(temporary, dir_fd=self.fd)
            except FileNotFoundError: pass

    def reserve_media(self, amount):
        """Budget a non-speech render without occupying a synthesis slot."""
        self.bytes = amount
        control = self.file('speech-admission.lock')
        slots = [self.file('speech-slot-%02d.lock' % index) for index in range(MAX_SLOTS)]
        fcntl.flock(control, fcntl.LOCK_EX)
        try:
            media = generated_inventory(self.directory, self.web)
            if len(media) >= MAX_GENERATED_FILES:
                raise SpeechAdmissionError('Generated-media file count reached its safety limit. Run storage maintenance and review retained references.', 73)
            used = sum(row[3].st_size for row in media)
            reserved = sum(row['bytes'] for slot in slots for row in self.record(slot))
            if used + reserved + amount > self.budget:
                raise SpeechAdmissionError('Generated media has insufficient cache budget for this render. Review queued alerts or increase the cache budget.', 73)
            for path in (self.directory, self.web, Path('/tmp')):
                if not path.exists(): continue
                filesystem = os.statvfs(path)
                if filesystem.f_bavail * filesystem.f_frsize < self.reserve + reserved + amount:
                    raise SpeechAdmissionError('Generated media would consume the protected free-space reserve. Free disk space before retrying.', 73)
            target = next((slot for slot in slots if len(self.record(slot)) < 64), None)
            if target is None:
                raise SpeechAdmissionError('Generated-media reservation capacity is full. Wait for current renders to finish.', 75)
            self.write(target, self.record(target) + [{'token': self.token, 'bytes': amount, 'expires': time.time() + 60}])
        finally:
            fcntl.flock(control, fcntl.LOCK_UN)

    def acquire(self):
        control = self.file('speech-admission.lock')
        self.control = control
        deadline = self.clock() + self.wait
        fcntl.flock(control, fcntl.LOCK_EX)
        try:
            for index in range(self.queue):
                waiter = self.file('speech-wait-%02d.lock' % index)
                if self.available(waiter):
                    self.waiter = waiter
                    break
            if self.waiter is None:
                raise SpeechAdmissionError('Speech generation queue is full. Wait for current speech jobs to finish.')
        finally:
            fcntl.flock(control, fcntl.LOCK_UN)
        slots = [self.file('speech-slot-%02d.lock' % index) for index in range(MAX_SLOTS)]
        while True:
            fcntl.flock(control, fcntl.LOCK_EX)
            unlocked = []
            try:
                for slot in slots:
                    if self.available(slot):
                        unlocked.append(slot)
                if MAX_SLOTS - len(unlocked) < self.limit:
                    candidate = next((slot for slot in unlocked if len(self.record(slot)) < 64), None)
                    if candidate is not None:
                        rows = generated_inventory(self.directory, self.web)
                        if len(rows) >= MAX_GENERATED_FILES:
                            raise SpeechAdmissionError('Generated-media file count reached its safety limit. Run storage maintenance and review retained references.', 73)
                        used = sum(row[3].st_size for row in rows)
                        reserved = sum(row['bytes'] for slot in slots for row in self.record(slot))
                        if used + reserved + self.bytes > self.budget:
                            raise SpeechAdmissionError('Generated media has insufficient cache budget for this speech job. Review queued alerts or increase the cache budget.', 73)
                        for path in (self.directory, Path('/tmp')):
                            filesystem = os.statvfs(path)
                            if filesystem.f_bavail * filesystem.f_frsize < self.reserve + reserved + self.bytes:
                                raise SpeechAdmissionError('Speech generation would consume the protected free-space reserve. Free disk space before retrying.', 73)
                        self.write(candidate, self.record(candidate) + [{'token': self.token, 'bytes': self.bytes, 'expires': time.time() + min(900, 2 * self.bytes / 160000 + 30) + 65}])
                        self.active = candidate
                        self.slot_number = slots.index(candidate)
                        unlocked.remove(candidate)
                        fcntl.flock(self.waiter, fcntl.LOCK_UN)
                        self.waiter = None
                        return self
            finally:
                for slot in unlocked:
                    fcntl.flock(slot, fcntl.LOCK_UN)
                fcntl.flock(control, fcntl.LOCK_UN)
            if self.clock() >= deadline:
                raise SpeechAdmissionError('Speech generation waited too long for capacity. Current speech jobs are still running.')
            time.sleep(.1)

    def close(self):
        if self.fd is None: return
        try:
            if self.active is not None:
                # Hold space across duration checks/conversion, including a
                # short grace after failed jobs without inferring success.
                fcntl.flock(self.control, fcntl.LOCK_EX)
                try:
                    rows = self.record(self.active)
                    for row in rows:
                        if row['token'] == self.token: row['expires'] = time.time() + 60
                    self.write(self.active, rows)
                finally:
                    fcntl.flock(self.control, fcntl.LOCK_UN)
        finally:
            for fd in reversed(self.handles): os.close(fd)
            self.handles = []
            os.close(self.fd)
            self.fd = None


def speech_output(command, seconds):
    """Capture the selected voice rate and a private, single-inode WAV target."""
    def argument(name):
        if command.count(name) != 1:
            raise SpeechAdmissionError('Speech requires one selected voice and one private output file.', 78)
        position = command.index(name) + 1
        if position >= len(command):
            raise SpeechAdmissionError('Speech output arguments are incomplete.', 78)
        return position, command[position]
    _, model = argument('--model')
    position, output = argument('--output-file')
    descriptor = os.open(model + '.json', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    try:
        meta = os.fstat(descriptor)
        if not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_uid not in (0, os.geteuid()) or meta.st_size > 131072 or meta.st_mode & 0o022:
            raise SpeechAdmissionError('The selected voice metadata is unsafe. Repair the selected voice installation.', 78)
        raw = os.read(descriptor, 131073)
        try:
            value = json.loads(raw)
            rate = value['audio']['sample_rate']
        except (ValueError, TypeError, KeyError):
            raise SpeechAdmissionError('The selected voice metadata is invalid. Repair the selected voice installation.', 78) from None
        if type(rate) is not int or not 8000 <= rate <= 48000:
            raise SpeechAdmissionError('The selected voice has an unsupported sample rate. Repair the selected voice installation.', 78)
    finally:
        os.close(descriptor)
    if not os.path.isabs(output):
        raise SpeechAdmissionError('Speech output requires an absolute private file path.', 78)
    parent = open_directory(Path(output).parent)
    try:
        descriptor = os.open(Path(output).name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o600, dir_fd=parent)
    finally:
        os.close(parent)
    meta = os.fstat(descriptor)
    if not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or meta.st_uid != os.geteuid() or meta.st_size != 0 or meta.st_mode & 0o022:
        os.close(descriptor)
        raise SpeechAdmissionError('Speech output must be an empty private regular file. Discard the failed workspace before retrying.', 73)
    # Piper reopens this descriptor alias instead of a replaceable pathname.
    command = list(command)
    command[position] = '/proc/self/fd/' + str(descriptor)
    return command, descriptor, output, rate * 2 * seconds + WAV_HEADER_BYTES


def launch(command, timeout, slot=0, output_fd=None, max_bytes=None):
    parent = os.getpid()
    def prepare():
        # Stop synthesis when its admission supervisor is killed externally.
        if ctypes.CDLL(None).prctl(1, signal.SIGKILL) != 0:
            os._exit(125)
        if os.getppid() != parent:
            os._exit(125)
        if max_bytes is not None:
            resource.setrlimit(resource.RLIMIT_FSIZE, (max_bytes, max_bytes))
            signal.signal(signal.SIGXFSZ, signal.SIG_DFL)
        os.nice(10)
        if hasattr(os, 'sched_getaffinity'):
            cpus = sorted(os.sched_getaffinity(0))
            os.sched_setaffinity(0, {cpus[slot % len(cpus)]})
    environment = dict(os.environ, OMP_NUM_THREADS='1', OPENBLAS_NUM_THREADS='1', MKL_NUM_THREADS='1', NUMEXPR_NUM_THREADS='1')
    # Pipe diagnostics through the unrestricted supervisor: the raw-audio file
    # limit must never cap an existing worker log or admission state write.
    child = subprocess.Popen(command, env=environment, preexec_fn=prepare, start_new_session=True,
                             pass_fds=(() if output_fd is None else (output_fd,)), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    selector = selectors.DefaultSelector()
    for stream in (child.stdout, child.stderr):
        os.set_blocking(stream.fileno(), False)
        selector.register(stream, selectors.EVENT_READ)
    copied = {child.stdout: 0, child.stderr: 0}
    deadline = time.monotonic() + timeout
    def terminate(signum, frame):
        try: os.killpg(child.pid, signal.SIGTERM)
        except ProcessLookupError: pass
        raise KeyboardInterrupt()
    previous = {sig: signal.signal(sig, terminate) for sig in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP)}
    try:
        while selector.get_map() or child.poll() is None:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise subprocess.TimeoutExpired(command, timeout)
            for key, _ in selector.select(min(.1, remaining)):
                block = os.read(key.fd, 65536)
                if not block:
                    selector.unregister(key.fileobj)
                    continue
                allowed = MAX_DIAGNOSTIC_BYTES - copied[key.fileobj]
                if allowed > 0:
                    target = sys.stdout if key.fileobj is child.stdout else sys.stderr
                    target.write(block[:allowed].decode('utf-8', 'replace')); target.flush()
                    copied[key.fileobj] += min(allowed, len(block))
        code = child.wait()
        if max_bytes is not None and os.fstat(output_fd).st_size >= max_bytes:
            return 65
        return code
    except (subprocess.TimeoutExpired, KeyboardInterrupt):
        try: os.killpg(child.pid, signal.SIGTERM)
        except ProcessLookupError: pass
        try: child.wait(timeout=2)
        except subprocess.TimeoutExpired:
            os.killpg(child.pid, signal.SIGKILL)
            child.wait(timeout=2)
        return 124
    finally:
        selector.close()
        child.stdout.close(); child.stderr.close()
        for sig, handler in previous.items(): signal.signal(sig, handler)


def synthesize(command, seconds, timeout, slot=0):
    command, descriptor, output, limit = speech_output(command, seconds)
    try:
        code = launch(command, timeout, slot, descriptor, limit)
        if code == 0:
            try:
                with os.fdopen(os.dup(descriptor), 'rb') as handle:
                    handle.seek(0)
                    with wave.open(handle, 'rb') as generated:
                        expected_rate = (limit - WAV_HEADER_BYTES) // (2 * seconds)
                        if generated.getnchannels() != 1 or generated.getsampwidth() != 2 or generated.getframerate() != expected_rate or generated.getnframes() < 1:
                            code = 74
                        elif generated.getnframes() / generated.getframerate() > seconds:
                            code = 65
            except (OSError, EOFError, wave.Error):
                code = 74
        if code == 65:
            print('Generated speech exceeds the configured maximum of %d seconds or its reserved output bound. Audio was rejected; shorten the message or increase Maximum Speech Duration.' % seconds, file=sys.stderr)
        if code != 0:
            named = os.stat(output, follow_symlinks=False)
            captured = os.fstat(descriptor)
            if (named.st_dev, named.st_ino) == (captured.st_dev, captured.st_ino):
                os.unlink(output)
        return code
    finally:
        os.close(descriptor)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--max-seconds', type=int)
    parser.add_argument('--reserve-media-bytes', type=int)
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command[1:] if args.command[:1] == ['--'] else args.command
    if args.reserve_media_bytes is not None:
        if command or args.max_seconds is not None or not 1 <= args.reserve_media_bytes <= 128 * MIB:
            parser.error('A media reservation accepts 1 byte through 128 MiB and no command.')
    elif args.max_seconds is None or not 1 <= args.max_seconds <= 600 or not command or command[0] != PIPER:
        parser.error('Only the fixed SLS Piper executable and a 1–600 second speech limit are accepted.')
    admission = None
    try:
        if os.geteuid() == 0:
            account = pwd.getpwnam('asterisk')
            os.setgroups([])
            os.setgid(account.pw_gid)
            os.setuid(account.pw_uid)
        admission = Admission(read_private_json('mass-notifications.config'), args.max_seconds or 1)
        if args.reserve_media_bytes is not None:
            admission.reserve_media(args.reserve_media_bytes)
            return 0
        admission.acquire()
        return synthesize(command, args.max_seconds, min(900, args.max_seconds * 2 + 30), admission.slot_number)
    except SpeechAdmissionError as error:
        print(str(error), file=sys.stderr)
        return error.code
    except (OSError, RuntimeError, ValueError):
        print('Speech admission could not verify configuration, storage or lock permissions. Inspect SLS diagnostics before retrying.', file=sys.stderr)
        return 73
    finally:
        if admission is not None:
            try: admission.close()
            except (OSError,RuntimeError,ValueError):
                print('Speech reservation completion could not be saved. Inspect private storage and free space before retrying.',file=sys.stderr)
                return 73


if __name__ == '__main__':
    raise SystemExit(main())

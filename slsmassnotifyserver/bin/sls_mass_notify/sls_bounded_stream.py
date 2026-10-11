#!/usr/bin/python3
"""Write bounded download input to a private root-owned file."""
import os
from pathlib import Path
import stat
import sys


def write_stream(path, limit):
    if os.geteuid() != 0 or not 0 < limit <= 64 * 1024 * 1024:
        raise ValueError('Protected downloads require root and a valid byte limit')
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError('Download output must use an absolute protected path')
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    parent = os.open('/', flags)
    try:
        for name in path.parts[1:-1]:
            child = os.open(name, flags, dir_fd=parent)
            os.close(parent); parent = child
            info = os.fstat(parent)
            if info.st_uid != 0 or info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX:
                raise ValueError('Download output directory is not protected')
        flags = os.O_WRONLY | os.O_NOFOLLOW | os.O_NONBLOCK
        try:
            fd = os.open(path.name, flags | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=parent)
        except FileExistsError:
            fd = os.open(path.name, flags, dir_fd=parent)
        with os.fdopen(fd, 'wb') as output:
            info = os.fstat(output.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o600:
                raise ValueError('Download output is not a private root-owned file')
            os.ftruncate(output.fileno(), 0)
            written = 0
            while True:
                chunk = sys.stdin.buffer.read(min(65536, limit - written + 1))
                if not chunk: break
                if written + len(chunk) > limit:
                    raise ValueError('Download exceeds its byte limit')
                if output.write(chunk) != len(chunk):
                    raise OSError('Download write failed')
                written += len(chunk)
            output.flush(); os.fsync(output.fileno())
    finally:
        os.close(parent)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 3: raise ValueError('Expected output path and byte limit')
        write_stream(sys.argv[1], int(sys.argv[2]))
    except (OSError, ValueError) as error:
        print('SLS bounded download: ' + str(error), file=sys.stderr)
        sys.exit(1)

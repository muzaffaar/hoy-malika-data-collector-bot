#!/usr/bin/env python3
"""Single-destination HTTPS pull agent. No third-party dependencies."""
import hashlib
import json
import logging
import os
from pathlib import Path, PurePosixPath
import signal
import time
import urllib.request
from urllib.parse import urlparse


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError('Redirect refused to protect authorization token')


class SyncAgent:
    def __init__(self, server, token, root):
        parsed = urlparse(server)
        if parsed.scheme != 'https' or not parsed.netloc or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError('SERVER_URL must be an HTTPS origin')
        if len(token) < 32:
            raise ValueError('API_TOKEN must contain at least 32 characters')
        self.base = server.rstrip('/') + '/api/dataset-sync/'
        self.token = token
        self.root = Path(root).resolve()
        self.root.mkdir(parents=True, exist_ok=True)
        self.opener = urllib.request.build_opener(NoRedirect())

    def request(self, endpoint, data=None):
        req = urllib.request.Request(self.base + endpoint,
            data=json.dumps(data).encode() if data is not None else None,
            headers={'Authorization': 'Bearer ' + self.token, 'Accept': 'application/json', 'Content-Type': 'application/json'})
        return self.opener.open(req, timeout=90)

    def path(self, relative):
        parts = PurePosixPath(relative)
        if parts.is_absolute() or '..' in parts.parts or '\\' in relative or ':' in relative:
            raise ValueError('Unsafe dataset path')
        destination = self.root.joinpath(*parts.parts).resolve()
        if destination == self.root or self.root not in destination.parents:
            raise ValueError('Dataset path escapes root')
        return destination

    def sync_one(self, item):
        destination = self.path(item['relative_storage_path'])
        expected = item['sha256_checksum']
        size = int(item['file_size_bytes'])
        if destination.exists():
            if digest(destination) != expected or destination.stat().st_size != size:
                raise RuntimeError('Existing original differs; manual investigation required')
        else:
            destination.parent.mkdir(parents=True, exist_ok=True)
            temporary = destination.with_name(destination.name + '.part')
            try:
                with self.request(item['id'] + '/download') as response, temporary.open('wb') as output:
                    received = 0
                    while chunk := response.read(1024 * 1024):
                        received += len(chunk)
                        if received > size:
                            raise RuntimeError('Download exceeds expected size')
                        output.write(chunk)
                    output.flush()
                    os.fsync(output.fileno())
                if temporary.stat().st_size != size or digest(temporary) != expected:
                    raise RuntimeError('Downloaded checksum/size mismatch')
                # A process-wide lock below excludes competing agent instances.
                if destination.exists():
                    raise RuntimeError('Destination appeared during download')
                temporary.rename(destination)
                if os.name != 'nt':
                    fd = os.open(destination.parent, os.O_RDONLY)
                    try:
                        os.fsync(fd)
                    finally:
                        os.close(fd)
            finally:
                temporary.unlink(missing_ok=True)
        with self.request(item['id'] + '/complete', {'sha256': expected, 'size': size}):
            pass
        logging.info('Synchronized %s', item['id'])

    def cycle(self):
        # Deletion requests are processed before new downloads.
        with self.request('deletions') as response:
            deletions = json.load(response)
        for item in deletions:
            try:
                path = self.path(item['relative_storage_path'])
                if path.exists():
                    if digest(path) != item['sha256_checksum']:
                        raise RuntimeError('Deletion checksum mismatch')
                    path.unlink()
                path.with_name(path.name + '.part').unlink(missing_ok=True)
                with self.request(item['id'] + '/deleted', {}):
                    pass
            except Exception as exc:
                logging.error('Deletion pending for %s (%s)', item['id'], type(exc).__name__)
        with self.request('pending') as response:
            files = json.load(response)
        for item in files:
            try:
                self.sync_one(item)
            except Exception as exc:
                logging.error('Sync pending for %s (%s)', item['id'], type(exc).__name__)


def acquire_lock(root):
    handle = (root / '.sync-agent.lock').open('a+b')
    handle.seek(0)
    handle.write(b'0')
    handle.flush()
    handle.seek(0)
    if os.name == 'nt':
        import msvcrt
        msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
    else:
        import fcntl
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
    return handle


def main():
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    agent = SyncAgent(os.environ['SERVER_URL'], os.environ['API_TOKEN'], os.environ['LOCAL_DATASET_PATH'])
    lock = acquire_lock(agent.root)
    running = True

    def stop(*_):
        nonlocal running
        running = False

    signal.signal(signal.SIGINT, stop)
    signal.signal(signal.SIGTERM, stop)
    interval = max(5, int(os.environ.get('POLL_INTERVAL_SECONDS', '30')))
    while running:
        try:
            agent.cycle()
        except Exception as exc:
            logging.error('Server unavailable; will retry (%s)', type(exc).__name__)
        for _ in range(interval):
            if not running:
                break
            time.sleep(1)
    lock.close()


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""Verify SIGTERM reaps active request processes and removes their private storage."""
import concurrent.futures
import ctypes
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('PHP_BINARY', shutil.which('php'))
# Adopt any orphan from a failing regression so this test can always reap its own descendants.
if ctypes.CDLL(None).prctl(36, 1, 0, 0, 0) != 0:  # Linux PR_SET_CHILD_SUBREAPER
    raise RuntimeError('The shutdown integration check requires Linux subreaper support.')

def descendants(pid):
    try:
        children = [int(value) for value in Path(f'/proc/{pid}/task/{pid}/children').read_text().split()]
    except FileNotFoundError:
        return []
    return children + [descendant for child in children for descendant in descendants(child)]

def fetch(port, upload=False):
    body = b'--nour\r\nContent-Disposition: form-data; name="attachments[report]"; filename="report.txt"\r\nContent-Type: text/plain\r\n\r\nshutdown data\r\n--nour--\r\n'
    request = urllib.request.Request(
        f'http://127.0.0.1:{port}'+('/upload-timeout' if upload else '/'),
        data=body if upload else None,
        headers={'Content-Type': 'multipart/form-data; boundary=nour'} if upload else {},
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            response.read()
            return response.status
    except urllib.error.HTTPError as error:
        return error.code
    except (OSError, ConnectionError):
        return 'disconnected'

sock = socket.socket()
sock.bind(('127.0.0.1', 0))
port = sock.getsockname()[1]
sock.close()
with tempfile.TemporaryFile() as log, concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
    server = subprocess.Popen([
        PHP, str(ROOT/'bin/nour-serve'), '--bootstrap', str(ROOT/'tests/fixtures/runtime/app.php'),
        '--port', str(port), '--timeout', '30',
    ], cwd=ROOT, stdout=log, stderr=log)
    worker = None
    directory = None
    try:
        for _ in range(100):
            if fetch(port) == 200:
                break
            if server.poll() is not None:
                raise AssertionError('Gateway stopped before becoming ready')
            time.sleep(.05)
        else:
            raise AssertionError('Gateway failed to become ready')
        pending = pool.submit(fetch, port, True)
        for _ in range(200):
            for pid in descendants(server.pid):
                try:
                    command = Path(f'/proc/{pid}/cmdline').read_bytes().split(b'\x00')
                except FileNotFoundError:
                    continue
                if any(value.endswith(b'/bin/request-worker.php') for value in command):
                    worker = pid
                    directory = Path(command[-2].decode())
            if directory is not None and (directory/'nested/partial').exists():
                break
            time.sleep(.02)
        else:
            raise AssertionError('The upload request did not reach its active processing stage')
        start = time.monotonic()
        server.terminate()
        server.wait(timeout=10)
        try:
            os.kill(worker, 0)
        except ProcessLookupError:
            pass
        else:
            raise AssertionError('SIGTERM left an unreaped application process')
        assert not directory.exists(), 'SIGTERM left private request files'
        assert pending.result() in ['disconnected', 502]
        print(json.dumps({'shutdown_seconds': time.monotonic()-start, 'request_process_reaped': True, 'private_storage_removed': True}))
    finally:
        if server.poll() is None:
            server.terminate()
            try:
                server.wait(timeout=10)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
        for pid in [worker] if worker is not None else []:
            try:
                os.kill(pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            try:
                os.waitpid(pid, 0)
            except ChildProcessError:
                pass
        if directory is not None and directory.exists():
            shutil.rmtree(directory)
print('PASS: controlled shutdown cancels/reaps active request processes and removes private uploads')

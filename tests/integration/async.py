#!/usr/bin/env python3
"""Real Swoole sockets and isolated PHP processes. No remote services or credentials."""
import concurrent.futures
import base64
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('PHP_BINARY', shutil.which('php'))
subprocess.run([PHP, '-r', 'exit(extension_loaded("swoole") ? 0 : 1);'], check=True)

def request(port, path='/', method='GET', data=None, headers=None):
    req = urllib.request.Request(f'http://127.0.0.1:{port}{path}', data=data, headers=headers or {}, method=method)
    try:
        response = urllib.request.urlopen(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()

def serve(concurrency=4):
    sock = socket.socket()
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
    sock.close()
    log = tempfile.TemporaryFile()
    process = subprocess.Popen([
        PHP, str(ROOT/'bin/nour-serve'), '--bootstrap', str(ROOT/'tests/fixtures/runtime/app.php'),
        '--port', str(port), '--concurrency', str(concurrency), '--timeout', '2', '--max-response-bytes', '16384',
    ], cwd=ROOT, stdout=log, stderr=log)
    for _ in range(100):
        if process.poll() is not None:
            log.seek(0)
            raise AssertionError(log.read().decode())
        try:
            if request(port)[0] == 200:
                return process, log, port
        except OSError:
            time.sleep(.05)
    raise AssertionError('Runtime failed to start')

process, log, port = serve()
try:
    assert request(port)[2] == b'runtime ready'
    assert json.loads(request(port, '/context')[2]) == {'console': False, 'memory': '128M'}
    assert request(port, method='HEAD')[2] == b''
    status, _, body = request(port, '/missing', headers={'Accept': 'application/json'})
    assert status == 404 and isinstance(json.loads(body), dict)

    begin = time.monotonic()
    request(port, '/delay/one?id=one')
    request(port, '/delay/two?id=two')
    serial = time.monotonic() - begin
    begin = time.monotonic()
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        one = pool.submit(request, port, '/delay/one?id=one', headers={'Host': 'one.example'})
        two = pool.submit(request, port, '/delay/two?id=two', headers={'Host': 'two.example'})
        outputs = [json.loads(future.result()[2]) for future in [one, two]]
    parallel = time.monotonic() - begin
    assert parallel < serial * .85, (serial, parallel)
    for value, name in zip(outputs, ['one', 'two']):
        assert value == {'id': name, 'requests': 1, 'query': name, 'host': f'http://{name}.example'}, value

    begin = time.monotonic()
    assert json.loads(request(port, '/async')[2]) == {'first': 'first', 'second': 'second'}
    intra_request = time.monotonic() - begin
    # Task intervals are checked by concurrent.php; cold process startup is not a timing contract.

    value = json.loads(request(port, '/echo', 'POST', b'{"name":"Nour"}', {'Content-Type': 'application/json', 'Cookie': 'session=test', 'Proxy': 'http://untrusted'})[2])
    assert value['json'] == {'name': 'Nour'} and value['cookies'] == {'session': 'test'} and not value['proxy'], value
    value = json.loads(request(port, '/echo?a=one%20two', 'POST', b'0', {'Content-Type': 'application/octet-stream'})[2])
    assert base64.b64decode(value['content']) == b'0' and value['uri'] == '/echo?a=one%20two', value
    value = json.loads(request(port, '/echo', 'POST', b'name=Nour', {'Content-Type': 'application/x-www-form-urlencoded'})[2])
    assert value['form'] == {'name': 'Nour'}, value
    multipart = b'--nour\r\nContent-Disposition: form-data; name="attachments[report]"; filename="report.txt"\r\nContent-Type: text/plain\r\n\r\nhello upload\r\n--nour--\r\n'
    value = json.loads(request(port, '/echo', 'POST', multipart, {'Content-Type': 'multipart/form-data; boundary=nour'})[2])
    assert value['file'] == {'name': 'report.txt', 'content': 'hello upload', 'valid': True}, value
    assert value['file_global'] == 'report.txt', value
    moved = json.loads(request(port, '/move', 'POST', multipart, {'Content-Type': 'multipart/form-data; boundary=nour'})[2])
    assert moved == {'content': 'hello upload'}
    before = set(Path(tempfile.gettempdir()).glob('nour-request-*'))
    assert request(port, '/upload-timeout', 'POST', multipart, {'Content-Type': 'multipart/form-data; boundary=nour'})[0] == 504
    time.sleep(.05)
    assert set(Path(tempfile.gettempdir()).glob('nour-request-*')) == before, 'Timed-out uploads must be cleaned by the gateway'
    with socket.create_connection(('127.0.0.1', port), timeout=3) as connection:
        connection.sendall(b'POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: 3000000\r\nConnection: close\r\n\r\n')
        assert b' 413 ' in connection.recv(4096).split(b'\r\n')[0]
    status, headers, body = request(port, '/cookies')
    assert status == 200 and body == b'binary\x00\xff' and len(headers.get_all('Set-Cookie')) == 2, (status, headers, body)
    assert headers.get('X-Private-Hop') is None
    assert request(port, '/stream')[2] == b'part onepart two'
    for path, status in [('/large', 502), ('/exit', 502), ('/timeout', 504)]:
        assert request(port, path)[0] == status, path
    marker = Path(json.loads(request(port, '/termination')[2])['marker'])
    assert marker.read_text() == 'done'
    marker.unlink()
    assert request(port)[0] == 200
    print(json.dumps({'serial_seconds': serial, 'parallel_seconds': parallel, 'concurrent_io_request_seconds': intra_request}))
finally:
    process.terminate()
    process.wait(timeout=15)
    log.seek(0)
    output = log.read().decode()
    log.close()
    if 'Fatal error' in output:
        raise AssertionError(output)

process, log, port = serve(concurrency=1)
try:
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
        pending = pool.submit(request, port, '/timeout')
        time.sleep(.2)
        assert request(port)[0] == 503
        assert pending.result()[0] == 504
    assert request(port)[0] == 200
finally:
    process.terminate()
    process.wait(timeout=15)
    log.close()
print('PASS: async overlap, request isolation, concurrent I/O, HTTP semantics, uploads, deadlines, process failures and backpressure')

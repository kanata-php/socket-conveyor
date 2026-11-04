#!/usr/bin/env python3
"""Exercise the built image through Docker and a real signed broadcast."""

import base64
import hashlib
import hmac
import http.client
import json
import os
import socket
import struct
import subprocess
import sys
import time
import urllib.parse


IMAGE = sys.argv[1] if len(sys.argv) > 1 else "socket-conveyor:local"
# Reuse the existing PusherWebSocketTest fixture, never a real account.
APP_ID = "1"
APP_KEY = "278d425bdf160c739803"
APP_SECRET = "7ad3773142a6692b25b8"
ENVIRONMENT = [
    "-e", f"PUSHER_APP_ID={APP_ID}",
    "-e", f"PUSHER_APP_KEY={APP_KEY}",
    "-e", f"PUSHER_APP_SECRET={APP_SECRET}",
]


def docker(*args):
    return subprocess.check_output(["docker", *args], text=True).strip()


def exact(stream, length):
    data = stream.read(length)
    if len(data) != length:
        raise RuntimeError("WebSocket closed before the expected frame arrived")
    return data


def receive(stream):
    first, second = exact(stream, 2)
    assert first & 15 == 1, "Expected a text frame"
    assert not second & 128, "Server frames must be unmasked"
    length = second & 127
    if length == 126:
        length = struct.unpack("!H", exact(stream, 2))[0]
    elif length == 127:
        length = struct.unpack("!Q", exact(stream, 8))[0]
    assert length < 65536, "Unexpectedly large smoke-test frame"
    return json.loads(exact(stream, length))


def subscribe(connection):
    payload = json.dumps({
        "event": "pusher:subscribe", "data": {"channel": "docker-smoke"},
    }).encode()
    assert len(payload) < 126
    mask = os.urandom(4)
    encoded = bytes(value ^ mask[index % 4] for index, value in enumerate(payload))
    connection.sendall(bytes([0x81, 0x80 | len(payload)]) + mask + encoded)


def broadcast(port):
    connection = socket.create_connection(("127.0.0.1", port), timeout=5)
    with connection, connection.makefile("rb") as stream:
        key = base64.b64encode(b"conveyor-smoke-1").decode()
        connection.sendall((
            f"GET /app/{APP_KEY}?protocol=7&client=smoke&version=1 HTTP/1.1\r\n"
            f"Host: 127.0.0.1:{port}\r\nUpgrade: websocket\r\n"
            "Connection: Upgrade\r\nSec-WebSocket-Version: 13\r\n"
            f"Sec-WebSocket-Key: {key}\r\n\r\n"
        ).encode())
        assert b" 101 " in stream.readline(), "WebSocket upgrade failed"
        headers = {}
        while True:
            line = stream.readline()
            if line in (b"\r\n", b""):
                break
            name, value = line.decode().split(":", 1)
            headers[name.lower()] = value.strip()
        expected = base64.b64encode(hashlib.sha1(
            (key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()
        ).digest()).decode()
        assert headers.get("sec-websocket-accept") == expected
        assert receive(stream)["event"] == "pusher:connection_established"
        subscribe(connection)
        assert receive(stream)["event"] == "pusher_internal:subscription_succeeded"

        body = json.dumps({
            "name": "DockerSmoke", "channels": ["docker-smoke"],
            "data": json.dumps({"delivery": "verified"}),
        }).encode()
        path = f"/apps/{APP_ID}/events"
        query = urllib.parse.urlencode(sorted({
            "auth_key": APP_KEY, "auth_timestamp": str(int(time.time())),
            "auth_version": "1.0", "body_md5": hashlib.md5(body).hexdigest(),
        }.items()))
        signature = hmac.new(
            APP_SECRET.encode(), f"POST\n{path}\n{query}".encode(), hashlib.sha256,
        ).hexdigest()
        publisher = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
        try:
            publisher.request("POST", f"{path}?{query}&auth_signature={signature}", body,
                              {"Content-Type": "application/json"})
            response = publisher.getresponse()
            assert response.status == 200, "Signed REST publish failed"
            response.read()
        finally:
            publisher.close()
        event = receive(stream)
        assert event["event"] == "DockerSmoke"
        assert json.loads(event["data"]) == {"delivery": "verified"}


def run_server(internal_port):
    container = docker(
        "run", "-d", "--init", "--read-only", "--tmpfs", "/tmp:rw,nosuid,noexec,size=16m",
        "--cap-drop=ALL", "--security-opt=no-new-privileges", "--health-interval=1s",
        "--health-start-period=1s", "-p", f"127.0.0.1::{internal_port}",
        *ENVIRONMENT, "-e", f"CONVEYOR_PORT={internal_port}", IMAGE,
    )
    try:
        port = int(docker("port", container, str(internal_port)).rsplit(":", 1)[1])
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            state = json.loads(docker("inspect", "-f", "{{json .State}}", container))
            assert state["Running"], "Container exited before becoming healthy"
            if state["Health"]["Status"] == "healthy":
                break
            time.sleep(0.2)
        else:
            raise RuntimeError("Container did not become healthy in 30 seconds")
        assert docker("exec", container, "id", "-u") == "10001"
        broadcast(port)
        docker("stop", "-t", "5", container)
        assert docker("inspect", "-f", "{{.State.ExitCode}}", container) in ("0", "143"), \
            "Container did not shut down cleanly after SIGTERM"
    finally:
        subprocess.run(["docker", "rm", "-f", container], check=True, stdout=subprocess.DEVNULL)


def rejected(extra, expected):
    result = subprocess.run(["docker", "run", "--rm", *extra, IMAGE],
                            text=True, capture_output=True, timeout=30)
    assert result.returncode == 1
    assert expected in result.stderr
    assert APP_SECRET not in result.stdout + result.stderr


rejected([], "Missing required environment variable: PUSHER_APP_ID")
rejected([*ENVIRONMENT, "-e", "CONVEYOR_PORT=0"], "CONVEYOR_PORT must be")
version = docker("run", "--rm", IMAGE, "php", "-r",
                 'echo PHP_VERSION, " ", phpversion("openswoole");')
assert version.startswith("8.5.12 26.2.0"), version
run_server(8080)
run_server(8989)
print(f"PASS: PHP/OpenSwoole {version}; non-root/read-only runtime; signed REST to WebSocket delivery; custom port; invalid config; graceful shutdown")

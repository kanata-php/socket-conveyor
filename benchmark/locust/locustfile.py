from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import sys
import time
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlencode, urlparse


DEFAULT_APP_ID = "local-app"
DEFAULT_APP_KEY = "local-key"
DEFAULT_APP_SECRET = "local-secret"
DEFAULT_CHANNEL = "benchmark-channel"
EVENT_NAME = "benchmark.message"


def target_env(name: str, default: str | None = None) -> str | None:
    value = os.getenv(f"BENCHMARK_{name}")
    if value is not None:
        return value

    return default


def body_md5(body: str) -> str:
    return hashlib.md5(body.encode("utf-8")).hexdigest()


def request_signature(secret: str, method: str, path: str, params: dict[str, str]) -> str:
    unsigned = {key: value for key, value in params.items() if key != "auth_signature"}
    param_string = "&".join(f"{key}={unsigned[key]}" for key in sorted(unsigned))
    string_to_sign = f"{method.upper()}\n{path}\n{param_string}"

    return hmac.new(secret.encode("utf-8"), string_to_sign.encode("utf-8"), hashlib.sha256).hexdigest()


def decode_pusher_data(frame: dict[str, Any]) -> dict[str, Any]:
    data = frame.get("data")
    if isinstance(data, str):
        parsed = json.loads(data)
        return parsed if isinstance(parsed, dict) else {}

    return data if isinstance(data, dict) else {}


def http_base_url(host: str) -> str:
    value = host.strip()
    if not value:
        raise ValueError("Locust target host is empty")

    if "://" not in value:
        value = f"http://{value}"

    parsed = urlparse(value)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise ValueError(f"Locust target host must be an HTTP URL: {host}")

    return value.rstrip("/")


@dataclass(frozen=True)
class BenchmarkConfig:
    app_id: str
    app_key: str
    app_secret: str
    channel: str
    payload_bytes: int
    receive_timeout: float

    @classmethod
    def from_environment(cls) -> "BenchmarkConfig":
        return cls(
            app_id=target_env("APP_ID", os.getenv("PUSHER_APP_ID", DEFAULT_APP_ID)) or DEFAULT_APP_ID,
            app_key=target_env("APP_KEY", os.getenv("PUSHER_APP_KEY", DEFAULT_APP_KEY)) or DEFAULT_APP_KEY,
            app_secret=target_env("APP_SECRET", os.getenv("PUSHER_APP_SECRET", DEFAULT_APP_SECRET)) or DEFAULT_APP_SECRET,
            channel=target_env("CHANNEL", DEFAULT_CHANNEL) or DEFAULT_CHANNEL,
            payload_bytes=int(target_env("PAYLOAD_BYTES", "256") or "256"),
            receive_timeout=float(target_env("RECEIVE_TIMEOUT", "10") or "10"),
        )


def websocket_url(http_host: str, app_key: str) -> str:
    parsed = urlparse(http_base_url(http_host))
    scheme = "wss" if parsed.scheme == "https" else "ws"

    return (
        f"{scheme}://{parsed.netloc}/app/{app_key}"
        "?protocol=7&client=socket-conveyor-locust&version=1.0&flash=false"
    )


def publish_url(http_host: str, path: str, params: dict[str, str]) -> str:
    return f"{http_base_url(http_host)}{path}?{urlencode(params)}"


def signed_publish_request(config: BenchmarkConfig, sequence: int) -> tuple[str, dict[str, str], str]:
    data = json.dumps(
        {
            "sequence": sequence,
            "sent_at": time.time(),
            "payload": "x" * max(0, config.payload_bytes),
        },
        separators=(",", ":"),
    )
    body = json.dumps(
        {
            "name": EVENT_NAME,
            "channel": config.channel,
            "data": data,
        },
        separators=(",", ":"),
    )
    path = f"/apps/{config.app_id}/events"
    params = {
        "auth_key": config.app_key,
        "auth_timestamp": str(int(time.time())),
        "auth_version": "1.0",
        "body_md5": body_md5(body),
    }
    params["auth_signature"] = request_signature(config.app_secret, "POST", path, params)

    return path, params, body


def run_self_test() -> None:
    fixture_body = '{"name":"foo","channels":["project-3"],"data":"{\\"some\\":\\"data\\"}"}'
    expected_md5 = "ec365a775a4cd0599faeb73354201b6f"
    if body_md5(fixture_body) != expected_md5:
        raise RuntimeError("body_md5 self-test failed")

    params = {
        "auth_key": "278d425bdf160c739803",
        "auth_timestamp": "1353088179",
        "auth_version": "1.0",
        "body_md5": expected_md5,
    }
    signature = request_signature("7ad3773142a6692b25b8", "POST", "/apps/3/events", params)
    if signature != "da454824c97ba181a32ccc17a72625ba02771f50b50e1e7430e47a1f3f457e6c":
        raise RuntimeError("REST signature self-test failed")

    decoded = decode_pusher_data({"data": '{"socket_id":"1.2","activity_timeout":120}'})
    if decoded.get("socket_id") != "1.2":
        raise RuntimeError("Pusher data decode self-test failed")

    if http_base_url("127.0.0.1:8991") != "http://127.0.0.1:8991":
        raise RuntimeError("HTTP host normalization self-test failed")

    if websocket_url("127.0.0.1:8991", "local-key").split("/app/", 1)[0] != "ws://127.0.0.1:8991":
        raise RuntimeError("WebSocket URL normalization self-test failed")

    config = BenchmarkConfig.from_environment()
    path, params, body = signed_publish_request(config, 1)
    if path != f"/apps/{config.app_id}/events" or "auth_signature" not in params or not body:
        raise RuntimeError("Signed publish request self-test failed")

    if not publish_url("127.0.0.1:8991", path, params).startswith("http://127.0.0.1:8991/apps/"):
        raise RuntimeError("Publish URL normalization self-test failed")

    print("locust benchmark self-test passed")


if __name__ == "__main__" and "--self-test" in sys.argv:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    parser.parse_args()
    run_self_test()
    raise SystemExit(0)


from locust import HttpUser, between, task
from websocket import WebSocketTimeoutException, create_connection


class PusherBenchmarkUser(HttpUser):
    wait_time = between(
        float(target_env("WAIT_MIN_SECONDS", "0.01") or "0.01"),
        float(target_env("WAIT_MAX_SECONDS", "0.05") or "0.05"),
    )

    def on_start(self) -> None:
        self.config = BenchmarkConfig.from_environment()
        self.sequence = 0
        self.ws = None
        self.connect_websocket()
        self.subscribe()

    def on_stop(self) -> None:
        if self.ws is not None:
            self.ws.close()

    def connect_websocket(self) -> None:
        start = time.perf_counter()
        exception = None
        try:
            self.ws = create_connection(websocket_url(self.host, self.config.app_key), timeout=self.config.receive_timeout)
            frame = json.loads(self.ws.recv())
            if frame.get("event") != "pusher:connection_established":
                raise RuntimeError(f"unexpected connection frame: {frame}")
            self.socket_id = decode_pusher_data(frame)["socket_id"]
        except Exception as exc:
            exception = exc
            raise
        finally:
            self.environment.events.request.fire(
                request_type="WS",
                name="connect",
                response_time=(time.perf_counter() - start) * 1000,
                response_length=0,
                exception=exception,
                context={},
            )

    def subscribe(self) -> None:
        start = time.perf_counter()
        exception = None
        try:
            self.ws.send(json.dumps({"event": "pusher:subscribe", "data": {"channel": self.config.channel}}))
            frame = json.loads(self.ws.recv())
            if frame.get("event") != "pusher_internal:subscription_succeeded":
                raise RuntimeError(f"unexpected subscription frame: {frame}")
        except Exception as exc:
            exception = exc
            raise
        finally:
            self.environment.events.request.fire(
                request_type="WS",
                name="subscribe",
                response_time=(time.perf_counter() - start) * 1000,
                response_length=0,
                exception=exception,
                context={},
            )

    @task
    def publish_and_receive(self) -> None:
        self.sequence += 1
        path, params, body = signed_publish_request(self.config, self.sequence)
        url = publish_url(self.host, path, params)

        with self.client.post(
            url,
            data=body,
            headers={"Content-Type": "application/json"},
            name="REST publish",
            catch_response=True,
        ) as response:
            if response.status_code < 200 or response.status_code >= 300:
                response.failure(f"unexpected status {response.status_code}: {response.text[:200]}")

        self.receive_published_event()

    def receive_published_event(self) -> None:
        deadline = time.perf_counter() + self.config.receive_timeout
        start = time.perf_counter()
        exception = None
        response_length = 0

        try:
            while time.perf_counter() < deadline:
                try:
                    raw = self.ws.recv()
                except WebSocketTimeoutException:
                    continue

                response_length += len(raw)
                frame = json.loads(raw)
                if frame.get("event") == EVENT_NAME:
                    return

            raise TimeoutError(f"timed out waiting for {EVENT_NAME}")
        except Exception as exc:
            exception = exc
            raise
        finally:
            self.environment.events.request.fire(
                request_type="WS",
                name="fanout receive",
                response_time=(time.perf_counter() - start) * 1000,
                response_length=response_length,
                exception=exception,
                context={},
            )

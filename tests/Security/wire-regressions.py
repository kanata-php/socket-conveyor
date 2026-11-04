#!/usr/bin/env python3
"""Independent Docker-only threat regressions; fixed public fixture credentials.

Usage: python3 wire-regressions.py --source /absolute/plain/candidate --output DIR
No existing containers, ports, environment secrets, or real accounts are used.
"""
import argparse
import base64
import concurrent.futures
import hashlib
import hmac
import http.client
import json
import os
from pathlib import Path
import re
import select
import shutil
import socket
import struct
import subprocess
import time
import threading
import traceback
import urllib.parse

VENDOR = None
HERE = Path(__file__).resolve().parent
# Existing docker/smoke.py and examples/pusher-real/run-conveyor.php defaults.
APPS = [('1', '278d425bdf160c739803', '7ad3773142a6692b25b8'),
        ('local-app', 'local-key', 'local-secret')]
SUCCESS = 'pusher_internal:subscription_succeeded'


def docker(*args):
    return subprocess.check_output(['docker', *map(str, args)], text=True, stderr=subprocess.STDOUT).strip()


def require(value, message):
    if not value:
        raise AssertionError(message)


class WS:
    def __init__(self, port, app=0, receive_buffer=None):
        self.app = APPS[app]
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        if receive_buffer is not None:
            # Set before connect so TCP window negotiation models a nonreading client.
            self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, receive_buffer)
        self.sock.settimeout(3)
        self.sock.connect(('127.0.0.1', port))
        self.sock.settimeout(3)
        self.sock.sendall((f'GET /app/{self.app[1]}?protocol=7&client=qa HTTP/1.1\r\n'
                           f'Host: 127.0.0.1:{port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n'
                           'Sec-WebSocket-Version: 13\r\n'
                           f'Sec-WebSocket-Key: {base64.b64encode(os.urandom(16)).decode()}\r\n\r\n').encode())
        header = bytearray()
        while not header.endswith(b'\r\n\r\n'):
            data = self.sock.recv(1)
            if not data:
                raise EOFError('Upgrade closed')
            header.extend(data)
        require(b' 101 ' in header.split(b'\r\n')[0], 'Expected valid Pusher upgrade')
        frame = self.recv()
        require(frame['event'] == 'pusher:connection_established', 'Missing established frame')
        self.sid = json.loads(frame['data'])['socket_id']

    def exact(self, n):
        result = bytearray()
        while len(result) < n:
            data = self.sock.recv(n-len(result))
            if not data:
                raise EOFError('WebSocket closed')
            result.extend(data)
        return bytes(result)

    def send(self, payload):
        self.raw(json.dumps(payload, separators=(',', ':')).encode())

    def raw(self, payload, opcode=1, fin=True):
        n, mask = len(payload), os.urandom(4)
        header = bytes([(128 if fin else 0) | opcode])
        if n < 126:
            header += bytes([128 | n])
        elif n < 65536:
            header += bytes([128 | 126]) + struct.pack('!H', n)
        else:
            header += bytes([128 | 127]) + struct.pack('!Q', n)
        self.sock.sendall(header + mask + bytes(c ^ mask[i % 4] for i, c in enumerate(payload)))

    def recv(self):
        a, b = self.exact(2)
        n = b & 127
        if n == 126:
            n = struct.unpack('!H', self.exact(2))[0]
        elif n == 127:
            n = struct.unpack('!Q', self.exact(8))[0]
        require(n < 2**21, 'Unbounded response frame')
        if a & 15 == 8:
            self.exact(n)
            raise EOFError('WebSocket close frame')
        return json.loads(self.exact(n))

    def quiet(self, duration=.12):
        require(not select.select([self.sock], [], [], duration)[0], 'Unexpected cross-app or duplicate frame')

    def auth(self, channel, channel_data=None, sid=None):
        text = f'{sid or self.sid}:{channel}'
        if channel_data is not None:
            text += ':' + channel_data
        return self.app[1] + ':' + hmac.new(self.app[2].encode(), text.encode(), hashlib.sha256).hexdigest()

    def subscribe(self, channel, channel_data=None, auth=None):
        data = {'channel': channel}
        if channel.startswith(('private-', 'presence-')):
            data['auth'] = auth if auth is not None else self.auth(channel, channel_data)
        if channel_data is not None:
            data['channel_data'] = channel_data
        self.send({'event': 'pusher:subscribe', 'data': data})
        return self.recv()

    def unsubscribe(self, channel):
        self.send({'event': 'pusher:unsubscribe', 'data': {'channel': channel}})
        # Ping acts as a queue barrier with one ordered connection.
        self.ping()

    def ping(self):
        self.send({'event': 'pusher:ping', 'data': {}})
        require(self.recv()['event'] == 'pusher:pong', 'Follow-up ping failed')

    def close(self):
        try:
            self.raw(b'', opcode=8)
        except OSError:
            pass
        self.sock.close()


def rest(port, app=0, path='/events', method='POST', payload=None, params=None):
    appid, key, secret = APPS[app]
    path = f'/apps/{appid}' + path
    raw = json.dumps(payload, separators=(',', ':')).encode() if payload is not None else b''
    query = {'auth_key': key, 'auth_timestamp': str(int(time.time())), 'auth_version': '1.0'}
    if raw:
        query['body_md5'] = hashlib.md5(raw).hexdigest()
    query.update(params or {})
    signing = '&'.join(f'{k}={v}' for k, v in sorted(query.items()))
    query['auth_signature'] = hmac.new(secret.encode(), f'{method}\n{path}\n{signing}'.encode(), hashlib.sha256).hexdigest()
    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=4)
    try:
        try:
            conn.request(method, path + '?' + urllib.parse.urlencode(query), raw, {'Content-Type': 'application/json'})
        except (BrokenPipeError, ConnectionResetError):
            # The bounded server can send 413 from Content-Length before draining the body.
            if len(raw) <= 1048576:
                raise
        response = conn.getresponse()
        body = response.read()
        return response.status, json.loads(body) if body else {}
    finally:
        conn.close()


class Server:
    def __init__(self, source, output, label, limits=None, fault=False):
        self.output, self.label, self.clients = output, label, []
        args = ['run', '-d', '--init', '--read-only', '--tmpfs', '/tmp:rw,nosuid,size=32m',
                '--cap-drop=ALL', '--security-opt=no-new-privileges', '--memory', '512m',
                '--cpus', '1', '--pids-limit', '128', '-p', '127.0.0.1::8080',
                '-v', f'{source}:/workspace:ro', '-v', f'{VENDOR}:/workspace/vendor:ro',
                '-v', f'{HERE / "server.php"}:/qa-server.php:ro', '-w', '/workspace']
        if fault:
            args += ['-e', 'QA_PRESENCE_FAILURE=1']
        if limits:
            args += ['-e', 'QA_LIMITS=' + json.dumps(limits)]
        self.cid = docker(*args, 'socket-conveyor-runtime:latest', 'php', '/qa-server.php')
        try:
            self.wait()
        except Exception:
            try:
                (self.output / (self.label + '.startup.log')).write_text(self.logs())
            finally:
                docker('rm', '-f', self.cid)
            raise

    def wait(self):
        self.port = int(docker('port', self.cid, '8080').rsplit(':', 1)[1])
        end = time.monotonic() + 12
        while time.monotonic() < end:
            processes = docker('top', self.cid, '-eo', 'pid,args').splitlines()[1:]
            if sum('php /qa-server.php' in line for line in processes) >= 6:
                return
            time.sleep(.1)
        raise RuntimeError('Disposable server readiness timed out')

    def ws(self, app=0, receive_buffer=None):
        client = WS(self.port, app, receive_buffer)
        self.clients.append(client)
        return client

    def workers(self):
        # OpenSwoole labels worker type; compare task/event worker PID identity.
        return sorted(line.strip() for line in docker('top', self.cid, '-eo', 'pid,args').splitlines()
                      if 'php /qa-server.php' in line and '/sbin/docker-init' not in line)

    def logs(self):
        logs = docker('logs', self.cid)
        for app in APPS:
            for value in app:
                # Values are public fixtures, but avoid emitting signatures/payloads.
                if len(value) > 6:
                    logs = logs.replace(value, '[PUBLIC_FIXTURE]')
        return logs

    def close(self):
        for client in self.clients:
            client.close()
        try:
            (self.output / (self.label + '.container.log')).write_text(self.logs())
        finally:
            docker('rm', '-f', self.cid)


def controlled(frame):
    require(frame.get('event') == 'pusher:error', 'Malformed input was not rejected with pusher:error')
    data = frame.get('data', '')
    if isinstance(data, str):
        data = json.loads(data)
    require(len(str(data.get('message', '')).encode()) <= 160, 'Unbounded error text')


def test_malformed_worker_survival(server):
    a, observer = server.ws(), server.ws()
    require(a.subscribe('private-safe')['event'] == SUCCESS, 'Private control subscribe failed')
    require(observer.subscribe('private-safe')['event'] == SUCCESS, 'Observer control subscribe failed')
    before = server.workers()
    require(before, 'Worker identity measurement unavailable')
    cases = [('long201', {'event': 'pusher:subscribe', 'data': {'channel': 'x' * 201}})]
    cases += [('scalar-' + str(i), {'event': 'client-test', 'channel': 'private-safe', 'data': v})
              for i, v in enumerate([1, True, None, 1.5])]
    cases += [('envelope-' + str(i), v) for i, v in enumerate([None, 1, [], 'text', {'event': []}])]
    cases += [('auth-object', {'event': 'pusher:subscribe', 'data': {'channel': 'private-bad', 'auth': {}}})]
    for name, payload in cases:
        try:
            a.send(payload)
            controlled(a.recv())
            a.ping()
            observer.ping()
        except Exception as error:
            raise AssertionError(f'Malformed case {name}: {type(error).__name__}') from error
    require(before == server.workers(), 'Malformed input restarted a worker')
    require('Fatal error' not in server.logs(), 'Fatal worker exception logged')
    a.send({'event': 'client-recovered', 'channel': 'private-safe', 'data': {'ok': True}})
    require(observer.recv()['event'] == 'client-recovered', 'Valid follow-up traffic failed')
    return {'cases': len(cases), 'worker_pids_unchanged': True}


def test_channel_and_presence_boundaries(server):
    a = server.ws()
    for size in (163, 164):
        name = 'presence-' + 'x' * (size-9)
        # Exact raw byte bounds including whitespace within valid JSON.
        for length in (2047, 2048):
            base = '{"user_id":"boundary","user_info":null}'
            cd = base[:-1] + ' ' * (length-len(base)) + '}'
            require(len(cd.encode()) == length, 'Incorrect boundary fixture')
            require(a.subscribe(name, cd)['event'] == SUCCESS, 'Accepted protocol boundary rejected')
            a.unsubscribe(name)
    for size in (165, 200, 201):
        controlled(a.subscribe('x' * size))
    for channel in ('bad channel', 'bad#channel', 'bad/channel', 'bad:channel', 'bad\x00channel', 'caf\u00e9'):
        controlled(a.subscribe(channel))
    cd = '{"user_id":"boundary","user_info":null}'
    cd = cd[:-1] + ' ' * (2049-len(cd)) + '}'
    controlled(a.subscribe('presence-over', cd))
    for user_id in (True, None, 1.5, [], {}, '', 'x' * 129):
        cd = json.dumps({'user_id': user_id})
        controlled(a.subscribe('presence-invalid-user', cd))
    a.ping()
    return {'channel_accept_bytes': [163, 164], 'presence_accept_bytes': [2047, 2048], 'presence_reject_bytes': 2049}


def test_two_application_isolation(server):
    a, a2, b, b2 = server.ws(0), server.ws(0), server.ws(1), server.ws(1)
    for channel in ('orders', 'private-orders', 'presence-orders'):
        for client, user in ((a, 'a-user'), (a2, 'a-user'), (b, 'b-user'), (b2, 'b-user')):
            cd = json.dumps({'user_id': user, 'user_info': None}) if channel.startswith('presence-') else None
            reply = client.subscribe(channel, cd)
            require(reply['event'] == SUCCESS and reply['channel'] == channel, 'Same-named channel subscribe failed')
            if cd:
                roster = json.loads(reply['data'])['presence']
                require(roster['count'] == 1, 'Presence roster leaked or failed to collapse null-info duplicate user')
                require(set(roster['ids']) == {user}, 'Presence roster crossed apps')
        for client in (a, a2, b, b2):
            client.quiet()
        for app, own, foreign in ((0, (a, a2), (b, b2)), (1, (b, b2), (a, a2))):
            status, _ = rest(server.port, app, payload={'name': 'qa-event', 'channels': [channel], 'data': '{}'})
            require(status == 200, 'Signed publish failed')
            for client in own:
                require(client.recv()['event'] == 'qa-event', 'Within-app REST delivery failed')
            for client in foreign:
                client.quiet()
        if channel.startswith(('private-', 'presence-')):
            a.send({'event': 'client-isolated', 'channel': channel, 'data': {'ok': True}, 'app_key': APPS[1][1]})
            require(a2.recv()['event'] == 'client-isolated', 'Within-app client delivery failed')
            for client in (a, b, b2):
                client.quiet()
        # An app B exclusion must not exclude app A recipients.
        require(rest(server.port, 0, payload={'name': 'qa-exclusion', 'channels': [channel], 'data': '{}', 'socket_id': b.sid})[0] == 200,
                'Cross-app exclusion request failed')
        require(a.recv()['event'] == 'qa-exclusion' and a2.recv()['event'] == 'qa-exclusion', 'Foreign exclusion affected app A')
        b.quiet(); b2.quiet()
    require(a.subscribe('only-a')['event'] == SUCCESS, 'Exclusive channel setup failed')
    status, channels = rest(server.port, 1, '/channels', 'GET')
    require(status == 200 and 'only-a' not in channels.get('channels', {}), 'REST listing leaked occupancy')
    for app, user in ((0, 'a-user'), (1, 'b-user')):
        status, users = rest(server.port, app, '/channels/presence-orders/users', 'GET')
        require(status == 200 and {str(x['id']) for x in users['users']} == {user}, 'REST users crossed apps')
        status, info = rest(server.port, app, '/channels/private-orders', 'GET', params={'info': 'subscription_count'})
        require(status == 200 and info.get('subscription_count') == 2, 'REST single-channel count crossed apps')
    a.close(); a2.close()
    time.sleep(.2)
    require(rest(server.port, 1, '/channels/private-orders', 'GET', params={'info': 'subscription_count'})[1].get('subscription_count') == 2,
            'App A cleanup removed app B membership')
    require(rest(server.port, 1, '/channels/presence-orders/users', 'GET')[1]['users'] == [{'id': 'b-user'}],
            'App A cleanup changed app B roster')
    b.ping(); b2.ping()
    return {'apps': 2, 'channels': ['public', 'private', 'presence'], 'directions': 2, 'rest_and_client_delivery': True}


def test_reconnect_and_restart_replay(server):
    first = server.ws()
    saved = first.auth('private-revocation')
    old_sid = first.sid
    require(first.subscribe('private-revocation', auth=saved)['event'] == SUCCESS, 'Initial authorization failed')
    first.close()
    second = server.ws()
    require(old_sid != second.sid, 'Reconnect repeated socket authorization context')
    controlled(second.subscribe('private-revocation', auth=saved))
    require(second.subscribe('private-revocation')['event'] == SUCCESS, 'Fresh reconnect authorization failed')
    for sid in (old_sid, second.sid):
        require(re.fullmatch(r'[1-9][0-9]{0,18}\.[1-9][0-9]{0,18}', sid), 'Numeric-dot-numeric compatibility broken')
    docker('restart', '-t', '2', server.cid)
    server.wait()
    # Save auth on the first connection after a restart; repeat the same sequence.
    fresh_first = server.ws()
    saved_restart = fresh_first.auth('private-revocation')
    restart_sid = fresh_first.sid
    require(fresh_first.subscribe('private-revocation', auth=saved_restart)['event'] == SUCCESS, 'Pre-restart authorization failed')
    docker('restart', '-t', '2', server.cid)
    server.wait()
    third = server.ws()
    require(third.sid != restart_sid, 'Restart repeated socket authorization context')
    controlled(third.subscribe('private-revocation', auth=saved_restart))
    require(third.subscribe('private-revocation')['event'] == SUCCESS, 'Fresh restart authorization failed')
    return {'reconnect_replay_rejected': True, 'restart_replay_rejected': True}


def test_client_limits_and_rates(server):
    sender, receiver, other_app = server.ws(), server.ws(), server.ws(1)
    for client in (sender, receiver):
        require(client.subscribe('private-client')['event'] == SUCCESS, 'Client setup failed')
    require(other_app.subscribe('other-app')['event'] == SUCCESS, 'Other-app setup failed')
    for size in (9999, 10000):
        sender.send({'event': 'client-ok', 'channel': 'private-client', 'data': 'x' * size})
        require(len(receiver.recv()['data']) == size, 'Valid client data byte boundary failed')
    sender.send({'event': 'client-large', 'channel': 'private-client', 'data': 'x' * 10001})
    controlled(sender.recv()); receiver.quiet()
    for event in ('client-' + 'x' * 194, 'client-\x00bad'):
        sender.send({'event': event, 'channel': 'private-client', 'data': '{}'})
        controlled(sender.recv())
    # Await each result to exercise client-event rate, without filling pending slots.
    errors = 0
    for _ in range(30):
        sender.send({'event': 'client-rate', 'channel': 'private-client', 'data': '{}'})
        ready, _, _ = select.select([sender.sock, receiver.sock], [], [], 3)
        require(ready, 'Rate burst produced no bounded outcome')
        if sender.sock in ready:
            controlled(sender.recv()); errors += 1
            break
        require(receiver.recv()['event'] == 'client-rate', 'Unexpected client event')
    require(errors > 0, 'Client rate burst was not bounded')
    other_app.ping()
    return {'accepted_data_bytes': [9999, 10000], 'rejected_data_bytes': 10001, 'burst_denials': errors}


def test_rest_validation_is_atomic(server):
    observer = server.ws()
    observer.subscribe('rest-atomic')
    valid = {'name': 'qa-atomic', 'channels': ['rest-atomic'], 'data': '{}'}
    cases = [('/events', {**valid, 'channels': ['rest-atomic', 'bad channel']}),
             ('/events', {**valid, 'name': 'pusher:reserved'}),
             ('/events', {**valid, 'socket_id': []}),
             ('/events', {**valid, 'channels': ['c' + str(x) for x in range(101)]}),
             ('/batch_events', {'batch': []}),
             ('/batch_events', {'batch': [valid, {**valid, 'channels': ['bad channel']}]})]
    for path, payload in cases:
        require(rest(server.port, path=path, payload=payload)[0] == 400, 'Invalid REST request accepted')
        observer.quiet()
    require(rest(server.port, payload={**valid, 'channels': ['rest-atomic', 'rest-atomic']})[0] == 200,
            'Valid duplicate channel request failed')
    require(observer.recv()['event'] == 'qa-atomic', 'Valid REST delivery failed')
    observer.quiet()
    require(rest(server.port, payload={**valid, 'data': 'x' * (1048576 + 1)})[0] == 413,
            'HTTP body limit did not reject before parsing')
    observer.ping()
    return {'atomic_invalid_cases': len(cases), 'deduplicated_fanout': True, 'body_limit': 1048576}


def test_membership_quota_recovery(server):
    a, b = server.ws(), server.ws(1)
    for i in range(100):
        require(a.subscribe('quota-' + str(i))['event'] == SUCCESS, 'Default quota rejected <=100 memberships')
        if i % 25 == 24:
            time.sleep(.3)  # Stay below the separate inbound-frame rate budget.
    controlled(a.subscribe('quota-over'))
    require(a.subscribe('quota-0')['event'] == SUCCESS, 'Idempotent subscription charged capacity')
    require(b.subscribe('quota-other-app')['event'] == SUCCESS, 'One app exhausted another app')
    a.unsubscribe('quota-0')
    require(a.subscribe('quota-recovered')['event'] == SUCCESS, 'Unsubscribe failed to return capacity')
    a.close()
    fresh = server.ws()
    require(fresh.subscribe('quota-after-close')['event'] == SUCCESS, 'Disconnect leaked membership budget')
    return {'accepted': 100, 'overflow_rejected': True, 'unsubscribe_and_close_recovery': True}


def test_atomic_low_budgets_and_recovery(server):
    app_a = [server.ws(), server.ws()]
    app_b = [server.ws(1), server.ws(1)]
    def subscribe_many(item):
        client, prefix, count = item
        return [(client, prefix + str(i), client.subscribe(prefix + str(i))) for i in range(count)]
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        a_results = sum(list(pool.map(subscribe_many, [(c, 'a' + str(i) + '-', 3) for i, c in enumerate(app_a)])), [])
    accepted_a = [x for x in a_results if x[2]['event'] == SUCCESS]
    require(len(accepted_a) == 4, 'Concurrent app budget overshot or lost accounting')
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        b_results = sum(list(pool.map(subscribe_many, [(c, 'b' + str(i) + '-', 2) for i, c in enumerate(app_b)])), [])
    require(sum(x[2]['event'] == SUCCESS for x in b_results) == 2, 'Global budget overshot or another app lost headroom')
    client, channel, _ = accepted_a[0]
    client.unsubscribe(channel)
    require(app_b[0].subscribe('recovered-global')['event'] == SUCCESS, 'Shared capacity was not recovered')
    for client in app_a + app_b:
        client.ping()
    return {'app_a_exact_limit': 4, 'server_exact_limit': 6, 'concurrent_workers': 2, 'recovery': True}


def test_presence_duplicate_notifications_and_capacity(server):
    first, duplicate, new_user, foreign = server.ws(), server.ws(), server.ws(), server.ws(1)
    cd = json.dumps({'user_id': 'same', 'user_info': None})
    other = json.dumps({'user_id': 'other', 'user_info': None})
    require(first.subscribe('presence-capacity', cd)['event'] == SUCCESS, 'First presence subscription failed')
    require(duplicate.subscribe('presence-capacity', cd)['event'] == SUCCESS, 'Duplicate-user connection failed')
    first.quiet()
    controlled(new_user.subscribe('presence-capacity', other))
    require(foreign.subscribe('presence-capacity', cd)['event'] == SUCCESS, 'Other app presence budget was blocked')
    first.quiet(); duplicate.quiet()
    duplicate.close()
    time.sleep(.15)
    first.quiet(); foreign.quiet()
    require(new_user.subscribe('presence-capacity', other)['event'] == SUCCESS, 'Presence disconnect did not return capacity')
    member = first.recv()
    require(member['event'] == 'pusher_internal:member_added' and json.loads(member['data'])['user_id'] == 'other', 'First-user added event missing')
    foreign.quiet()
    new_user.unsubscribe('presence-capacity')
    member = first.recv()
    require(member['event'] == 'pusher_internal:member_removed' and json.loads(member['data'])['user_id'] == 'other', 'Last-user removed event missing')
    foreign.quiet()
    return {'duplicate_user_collapses': True, 'connection_limit_counts_duplicates': True, 'first_last_notifications': True}


def test_oversized_fragmented_message_is_contained(server):
    offender, healthy = server.ws(), server.ws(1)
    before = server.workers()
    offender.raw(b'x' * 40000, fin=False)
    offender.raw(b'x' * 40000, opcode=0)
    try:
        controlled(offender.recv())
    except (EOFError, ConnectionResetError):
        pass
    healthy.ping()
    require(before == server.workers(), 'Oversized assembled frame restarted a worker')
    return {'fragmented_bytes': 80000, 'other_app_healthy': True, 'worker_pids_unchanged': True}


def test_custom_presence_partial_failure_rolls_back(server):
    sender, observer, foreign = server.ws(), server.ws(), server.ws(1)
    observer_data = json.dumps({'user_id': 'observer', 'user_info': None})
    foreign_data = json.dumps({'user_id': 'foreign', 'user_info': None})
    require(observer.subscribe('presence-failure', observer_data)['event'] == SUCCESS, 'Custom adapter setup failed')
    require(foreign.subscribe('presence-failure', foreign_data)['event'] == SUCCESS, 'Foreign adapter setup failed')
    before = server.workers()
    failing = json.dumps({'user_id': 'failed', 'user_info': {'fail': True}}, separators=(',', ':'))
    controlled(sender.subscribe('presence-failure', failing))
    users = rest(server.port, 0, '/channels/presence-failure/users', 'GET')[1]['users']
    require(users == [{'id': 'observer'}], 'Failed custom write left a phantom presence user')
    observer.quiet(); foreign.quiet()
    require(sender.subscribe('after-failure')['event'] == SUCCESS, 'Failed custom write leaked socket quota')
    sender.unsubscribe('after-failure')
    good = json.dumps({'user_id': 'recovered', 'user_info': None})
    require(sender.subscribe('presence-failure', good)['event'] == SUCCESS, 'Custom adapter failed to recover')
    require(observer.recv()['event'] == 'pusher_internal:member_added', 'Recovered user was not announced')
    foreign.quiet()
    require(before == server.workers(), 'Custom adapter exception restarted a worker')
    return {'partial_insert_rollback': True, 'custom_adapter_used': True, 'quota_recovered': True, 'worker_pids_unchanged': True}


def test_slow_consumer_does_not_block_healthy_peer(server):
    slow, healthy = server.ws(receive_buffer=1024), server.ws()
    require(slow.subscribe('backpressure')['event'] == SUCCESS, 'Slow-consumer setup failed')
    require(healthy.subscribe('backpressure')['event'] == SUCCESS, 'Healthy-consumer setup failed')
    before = server.workers()
    evidence = {'published': 0, 'received': 0, 'overflow_observed': False,
                'slow_consumer_evicted': False, 'maximum_publish_seconds': 0,
                'saturation_limit': 1600, 'followup_messages': 200, 'progress': []}
    server.slow_evidence = evidence
    stopped = threading.Event()
    errors = []
    seen = set()

    def consume():
        try:
            while not stopped.is_set():
                frame = healthy.recv()
                require(frame['event'] == 'qa-load', 'Healthy peer received an unexpected event')
                sequence = int(frame['data'][:6])
                expected = f'{sequence:06}' + 'x' * 9994
                require(frame['data'] == expected and sequence not in seen,
                        'Healthy peer received a corrupt or duplicate event')
                seen.add(sequence)
                evidence['received'] += 1
        except Exception as error:
            if not stopped.is_set():
                errors.append(f'{type(error).__name__}: {error}')

    def publish():
        data = f"{evidence['published']:06}" + 'x' * 9994
        start = time.monotonic()
        status, _ = rest(server.port, payload={'name': 'qa-load', 'channels': ['backpressure'], 'data': data})
        elapsed = time.monotonic() - start
        evidence['maximum_publish_seconds'] = max(evidence['maximum_publish_seconds'], elapsed)
        require(status == 200, 'Bounded backpressure publish failed')
        evidence['published'] += 1
        require(elapsed < 3, 'Slow consumer exceeded finite publication timeout')

    def progress():
        end = time.monotonic() + 1
        while evidence['received'] < evidence['published'] and not errors and time.monotonic() < end:
            time.sleep(.005)
        evidence['progress'].append({'published': evidence['published'], 'received': evidence['received']})
        require(not errors and seen == set(range(evidence['published'])),
                f"Healthy peer stalled: {evidence['received']}/{evidence['published']}; {errors}")

    receiver = threading.Thread(target=consume, daemon=True)
    receiver.start()
    try:
        overflow_at = None
        previous_check = time.monotonic()
        for i in range(evidence['saturation_limit']):
            publish()
            if i % 50 != 49:
                continue
            if 'output buffer overflow' in server.logs():
                evidence['overflow_observed'] = True
                overflow_at = overflow_at or time.monotonic()
            status, channel = rest(server.port, path='/channels/backpressure', method='GET',
                                   params={'info': 'subscription_count'})
            if evidence['overflow_observed'] and status == 200 and channel.get('subscription_count') == 1:
                evidence['slow_consumer_evicted'] = True
                evidence['overflow_observation_to_eviction_check_seconds'] = time.monotonic() - overflow_at
                evidence['eviction_detection_interval_seconds'] = time.monotonic() - previous_check
                progress()
                break
            previous_check = time.monotonic()
        require(evidence['overflow_observed'], 'No actual overflow within bounded saturation train')
        require(evidence['slow_consumer_evicted'], 'Nonreading consumer survived actual output overflow')
        followup_start = time.monotonic()
        for i in range(evidence['followup_messages']):
            publish()
            time.sleep(.025)
            if i % 50 == 49:
                progress()
        evidence['followup_seconds'] = time.monotonic() - followup_start
        progress()
        require(before == server.workers(), 'Backpressure restarted a worker')
    finally:
        evidence['overflow_observed'] = 'output buffer overflow' in server.logs()
        stopped.set()
        receiver.join(timeout=3.1)
    require(not receiver.is_alive(), 'Healthy receiver did not stop')
    healthy.ping()
    return evidence


def test_worker_death_during_presence_write_recovers(server):
    sender, observer, foreign = server.ws(), server.ws(), server.ws(1)
    cd = json.dumps({'user_id': 'observer', 'user_info': None})
    require(observer.subscribe('presence-worker-death', cd)['event'] == SUCCESS, 'Worker-death setup failed')
    require(foreign.subscribe('presence-worker-death', cd)['event'] == SUCCESS, 'Foreign worker-death setup failed')
    before = server.workers()
    killed = json.dumps({'user_id': 'interrupted', 'user_info': {'kill': True}}, separators=(',', ':'))
    sender.send({'event': 'pusher:subscribe', 'data': {'channel': 'presence-worker-death', 'channel_data': killed,
                                                     'auth': sender.auth('presence-worker-death', killed)}})
    # The fixture kills only the task worker currently performing this disposable write.
    time.sleep(1.2)
    after = server.workers()
    require(before != after and len(before) == len(after), 'Injected task-worker death/replacement was not observed')
    observer.unsubscribe('presence-worker-death')  # A real mutation triggers abandoned-transaction recovery.
    require(observer.subscribe('presence-worker-death', cd)['event'] == SUCCESS, 'Post-crash presence mutation failed')
    users = rest(server.port, path='/channels/presence-worker-death/users', method='GET')[1]['users']
    require(users == [{'id': 'observer'}], 'Abandoned presence transaction left phantom membership')
    # Two fresh pending slots would overflow if the dead worker's lease remained.
    sender.send({'event': 'pusher:ping', 'data': {}})
    sender.send({'event': 'pusher:ping', 'data': {}})
    require(sender.recv()['event'] == 'pusher:pong' and sender.recv()['event'] == 'pusher:pong', 'Expired claimed lease was not reclaimed')
    require(sender.subscribe('after-worker-death')['event'] == SUCCESS, 'Crash leaked shared or socket capacity')
    foreign.ping()
    return {'task_worker_replaced': True, 'partial_transaction_recovered': True, 'pending_lease_reclaimed': True}


def test_presence_saved_signature_replay_is_rejected(server):
    channel = 'presence-revocation'
    cd = '{"user_id":"permitted","user_info":null}'
    first = server.ws()
    saved = first.auth(channel, cd)
    require(first.subscribe(channel, cd, saved)['event'] == SUCCESS, 'Initial presence authorization failed')
    first.close()
    reconnect = server.ws()
    controlled(reconnect.subscribe(channel, cd, saved))
    require(reconnect.subscribe(channel, cd)['event'] == SUCCESS, 'Fresh presence reconnect failed')
    docker('restart', '-t', '2', server.cid); server.wait()
    before = server.ws()
    saved_restart = before.auth(channel, cd)
    old_sid = before.sid
    require(before.subscribe(channel, cd, saved_restart)['event'] == SUCCESS, 'Pre-restart presence authorization failed')
    docker('restart', '-t', '2', server.cid); server.wait()
    after = server.ws()
    require(after.sid != old_sid, 'Restart reused presence authorization context')
    controlled(after.subscribe(channel, cd, saved_restart))
    # Equivalent JSON with changed exact bytes must not accept the original HMAC.
    changed_bytes = '{ "user_id":"permitted","user_info":null}'
    controlled(after.subscribe(channel, changed_bytes, after.auth(channel, cd)))
    require(after.subscribe(channel, cd)['event'] == SUCCESS, 'Fresh presence authorization failed')
    return {'presence_reconnect_replay_rejected': True, 'presence_restart_replay_rejected': True, 'exact_channel_data_bytes_bound': True}


def main():
    global VENDOR
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--only', default='')
    parser.add_argument('--vendor', type=Path, help='Existing installed vendor directory; mounted read-only')
    args = parser.parse_args()
    VENDOR = (args.vendor or args.source / 'vendor').resolve()
    require((VENDOR / 'autoload.php').is_file(), 'Pass --vendor pointing to installed public dependencies')
    args.output.mkdir(parents=True, exist_ok=True)
    prepared = args.output / 'source'
    # Only runtime source and public Composer metadata enter the disposable snapshot.
    prepared.mkdir(parents=True, exist_ok=True)
    shutil.copytree(args.source / 'src', prepared / 'src', dirs_exist_ok=True)
    for name in ('composer.json', 'composer.lock'):
        if (args.source / name).is_file():
            shutil.copyfile(args.source / name, prepared / name)
    source_hashes = {str(path.relative_to(prepared)): hashlib.sha256(path.read_bytes()).hexdigest()
                     for path in sorted((prepared / 'src').rglob('*.php'))}
    (args.output / 'source-sha256.json').write_text(json.dumps(source_hashes, indent=2) + '\n')
    (prepared / 'vendor').mkdir(exist_ok=True)
    results = {'source': str(args.source.resolve()), 'runtime_image': docker('image', 'inspect', 'socket-conveyor-runtime:latest', '--format', '{{.Id}}'), 'tests': []}
    tests = [test_malformed_worker_survival, test_channel_and_presence_boundaries, test_two_application_isolation,
             test_reconnect_and_restart_replay, test_client_limits_and_rates, test_rest_validation_is_atomic,
             test_membership_quota_recovery, test_atomic_low_budgets_and_recovery,
             test_presence_duplicate_notifications_and_capacity, test_oversized_fragmented_message_is_contained,
             test_custom_presence_partial_failure_rolls_back, test_slow_consumer_does_not_block_healthy_peer,
             test_worker_death_during_presence_write_recovers, test_presence_saved_signature_replay_is_rejected]
    for test in tests:
        if args.only and not any(value in test.__name__ for value in args.only.split(',')):
            continue
        server = None
        start = time.monotonic()
        result = {'name': test.__name__, 'passed': False}
        try:
            limits = {'memberships_per_socket': 3, 'memberships_per_app': 4, 'memberships_total': 6} if test.__name__ == 'test_atomic_low_budgets_and_recovery' else None
            if test.__name__ == 'test_presence_duplicate_notifications_and_capacity':
                limits = {'presence_per_channel': 2}
            fault = test.__name__ in ('test_custom_presence_partial_failure_rolls_back', 'test_worker_death_during_presence_write_recovers')
            if fault:
                limits = {'memberships_per_socket': 1, 'memberships_per_app': 2, 'memberships_total': 3}
            if test.__name__ == 'test_worker_death_during_presence_write_recovers':
                limits.update({'pending_per_socket': 2, 'pending_total': 4, 'task_timeout_seconds': 1})
            server = Server(prepared.resolve(), args.output, test.__name__, limits, fault)
            result['container_id'] = server.cid
            result['configured_limits'] = limits or 'defaults'
            result['evidence'] = test(server)
            result['passed'] = True
        except Exception as error:
            result['failure'] = f'{type(error).__name__}: {error}'
            result['traceback'] = traceback.format_exc()
            if server is not None and hasattr(server, 'slow_evidence'):
                result['evidence'] = server.slow_evidence
        finally:
            if server is not None:
                server.close()
        result['seconds'] = round(time.monotonic() - start, 3)
        results['tests'].append(result)
        (args.output / 'results.json').write_text(json.dumps(results, indent=2) + '\n')
        print(json.dumps({k: v for k, v in result.items() if k != 'traceback'}), flush=True)
    return 0 if all(x['passed'] for x in results['tests']) else 1


if __name__ == '__main__':
    raise SystemExit(main())

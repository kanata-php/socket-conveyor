# Pusher admission and application isolation

These controls describe the unreleased changes on `master`; they are not included in the published Docker Hub `lotharthesavior/socket-conveyor:2.6.0` image. Publishing a new image release awaits separate authorization.

Pusher requires 64-bit PHP and writable private temporary storage for process locks (a tmpfs `/tmp` works with a read-only container root). Its public connection ID is two independent `random_int(1, PHP_INT_MAX)` values separated by a dot: approximately 126 bits of entropy. IDs and app bindings are shared, collision checked, and renewed after reconnect or restart. Handshakes fail closed when entropy, storage or connection capacity is unavailable. Channel signatures must use the exact new socket ID and exact original presence JSON bytes.

Every production membership includes app key, connection generation, fd and external channel. REST uses the authenticated route app; WebSocket messages use the handshake binding. Client-provided app fields have no authority. Equal channel/user names in different apps remain separate. Only exclusion IDs currently owned by the publishing app are honored. Internal fixed-size hashed keys and presence scope tokens never replace external channel names on the wire or in existing hooks.

Public router methods `deliver(event, channel, data, excludeSocketId = null, appKey = null)`, `subscribersOf(channel, appKey = null)`, `allSubscriptions(appKey = null)` and `roster(channel, appKey = null)` accept optional app context. Omission resolves only a sole enabled app; multi-app omission fails closed. Corresponding repository methods append optional app context; standalone repositories without a socket registry retain an isolated legacy null namespace. Production always supplies explicit context and the socket registry. Existing native Conveyor interfaces and user associations remain unchanged.

Injected presence persistence keeps the same interface and receives a 64-byte scope token instead of the Pusher external channel. It must support opaque channel names, raw presence JSON up to 2,048 bytes, exact readbacks and synchronous nonblocking add/remove/getMembers calls: do not yield, push, call hooks or access network services inside these calls. Failed writes are rolled back; failures and unexpected exceptions produce bounded errors and sampled, value-free class/reason counters (`PusherDiagnostics::counts()`). A permanently broken custom adapter remains an operator fault; repair it before resuming presence subscriptions. Pusher never writes native integer user associations.

## Input policy

Names are validated before storage/authentication. Channels use nonempty ASCII `[A-Za-z0-9_=@,.;-]+`, maximum 164 bytes including prefixes. Events use valid UTF-8 with no control characters, maximum 200 bytes; REST cannot publish reserved `pusher:`/`pusher_internal:` events. Client data accepts strings or JSON objects/arrays, maximum 10,000 encoded bytes; explicit null, numbers and booleans are rejected. Absent client data retains `{}`. Subscribe/unsubscribe data accepts an object or a string containing an object. JSON depth is limited to 64. Malformed messages receive bounded `pusher:error`; admission overload closes only the offending connection before task serialization.

Presence channel_data must be an original JSON-object string up to 2,048 bytes; user_id is a nonempty valid control-free UTF-8 string up to 128 bytes or an integer. Duplicate user IDs collapse even with null user_info; member-added/removed events correspond to first/last connections. Repeated subscriptions are idempotent and preserve the first identity until explicit unsubscribe.

REST retains string data and at most ten events per nonempty batch; each event has one to 100 valid channels, deduplicated only after validating every entry. The entire batch is validated and rate-admitted before dispatch. Invalid input returns 400, oversized bodies 413 and rate exhaustion 429. Existing hook argument ordering and double-encoded outbound data are retained.

## Configurable budgets

Supply an associative `pusher_limits` array through `ConveyorOptions::fromArray()`, alongside the existing `Constants::APPS` and `Constants::WEBSOCKET_SUBPROTOCOL` options. Each setting must be an integer between one and its default below; unknown, string, boolean, zero, negative and contradictory limits are rejected at startup. Defaults are safety budgets, not benchmarked capacity. They apply only to Pusher; native Conveyor defaults are unchanged.

| Keys | Defaults |
| --- | --- |
| memberships_per_socket / memberships_per_app / memberships_total | 100 / 10,000 / 40,000 |
| connections_per_app / connections_total | 1,000 / 5,000 |
| presence_per_channel (connections, including duplicate users) | 100 |
| client_rate_socket / client_burst_socket | 10 / 10 |
| event_rate_app / event_burst_app (client events and REST share) | 1,000 / 1,000 |
| frame_rate_socket / frame_burst_socket | 100 / 100 |
| frame_rate_app / frame_burst_app | 5,000 / 5,000 |
| pending_per_socket / pending_total (queued plus executing) | 8 / 1,024 |
| frame_bytes / http_body_bytes | 65,536 / 1,048,576 |
| send_buffer_bytes / send_timeout_seconds | 1,048,576 / 1 |
| idle_seconds / heartbeat_seconds | 120 / 30 |
| task_timeout_seconds (queued/dead-worker lease recovery) | 30 |

For example, lower `memberships_per_socket` to 2, `memberships_per_app` to 4 and `memberships_total` to 8 for isolated quota testing. Lower nested socket limits together with their app/server limits. `heartbeat_seconds` must not exceed `idle_seconds`. Increasing beyond defaults requires an explicit future table-sizing change. At most 1,000 distinct app configurations are supported, with unique nonempty string IDs/keys/secrets, keys at most 64 bytes, and strict boolean flags. No application credentials are generated or changed by these controls.

Shared process locks serialize existence checks, insertion/removal, presence changes and quota checks; committed row counts are the authoritative accounting, avoiding independent counter drift. A per-membership journal hides incomplete presence changes and repairs abandoned transactions at the next locked operation. Kernel file locks are released when a worker dies. Pending task leases are reserved before enqueue, claimed once by a task worker, and released in finally or on enqueue failure; expired unclaimed/dead-worker leases are reconciled under a shared lock before admission. Executing live workers retain their leases, preventing timeout oversubscription. Old generation envelopes and deferred handshake callbacks cannot act on replacement connections.

Per-app 10,000 versus server 40,000 memberships leaves headroom for other apps; it does not reserve capacity for arbitrarily many apps. Physical table exhaustion can happen below configured budgets and produces controlled failure. Rate keys are bounded by live configured apps/connections and socket keys are removed on close. Restart recreates all volatile state together; worker reload retains inherited shared tables. Finite output buffers/timeouts evict a slow consumer. Transport byte/heartbeat/send settings enforce these budgets even when ordinary server options are also provided.

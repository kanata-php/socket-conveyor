# Native Auth Token Example

This example shows Conveyor's native token flow. It is separate from the
Pusher/Reverb Laravel Echo flow.

Start the protected native Conveyor server:

```bash
php examples/native-auth/run-conveyor.php
```

In another terminal, run the client:

```bash
php examples/native-auth/client.php
```

The client:

1. Calls `POST /conveyor/auth?token=local-server-token`.
2. Receives a channel-scoped temporary token.
3. Connects to `ws://127.0.0.1:8989/?token=<temporary-token>`.
4. Sends `channel-connect` with the same temporary token.
5. Receives a protected broadcast on `orders.1`.

Set `CONVEYOR_SERVER_TOKEN`, `CONVEYOR_HOST`, or `CONVEYOR_PORT` to override the
local defaults.

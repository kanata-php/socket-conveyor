# Real Pusher Client Smoke

This runs Socket Conveyor in Pusher-compatible mode and opens a browser client
using the real `pusher-js` client library.

Terminal 1:

> Note: this must run from the root of socket conveyor.

```bash
php examples/pusher-real/run-conveyor.php
```

Terminal 2:

```bash
php -S 127.0.0.1:8991 examples/pusher-real/router.php
```

Open:

```text
http://127.0.0.1:8991
```

For the Laravel Echo client smoke, open:

```text
http://127.0.0.1:8991/echo.html
```

Expected browser checks:

- connection reaches `connected` and shows a `socket_id`;
- public/private/presence subscriptions log `subscribed`;
- the Public, Private, and Presence buttons produce `DemoEvent` logs;
- `Public toOthers()` triggers the REST API with the current `socket_id`, so
  this browser does not receive its own broadcast;
- open the page in a second tab and use the whisper button to see the client
  event in the other tab.

The default app credentials are intentionally local-only:

```text
PUSHER_APP_ID=local-app
PUSHER_APP_KEY=local-key
PUSHER_APP_SECRET=local-secret
CONVEYOR_HOST=127.0.0.1
CONVEYOR_PORT=8990
```

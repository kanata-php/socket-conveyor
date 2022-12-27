
<p align="center">
<img src="./imgs/logo.png"/>
</p>

# Socket Conveyor

![Tests](https://github.com/kanata-php/socket-conveyor/workflows/Tests/badge.svg)
[![Code Intelligence Status](https://scrutinizer-ci.com/g/kanata-php/socket-conveyor/badges/code-intelligence.svg?b=master)](https://scrutinizer-ci.com/code-intelligence)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/kanata-php/socket-conveyor/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/kanata-php/socket-conveyor/?branch=master)

> A WebSocket/Socket message Router for PHP

<a href="https://www.buymeacoffee.com/savioresende" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me A Coffee" style="height: 60px !important;width: 217px !important;" ></a>

## Prerequisites

- PHP >= 8.0

## Installation

```shell
composer require kanata-php/socket-conveyor
```

## Description

This package enables you to work with socket messages using routing strategy. It comes with some solutions such as channel or action listeners, but you can implement your own by extending the `AbstractAction`.

This package is built with [OpenSwoole](https://openswoole.com/) in mind, but it works with any websocket implementation. Please let me know if you find issues! You can find out more how to use WebSockets with OpenSwoole [here](https://www.youtube.com/watch?v=Vgw5Ibqc15k).

> This package has a Client NPM package to facilitate the work, go check it here: https://www.npmjs.com/package/socket-conveyor-client.

## How it works

Here is how it works:

<p align="center">
<img src="./imgs/conveyor-process.png" title="How it works"/>
</p>


## Usage

> **Prerequisites**
> - The message must contain specific keys depending on the action being triggered.
> - If a simple non-JSON string is sent, it will be considered to be the BaseAction, which is the same as a ping/pong, responding to the same message that was sent.
> - If a JSON string is sent, and the action requires a "data" key, the "data" key can be of any format. Notice that the message string must always be a valid JSON string at the end.

Following we have 4 examples:

**Case 1**: The simple case, where messages happen in real-time fashion between client and server but won't broadcast to other clients.

**Case 2**: The channel case, where messages get broadcast to all other clients within the same channel.

**Case 3**: The listener case, where clients can filter which messages (by action) they will receive.

**Case 4**: Associate an application user id with a connection (fd).

### Case 1: Simple Use

<p align="center">
<img style="border: 1px solid #000" src="./imgs/conveyor-case-1.png" title="Simple case"/>
</p>

At this example the user will receive back a real-time messages from the server after sending a message.

At this library, there is the presumption that the socket message has a *JSON* format, if not, the `Conveyor\Actions\BaseAction` will be the handler and the text will be added as the "data" parameter of that action. That said, the following standard is expected to be followed by the messages in general, so they can match specific *Actions*. The minimum format is this:

```json
{
    "action": "base-action",
    "data": "here goes other fields necessary for the Actions processing..."
}
```

First, write some action handlers:

Second, at your Open Swoole Web Socket server, register `SocketMessageRouter` with your actions at your `OnMessage` event handler:

```php
require __DIR__.'/vendor/autoload.php';

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Conveyor\SocketHandlers\SocketMessageRouter;

$websocket = new Server('0.0.0.0', 8001);
$websocket->on('message', function (Server $server, Frame $frame) {
    $socketRouter = new SocketMessageRouter;
    $socketRouter($frame->data, $frame->fd, $server);
});

$websocket->start();
```

Thats it! Now, to communicate in real-time with this service, on your HTML you can do something like this:

```html
<div>
    <div><button onclick="sendMessage(JSON.stringify({
            'action': 'base-action',
            'data': 'first',
        }))">Base Action</button></div>
    <div><button onclick="sendMessage('second')">Simple Text</button></div>
    <div id="output"></div>
</div>
<script type="text/javascript">
    var websocket = new WebSocket('ws://127.0.0.1:8001');
    websocket.onmessage = function (evt) {
        document.getElementById('output').innerHTML = JSON.parse(evt.data).data;
    };
    function sendMessage(message) {
        websocket.send(message);
    }
</script>
```

> Notice that these 2 buttons result in the same action.

How it looks like:

<p align="center">
<img style="border: 1px solid #000" src="./imgs/example-server.gif" title="Example for base action"/>
</p>

### Case 2: Using Channels

<p align="center">
<img style="border: 1px solid #000" src="./imgs/conveyor-case-2.png" title="Using channels"/>
</p>

At this case it is possible for clients sharing a channel to communicate to each other by broadcasting messages and data through this channel.

The procedure here requires one extra step during the instantiation: the connection action. The connection action will link in a persistent manner the connection FD to a channel.

> If you are using the client lib of this package you won't need to manually open a connection by yourself as the client already does that out of the box. You can find it here: https://www.npmjs.com/package/socket-conveyor-client 

```json
{
    "action": "channel-connect",
    "channel": "channel-name"
}
```

The way to use it with vanilla JS is as follows:

```html
<script>
    websocket.send(JSON.stringify({
        'action': 'channel-connect',
        'channel': 'my-channel',
    }));
</script>
```

After connecting to a channel, all messages sent by that client will be within that channel. You can disconnect from a channel by sending this message:

```html
<script>
    websocket.send(JSON.stringify({
        'action': 'channel-disconnect',
    }));
</script>
```

The Socket Router instantiation also suffers a small change:

```php
require __DIR__.'/vendor/autoload.php';

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Conveyor\SocketHandlers\SocketMessageRouter;
use Conveyor\SocketHandlers\SocketChannelPersistenceTable;

$persistenceService = new SocketChannelPersistenceTable;

$websocket = new Server('0.0.0.0', 8001);
$websocket->on('message', function (Server $server, Frame $frame) use ($persistenceService) {
    $socketRouter = new SocketMessageRouter($persistenceService);
    $socketRouter($frame->data, $frame->fd, $server);
});

$websocket->start();
```

With these changes to the server, you can have different implementations on the client-side. Each implementation, in a different context, connects to a different channel. As an example, we have the following HTML example. When connected, the given connection will participate on a given channel.

> **Important**: to broadcast messages to all clients in the same channel, you'll need to use the `broadcast-action` action like. You can take a look on the file `Conveyor\Actions\BroadcastAction` to see how broadcasting actions works like. If you want to know how to broadcast outside channel borders, take a look at the "Fanout" actions.

```html
<div>
    <form id="message-form" onsubmit="return sendMessage()">
        <div>
            <input id="message-box" autocomplete="off" type="text" placeholder="The message goes here..."/>
        </div>
        <input type="submit" value="Submit"/>
    </form>

    <div>
        <ul id="output"></ul>
    </div>
</div>
<script type="text/javascript">
    var channel = 'actionschannel';
    var websocket = new WebSocket('ws://127.0.0.1:8001');
    websocket.onopen = function(e) {
        // connect to a channel
        websocket.send(JSON.stringify({
            'action': 'channel-connect',
            'channel': channel,
        }));
    };
    websocket.onmessage = function (evt) {
        document.getElementById('output').innerHTML = evt.data;
    };
    function sendMessage() {
        websocket.send(JSON.stringify({
            'action': 'broadcast-action',
            'data': document.getElementById('message-box').value,
        }));
        return false;
    }
</script>
```

That's all, with this, you would have the following:

<p align="center">
<img style="border: 1px solid #000" src="./imgs/example-server-channels.gif" title="Example Server with Channels"/>
</p>


### Case 3: Listening to Actions

<p align="center">
<img style="border: 1px solid #000" src="./imgs/conveyor-case-3.png" title="Listening to actions"/>
</p>

At this example clients can filter messages that they receive by adding listeners to the ones they want. If there are no listeners registered, they will receive all broadcast or fanout actions.

At the `SocketMessageRouter` preparation, we have one extra action being called: `Conveyor\Actions\AddListenerAction`. Also, listeners require another persistence instance: `Conveyor\SocketHandlers\SocketListenerPersistenceTable`.

```php
require __DIR__.'/vendor/autoload.php';

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Conveyor\Actions\AddListenerAction;
use Conveyor\SocketHandlers\SocketMessageRouter;
use Conveyor\SocketHandlers\SocketListenerPersistenceTable;

$persistenceService = new SocketListenerPersistenceTable;
$websocket = new Server('0.0.0.0', 8001);
$websocket->on('message', function (Server $server, Frame $frame) use ($persistenceService) {
    echo 'Received message (' . $frame->fd . '): ' . $frame->data . PHP_EOL;
    $socketRouter = new SocketMessageRouter($persistenceService);
    $socketRouter($frame->data, $frame->fd, $server);
});

$websocket->start();
```

The client in Javasript then starts listening to a specific action by sending a new message at the connection opening:

> At this example we assume that we have another broadcast action implemented "secondary-broadcast-action". That will be used to differentiate between the listened and the not listened broadcasted actions.

```html
<div>
    <div><button onclick="sendMessage('broadcast-action', 'first')">First Action</button></div>
    <div><button onclick="sendMessage('secondary-broadcast-action', 'second')">Second Action</button></div>
    <div id="output"></div>
</div>
<script type="text/javascript">
    var channel = 'actionschannel';
    var websocket = new WebSocket('ws://127.0.0.1:8001');
    websocket.onopen = function(e) {
        websocket.send(JSON.stringify({
            'action': 'channel-connect',
            'channel': channel,
        }));
        
        // This starts the process of listening to actions from the current client.
        websocket.send(JSON.stringify({
            'action': 'add-listener',
            'listen': 'secondary-broadcast-action',
        }));
    };
    websocket.onmessage = function (evt) {
        document.getElementById('output').innerHTML = JSON.parse(evt.data).data;
    };
    function sendMessage(action, message) {
        websocket.send(JSON.stringify({
            'action': action,
            'data': message,
        }));
    }
</script>
```

Once those changes are in place, you'll be able to see this (notice that we are in the same channel, but both are receiving only to the actions they are subscribed for):

<p align="center">
<img style="border: 1px solid #000" src="./imgs/example-server-listeners.gif" title="Example Server with Listeners"/>
</p>

### Case 4: Associate User with Connection

This functionality is for applications that need to have associations between connections (fd) and users. This is useful when you need to execute actions that need to know the user and decide upon that. One good example is a server that serves at the same time multiple users, but won't answer users the same way depending on the procedure executed. That way, you can have actions that will process some data and broadcast to connections only what each connection needs to receive for that procedure.

For this functionality, you only need one extra action to be dispatched:

```javascript
websocket.send(JSON.stringify({
    'action': 'assoc-user-to-fd-action',
    'userId': 1,
}));
```

This code will associate the user "1" with the current connection.

> **Advice:** It is recommended the usage of some token or secret to identify them before the websocket server accepting the association.

### Case 5: Using Middlewares

The usage of middlewares might help to secure your websocket server, making sure specific validations and conditions are met in order to proceed. At Socket Conveyor, middlewares are attached to actions at the socket router's instance. The way to do that is as follows:

```php
require __DIR__.'/vendor/autoload.php';

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Conveyor\SocketHandlers\SocketMessageRouter;
use Conveyor\SocketHandlers\SocketChannelPersistenceTable;
use Conveyor\ActionMiddlewares\Interfaces\MiddlewareInterface;

class Middleware1 extends MiddlewareInterface
{
    public function __invoke($payload)
    {
        // do somethign here
        return $payload;
    }
}

$persistenceService = new SocketChannelPersistenceTable;

$websocket = new Server('0.0.0.0', 8001);
$websocket->on('message', function (Server $server, Frame $frame) use ($persistenceService) {
    echo 'Received message (' . $frame->fd . '): ' . $frame->data . PHP_EOL;
    
    // adding with the constructor
    $socketRouter = new SocketMessageRouter($persistenceService, [
        ActionWithoutMiddleware::class,
        [
            ActionWithMiddleware::class,
            new Middleware1, // middleware 1
            fn($payload) => $payload, // middleware 2
            // ...
        ],
    ]);
    
    // is also possible adding after the instance is set:
    // $action = new ActionWithMiddleware2;
    // $socketRouter->add($action);
    // $socketRouter->middleware($action->getName(), new Middleware1);
    
    $socketRouter($frame->data, $frame->fd, $server);
});

$websocket->start();
```

Middlewares at Socket Conveyor are callables. Any callable is accepted. For is reason you can add functions as middlewares. Even though the system is pretty flexible on that side, we strongly suggest you to implement the interface `Conveyor\ActionMiddlewares\Interfaces\MiddlewareInterface`.

### Case 6: Fanout Action

This is a global broadcast, that goes outside the borders of the channels.

> **Important:** when a client is listening to actions other than the one you send, that client won't receive it. It happens because listeners are "filters".

```php
require __DIR__.'/vendor/autoload.php';

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Conveyor\SocketHandlers\SocketMessageRouter;
use Conveyor\SocketHandlers\SocketChannelPersistenceTable;

$websocket = new Server('0.0.0.0', 8001);
$websocket->on('message', function (Server $server, Frame $frame) {
    $socketRouter = new SocketMessageRouter();
    $socketRouter($frame->data, $frame->fd, $server);
});

$websocket->start();
```

```html
<div>
    <div><button onclick="sendMessage('Hello')">Say Hello</button></div>
    <div id="output"></div>
</div>
<script type="text/javascript">
    var websocket = new WebSocket('ws://127.0.0.1:8001');
    websocket.onmessage = function (evt) {
        document.getElementById('output').innerHTML = JSON.parse(evt.data).data;
    };
    function sendMessage(message) {
        websocket.send(JSON.stringify({
            'action': 'fanout-action',
            'data': message
        }));
    }
</script>
```

Messages sent with this example will be broadcasted to any client regardless of channels.

## Available Actions

This package comes with some out-of-the-box Actions, but you can (and probably will need) build your own for your own needs by extending the existent ones or creating new.

### Add Listener Action

> `Conveyor\Actions\AddListenerAction`

Action responsible for adding listeners to a connection. This way messages will be filtered.

Structure:

```json
{
    "action": "add-listener",
    "listen": "action-name"
}
```

### Associate User to Fd

> `Conveyor\Actions\AssocUserToFdAction`

Action responsible for associating users to connections.

Structure:

```json
{
    "action": "assoc-user-to-fd-action",
    "userId": 1
}
```

### Base (default)

> `Conveyor\Actions\BaseAction`

This is the base action. Works like a ping pong, returning the message to the client who sent it.

Structure:

```json
{
    "action": "base-action",
    "data": "message"
}
```

> If the message sent to the server is plain text instead of json, this actino will be the one selected by Socket Conveyor.

### Broadcast

> `Conveyor\Actions\BroadcastAction`

This is for messages to be broadcasted on the context of the connection that dispatches it.

Structure:

```json
{
    "action": "broadcast-action",
    "data": "message"
}
```

### Channel Connect

> `Conveyor\Actions\ChannelConnectAction`

Action used to connect to a channel.

Structure:

```json
{
    "action": "channel-connect",
    "channel": "channel-name"
}
```

### Channel Disconnect

> `Conveyor\Actions\ChannelDisconnectAction`

Action used to disconnect from a channel.

Structure

```json
{
    "action": "channel-disconnect"
}
```

### Fanout

> `Conveyor\Actions\FanoutAction`

Action used to broadcast without context borders (to every client in the server).

```json
{
    "action": "fanout-action",
    "data": "message"
}
```

## Commands

### Start Server

> This is a sample of a managed WebSocket Server.

Start a managed WebSocket Server. If you don't overwrite the default options, it comes with a sample Server and Client that you can use to build something else or extend and customize. After the installation composer will copy the command to the binary's directory inside vendor (`./vendor/bin/start-ws-server`) of your project's directory.

Once you run the following comment you'll be able to visit `localhost:8080` and see the manager to start or stop the server.

```shell
php ./vendor/bin/start-ws-server
```

<p align="center">
<img src="./imgs/managed-ws-server.png" width="500"/>
</p>

## Tests

Run Command:

```shell
./vendor/bin/phpunit
```

## Author

👤 **Savio Resende**

* Website: https://savioresende.com
* GitHub: [@lotharthesavior](https://github.com/lotharthesavior)

## 📝 License

Copyright © 2022 [Savio Resende](https://github.com/lotharthesavior).

This project is [MIT](https://github.com/kefranabg/readme-md-generator/blob/master/LICENSE) licensed.

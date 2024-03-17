<?php

namespace Conveyor\Traits;

use Conveyor\Constants;
use Conveyor\Events\MessageReceivedEvent;
use Conveyor\Events\ServerStartedEvent;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

trait HasHandlers
{
    protected function onServerStart(Server $server): void
    {
        $this->eventDispatcher->dispatch(
            event: new ServerStartedEvent(
                server: $server,
                // clientPool: $this->redisPool,
            ),
            eventName: Constants::EVENT_SERVER_STARTED,
        );
    }

    protected function onMessage(Server $server, Frame $frame): void
    {
        // if ($this->conveyorOptions[Constants::USE_REDIS]) {
        //     $this->queue->set(uniqid($frame->fd), ['data' => json_encode([
        //         'data' => $frame->data,
        //         'fd' => $frame->fd,
        //     ])]);
        //     return;
        // }

        $this->eventDispatcher->dispatch(
            event: new MessageReceivedEvent(
                server: $this->server,
                data: json_encode([
                    'data' => $frame->data,
                    'fd' => $frame->fd,
                ]),
                // redisPool: $this->redisPool,
            ),
            eventName: Constants::EVENT_MESSAGE_RECEIVED,
        );
    }

    protected function onHandshake(Request $request, Response $response): bool
    {
        $secWebSocketKey = $request->header['sec-websocket-key'];
        $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

        // Websocket handshake connection algorithm verification
        if (
            0 === preg_match($patten, $secWebSocketKey)
            || 16 !== strlen(base64_decode($secWebSocketKey))
        ) {
            $response->end();
            return false;
        }

        // Check the number of connections
        // if (
        //     $this->conveyorOptions[Constants::CONNECTIONS_LIMIT] !== -1
        //     && $this->connectionsCount->get()
        //         >= $this->conveyorOptions[Constants::CONNECTIONS_LIMIT]
        // ) {
        //     $response->status(503);
        //     $response->end();
        //     return false;
        // }

        $key = base64_encode(sha1(
            $request->header['sec-websocket-key']
            . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true
        ));

        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $key,
            'Sec-WebSocket-Version' => '13',
        ];

        // Response must not include 'Sec-WebSocket-Protocol' header if not present in request: websocket
        if (isset($request->header['sec-websocket-protocol'])) {
            $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
        }

        foreach ($headers as $key => $val) {
            $response->header($key, $val);
        }

        $fd = $request->fd;
        // @phpstan-ignore-next-line
        $this->server->defer(function () use ($fd) {
            $action = Constants::ACTION_CONNECTION_INFO;
            $data = json_encode(['fd' => $fd, 'event' => $action]);
            $hash = md5($data . time());
            $this->server->push($fd, json_encode([
                'action' => $action,
                'data' => $data,
                'id' => $hash,
            ]));
        });

        // $this->connectionsCount->add(1);

        $response->status(101);
        $response->end();
        return true;
    }
}

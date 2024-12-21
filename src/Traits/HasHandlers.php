<?php

namespace Conveyor\Traits;

use Conveyor\Constants;
use Conveyor\Events\ConnectionCloseEvent;
use Conveyor\Events\MessageReceivedEvent;
use Conveyor\Events\RequestReceivedEvent;
use Conveyor\Events\ServerStartedEvent;
use Conveyor\Events\TaskFinishedEvent;
use Hook\Filter;
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
            ),
            eventName: Constants::EVENT_SERVER_STARTED,
        );
    }

    protected function onMessage(Server $server, Frame $frame): void
    {
        $server->task(json_encode([
            'fd' => $frame->fd,
            'data' => $frame->data,
        ]));
    }

    protected function onRequest(Request $request, Response $response): void
    {
        $this->eventDispatcher->dispatch(
            event: new RequestReceivedEvent(
                request: $request,
                response: $response,
            ),
            eventName: Constants::EVENT_REQUEST_RECEIVED,
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

        if (!$this->validateAuth($request)) {
            $response->end();
            return false;
        }

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

            $data = [
                'action' => $action,
                'data' => json_encode(['fd' => $fd, 'event' => $action]),
            ];

            // @phpstan-ignore-next-line
            if ($this->conveyorOptions->{Constants::USE_ACKNOWLEDGMENT}) {
                $data['id'] = md5(json_encode($data) . time());
            }

            $this->server->push($fd, json_encode($data));
        });

        $response->status(101);
        $response->end();
        return true;
    }

    private function validateAuth(Request $request): bool
    {
        // @phpstan-ignore-next-line
        if (null === $this->conveyorOptions->{Constants::WEBSOCKET_SERVER_TOKEN}) {
            return true;
        }

        if (!isset($request->get['token'])) {
            return false;
        }

        $token = $request->get['token'];

        /**
         * Description: This is a filter for websocket handshake auth callback. If callable
         *              returned, that will be the one to be used.
         * Name: websocket_handshake_auth_callback
         * Returns: callback|null
         */
        $handshakeAuthMethod = Filter::applyFilters('websocket_handshake_auth_callback', null);

        if (is_callable($handshakeAuthMethod)) {
            return $handshakeAuthMethod($token);
        }

        return $token === $this->conveyorOptions->{Constants::WEBSOCKET_SERVER_TOKEN};
    }

    protected function onClose(Server $server, int $fd): void
    {
        $this->eventDispatcher->dispatch(
            event: new ConnectionCloseEvent(
                server: $server,
                fd: $fd,
            ),
            eventName: Constants::EVENT_SERVER_CLOSE,
        );
    }

    protected function onTask(Server $server, int $taskId, int $reactorId, string $data): void
    {
        $this->eventDispatcher->dispatch(
            event: new MessageReceivedEvent(
                server: $server,
                data: $data,
                taskId: $taskId,
                reactorId: $reactorId,
            ),
            eventName: Constants::EVENT_MESSAGE_RECEIVED,
        );

        $server->finish($data);
    }

    protected function onFinish(Server $server, int $taskId, mixed $data): void
    {
        $this->eventDispatcher->dispatch(
            event: new TaskFinishedEvent(
                server: $server,
                data: $data,
                taskId: $taskId,
            ),
            eventName: Constants::EVENT_TASK_FINISHED,
        );
    }
}

<?php

namespace Conveyor\SubProtocols\Conveyor;

use Conveyor\Config\ConveyorOptions;
use Conveyor\Constants;
use Conveyor\Events\AfterMessageHandledEvent;
use Conveyor\Events\BeforeMessageHandledEvent;
use Conveyor\Events\ConnectionCloseEvent;
use Conveyor\Events\MessageReceivedEvent;
use Conveyor\Events\RequestReceivedEvent;
use Conveyor\Helpers\Arr;
use Conveyor\Helpers\Http;
use Conveyor\SubProtocols\Conveyor\Actions\BroadcastAction;
use Conveyor\SubProtocols\Conveyor\Broadcast as ConveyorBroadcast;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\AuthTokenPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\ChannelPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Traits\HasAcknowledgement;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Hook\Filter;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ConveyorWorker
{
    use HasAcknowledgement;

    /**
     * @param Server $server
     * @param ConveyorOptions $conveyorOptions
     * @param EventDispatcher|null $eventDispatcher
     * @param array<array-key, GenericPersistenceInterface> $persistence
     * @param Client|null $httpClient
     */
    public function __construct(
        protected Server $server,
        protected ConveyorOptions $conveyorOptions,
        protected ?EventDispatcher $eventDispatcher = null,
        protected array $persistence = [],
        protected ?Client $httpClient = null,
    ) {
        $this->addListeners();
        $this->addAcknowledgementHooks();
    }

    private function addListeners(): void
    {
        $this->eventDispatcher->addListener(
            eventName: Constants::EVENT_MESSAGE_RECEIVED,
            listener: function (MessageReceivedEvent $event) {
                if (empty($event->data)) {
                    return;
                }
                $this->processMessage($event->data);
            }
        );

        $this->eventDispatcher->addListener(
            eventName: Constants::EVENT_REQUEST_RECEIVED,
            listener: fn(RequestReceivedEvent $event)
                => $this->processRequest($event->request, $event->response)
        );

        $this->eventDispatcher->addListener(
            eventName: Constants::EVENT_SERVER_CLOSE,
            listener: fn(ConnectionCloseEvent $event) =>
                $this->closeConnection($event->fd)
        );
    }

    private function closeConnection(int $fd): void
    {
        if (isset($this->persistence[Constants::CHANNELS])) {
            $this->persistence[Constants::CHANNELS]->disconnect($fd); // @phpstan-ignore-line
        }
    }

    private function processMessage(string $data): void
    {
        $parsedFrame = json_decode($data, true);
        $data = $parsedFrame['data'];
        $fd = $parsedFrame['fd'];

        $this->executeConveyor($fd, $data);
    }

    private function processRequest(Request $request, Response $response): void
    {
        if (!isset($request->get['token']) || !$this->checkToken($request->get['token'])) {
            Http::json(
                response: $response,
                content: [ 'error' => 'Unauthorized!' ],
                status: 401,
            );
            return;
        }

        // HTTP broadcast
        if (
            str_contains($request->server['request_uri'], 'conveyor/message')
            && strtoupper($request->server['request_method']) === 'POST'
        ) {
            $this->broadcastToChannel($request, $response);
            return;
        }

        // Temp token
        if (
            str_contains($request->server['request_uri'], 'conveyor/auth')
            && strtoupper($request->server['request_method']) === 'POST'
        ) {
            $this->tempToken($request, $response);
            return;
        }

        $httpCallback = Filter::applyFilters(
            Constants::FILTER_REQUEST_HANDLER,
            fn(Request $request, Response $response) => Http::json(
                response: $response,
                content: [],
                status: 404,
            ),
        );
        $httpCallback($request, $response);
        var_dump('test');
    }

    private function checkToken(string $token): bool
    {
        // @phpstan-ignore-next-line
        if ($token === $this->conveyorOptions->{Constants::WEBSOCKET_SERVER_TOKEN}) {
            return true;
        }

        $record = $this->persistence[Constants::AUTH_TOKENS]->byToken($token);

        return $record === false ? false : true;
    }

    /**
     * This endpoint expects a POST request with a body with the following structure:
     * {
     *     "channel": "channel-name",
     *     "message": "message"
     * }
     */
    private function broadcastToChannel(Request $request, Response $response): void
    {
        if (!isset($this->persistence[Constants::CHANNELS])) {
            Http::json(
                response: $response,
                content: [ 'error' => 'Channels not enabled!' ],
                status: 400,
            );
            return;
        }

        $content = json_decode($request->getContent(), true);

        $channel = Arr::get($content, 'channel');

        $connections = $this->persistence[Constants::CHANNELS]->getAllConnections();
        if (null === $channel || !in_array($channel, $connections)) {
            Http::json(
                response: $response,
                content: [ 'error' => 'Channel not found!' ],
                status: 404,
            );
            return;
        }

        $message = Arr::get($content, 'message');
        if (null === $message || empty($message)) {
            Http::json(
                response: $response,
                content: [ 'error' => 'Invalid message!' ],
                status: 400,
            );
            return;
        }

        /**
         * Description: This is a filter for websocket auth callback. If callable
         *              returned, that will be the one to be used.
         * Name: websocket_auth_callback
         * Params:
         *   - $content: array Request Body
         *   - $header: array Request Header
         */
        $authMethod = Filter::applyFilters(
            'websocket_auth_callback',
            $content,
            $request->header,
        );

        if (!is_callable($authMethod)) {
            $this->forceChannelMessage($channel, $message);
            Http::json(
                response: $response,
                content: [ 'success' => 'Message sent successfully!' ],
            );
            return;
        }

        if (!$authMethod($channel)) {
            Http::json(
                response: $response,
                content: [ 'error' => 'Failed to authorize!' ],
                status: 400,
            );
            return;
        }

        // At this point, 200 is enough to broadcast to the channel.
        $this->forceChannelMessage($channel, $message);

        Http::json(
            response: $response,
            content: [ 'success' => 'Message sent successfully!' ],
        );
    }

    /**
     * This endpoint expects a POST request with a body with the following structure:
     * {
     *     "channel": "channel-name",
     * }
     */
    private function tempToken(Request $request, Response $response): void
    {
        $token = $request->get['token'];
        $content = json_decode($request->getContent(), true);

        $authToken = md5(uniqid($token));

        /** @var AuthTokenPersistenceInterface $table */
        $table = $this->persistence[Constants::AUTH_TOKENS];
        $table->storeToken($authToken, isset($content['channel']) ? $content['channel'] : '');

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([ 'auth' => $authToken ]));
    }

    private function forceChannelMessage(string $channel, string $message): void
    {
        ConveyorBroadcast::forceBroadcastToChannel(
            data: json_encode([
                'action' => BroadcastAction::NAME,
                'data' => $message,
            ]),
            channel: $channel,
            server: $this->server,
            channelPersistence: Arr::get($this->persistence, Constants::CHANNELS),
            ackPersistence: Arr::get($this->persistence, Constants::MESSAGES_ACKNOWLEDGEMENTS),
        );
    }

    private function executeConveyor(int $fd, string $data): Conveyor
    {
        $this->eventDispatcher->dispatch(
            event: new BeforeMessageHandledEvent($this->server, $data, $fd),
            eventName: Constants::EVENT_BEFORE_MESSAGE_HANDLED,
        );

        $conveyor = Conveyor::init(options: $this->conveyorOptions->all())
            ->server($this->server)
            ->fd($fd)
            ->persistence($this->persistence)
            ->addActions($this->conveyorOptions->{Constants::ACTIONS} ?? [])
            ->closeConnections()
            ->run($data);

        $this->eventDispatcher->dispatch(
            event: new AfterMessageHandledEvent($this->server, $data, $fd),
            eventName: Constants::EVENT_AFTER_MESSAGE_HANDLED,
        );

        return $conveyor;
    }
}

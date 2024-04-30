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
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Traits\HasAcknowledgement;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
     * @param ?Client $client
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
        if (isset($this->persistence['channels'])) {
            $this->persistence['channels']->disconnect($fd); // @phpstan-ignore-line
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
        $method = $request->server['request_method'];
        $uri = $request->server['request_uri'];

        if (
            !str_contains($uri, 'jacked/auth')
            || strtoupper($method) !== 'POST'
        ) {
            Http::json(
                response: $response,
                content: [],
                status: 404,
            );
            return;
        }

        if (!isset($this->persistence['channels'])) {
            Http::json(
                response: $response,
                content: [ 'error' => 'Channels not enabled!' ],
                status: 400,
            );
            return;
        }

        $content = json_decode($request->getContent(), true);

        $channel = Arr::get($content, 'channel');
        $connections = $this->persistence['channels']->getAllConnections();
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

        if (null === $this->conveyorOptions->{Constants::WEBSOCKET_AUTH_URL}) {
            $this->forceChannelMessage($channel, $message);
        }

        // TODO: add filter for websocket auth customization point here
        if (!$this->websocketAuthRequest($channel)) {
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
     * This implementation is for Laravel Broadcasting. This is just
     * the fallback, you are able to customize by using Filter Hooks.
     *
     * @param string $channel
     * @return bool
     */
    private function websocketAuthRequest(string $channel): bool
    {
        $httpClient = $this->httpClient ?? new Client([ 'timeout' => 2.0 ]);
        $params = [
            'query' => [
                'channel_name' => $channel,
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];
        if ($this->conveyorOptions->{Constants::WEBSOCKET_AUTH_TOKEN}) {
            $params['headers']['Authorization'] = 'Bearer ' . $this->conveyorOptions->{Constants::WEBSOCKET_AUTH_TOKEN};
        }

        try {
            $authResponse = $httpClient->get(
                $this->conveyorOptions->{Constants::WEBSOCKET_AUTH_URL},
                $params,
            );
        } catch (GuzzleException $e) {
            // TODO: add logging
            return false;
        }

        $parsedResponse = json_decode($authResponse->getBody()->getContents(), true);
        if (200 !== $authResponse->getStatusCode() || !isset($parsedResponse['auth'])) {
            return false;
        }

        return true;
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
            channelPersistence: Arr::get($this->persistence, 'channels'),
            ackPersistence: Arr::get($this->persistence, 'messages-acknowledgments'),
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

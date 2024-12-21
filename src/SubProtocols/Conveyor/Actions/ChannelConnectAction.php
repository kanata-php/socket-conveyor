<?php

namespace Conveyor\SubProtocols\Conveyor\Actions;

use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Actions\Abstractions\AbstractAction;
use Conveyor\SubProtocols\Conveyor\Actions\Traits\HasPresence;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;

class ChannelConnectAction extends AbstractAction
{
    use HasPresence;

    public const NAME = 'channel-connect';

    protected string $name = self::NAME;

    /**
     * @param array{channel: string, auth: ?string} $data
     * @return mixed
     * @throws Exception
     */
    public function execute(array $data): mixed
    {
        $this->validateData($data);

        $channel = $data['channel'];

        if ($this->isAuthEnabled() && !$this->websocketAuthRequest($channel, auth: $data['auth'])) {
            return null;
        }

        if (null !== $this->channelPersistence) {
            $this->channelPersistence->connect($this->fd, $channel);
        }

        // @phpstan-ignore-next-line
        if ($this->conveyorOptions->{Constants::USE_PRESENCE}) {
            $this->broadcastPresence();
        }

        return null;
    }

    public function validateData(array $data): void
    {
        if (!isset($data['channel'])) {
            throw new InvalidArgumentException('Channel connection must specify "channel"!');
        }

        if ($this->isAuthEnabled() && !isset($data['auth'])) {
            throw new InvalidArgumentException('Channel connection must specify "auth" when authentication is set!');
        }
    }

    private function isAuthEnabled(): bool
    {
        return null !== $this->conveyorOptions->{Constants::WEBSOCKET_AUTH_URL}; // @phpstan-ignore-line
    }

    /**
     * This implementation is for Laravel Broadcasting. This is just
     * the fallback, you are able to customize by using Filter Hooks.
     *
     * @param string $channel
     * @param string $auth
     * @return bool
     */
    private function websocketAuthRequest(string $channel, string $auth): bool
    {
        $httpClient = $this->httpClient ?? new Client([ 'timeout' => 2.0 ]);
        $params = [
            'query' => [
                'channel_name' => $channel,
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $auth,
            ],
        ];

        try {
            $authResponse = $httpClient->get(
                $this->conveyorOptions->{Constants::WEBSOCKET_AUTH_URL}, // @phpstan-ignore-line
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
}

<?php

namespace Conveyor\SubProtocols\Pusher;

class PusherSigner
{
    /**
     * Compute the HMAC-SHA256 (hex) signature for a private or presence channel.
     *
     * The string to sign is "<socketId>:<channel>" for private channels and
     * "<socketId>:<channel>:<channelData>" when presence channel data is supplied.
     */
    public function channelSignature(
        string $secret,
        string $socketId,
        string $channel,
        ?string $channelData = null,
    ): string {
        $stringToSign = $socketId . ':' . $channel;

        if ($channelData !== null) {
            $stringToSign .= ':' . $channelData;
        }

        return hash_hmac('sha256', $stringToSign, $secret);
    }

    /**
     * Build the "<appKey>:<signature>" auth token expected by clients.
     */
    public function channelAuth(
        string $appKey,
        string $secret,
        string $socketId,
        string $channel,
        ?string $channelData = null,
    ): string {
        return $appKey . ':' . $this->channelSignature($secret, $socketId, $channel, $channelData);
    }

    /**
     * Verify a provided "<key>:<signature>" channel auth in constant time.
     */
    public function verifyChannelAuth(
        string $appKey,
        string $secret,
        string $providedAuth,
        string $socketId,
        string $channel,
        ?string $channelData = null,
    ): bool {
        $parts = explode(':', $providedAuth, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$providedKey, $providedSignature] = $parts;

        if (!hash_equals($appKey, $providedKey)) {
            return false;
        }

        $expectedSignature = $this->channelSignature($secret, $socketId, $channel, $channelData);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Hex md5 of the raw request body, used as the body_md5 REST parameter.
     */
    public function bodyMd5(string $body): string
    {
        return md5($body);
    }

    /**
     * Compute the REST request signature (HMAC-SHA256 hex).
     *
     * The auth_signature key is dropped, the remaining params are sorted by key
     * and joined unescaped as "k=v&...", then signed against
     * "<METHOD>\n<path>\n<paramString>".
     *
     * @param array<array-key, mixed> $params
     */
    public function requestSignature(
        string $secret,
        string $method,
        string $path,
        array $params,
    ): string {
        unset($params['auth_signature']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $paramString = implode('&', $pairs);

        $stringToSign = strtoupper($method) . "\n" . $path . "\n" . $paramString;

        return hash_hmac('sha256', $stringToSign, $secret);
    }

    /**
     * Verify a provided REST signature in constant time.
     *
     * @param array<array-key, mixed> $params
     */
    public function verifyRequest(
        string $secret,
        string $method,
        string $path,
        array $params,
        string $providedSignature,
    ): bool {
        $expectedSignature = $this->requestSignature($secret, $method, $path, $params);

        return hash_equals($expectedSignature, $providedSignature);
    }
}

<?php

namespace Tests\Unit\Pusher;

use Conveyor\SubProtocols\Pusher\Frame\PusherEvent;
use Conveyor\SubProtocols\Pusher\Frame\PusherFrame;
use Tests\TestCase;

class PusherFrameTest extends TestCase
{
    /**
     * Decode an encoded frame and assert its `data` field is a JSON string
     * (double-encoded), returning the data decoded back into an array.
     *
     * @return array{frame: array<string, mixed>, data: array<string, mixed>}
     */
    private function assertDoubleEncoded(string $encoded): array
    {
        $frame = json_decode($encoded, true);

        $this->assertIsArray($frame);
        $this->assertArrayHasKey('data', $frame);
        $this->assertIsString($frame['data'], 'data must be a JSON string on the wire');

        $data = json_decode($frame['data'], true);
        $this->assertIsArray($data);

        return ['frame' => $frame, 'data' => $data];
    }

    public function testConnectionEstablishedRoundTrip()
    {
        $out = PusherFrame::connectionEstablished('732456.123987', 120);
        ['frame' => $frame, 'data' => $data] = $this->assertDoubleEncoded($out);

        $this->assertEquals(PusherEvent::CONNECTION_ESTABLISHED, $frame['event']);
        $this->assertArrayNotHasKey('channel', $frame);
        $this->assertEquals('732456.123987', $data['socket_id']);
        $this->assertEquals(120, $data['activity_timeout']);
    }

    public function testConnectionEstablishedDefaultActivityTimeout()
    {
        $out = PusherFrame::connectionEstablished('1.1');
        ['data' => $data] = $this->assertDoubleEncoded($out);

        $this->assertEquals(120, $data['activity_timeout']);
    }

    public function testSubscriptionSucceededNonPresenceHasEmptyObjectData()
    {
        $out = PusherFrame::subscriptionSucceeded('my-channel');
        $frame = json_decode($out, true);

        $this->assertEquals(PusherEvent::SUBSCRIPTION_SUCCEEDED, $frame['event']);
        $this->assertEquals('my-channel', $frame['channel']);
        $this->assertIsString($frame['data']);
        // Must serialise as an empty object, never an empty array.
        $this->assertEquals('{}', $frame['data']);
    }

    public function testSubscriptionSucceededTreatsEmptyArrayAsNoRoster()
    {
        $out = PusherFrame::subscriptionSucceeded('my-channel', []);
        $frame = json_decode($out, true);

        $this->assertEquals('{}', $frame['data']);
    }

    public function testSubscriptionSucceededPresenceNestsRoster()
    {
        $roster = [
            'count' => 2,
            'ids' => ['10', '11'],
            'hash' => [
                '10' => ['name' => 'Alice'],
                '11' => ['name' => 'Bob'],
            ],
        ];

        $out = PusherFrame::subscriptionSucceeded('presence-room', $roster);
        ['frame' => $frame, 'data' => $data] = $this->assertDoubleEncoded($out);

        $this->assertEquals('presence-room', $frame['channel']);
        $this->assertArrayHasKey('presence', $data);
        $this->assertEquals(2, $data['presence']['count']);
        $this->assertEquals(['10', '11'], $data['presence']['ids']);
        $this->assertEquals(['name' => 'Alice'], $data['presence']['hash']['10']);
        $this->assertEquals(['name' => 'Bob'], $data['presence']['hash']['11']);
    }

    public function testMemberAddedRoundTrip()
    {
        $out = PusherFrame::memberAdded('presence-room', 10, ['name' => 'Alice']);
        ['frame' => $frame, 'data' => $data] = $this->assertDoubleEncoded($out);

        $this->assertEquals(PusherEvent::MEMBER_ADDED, $frame['event']);
        $this->assertEquals('presence-room', $frame['channel']);
        $this->assertEquals(10, $data['user_id']);
        $this->assertEquals(['name' => 'Alice'], $data['user_info']);
    }

    public function testMemberRemovedRoundTrip()
    {
        $out = PusherFrame::memberRemoved('presence-room', 10);
        ['frame' => $frame, 'data' => $data] = $this->assertDoubleEncoded($out);

        $this->assertEquals(PusherEvent::MEMBER_REMOVED, $frame['event']);
        $this->assertEquals('presence-room', $frame['channel']);
        $this->assertEquals(10, $data['user_id']);
        $this->assertArrayNotHasKey('user_info', $data);
    }

    public function testErrorRoundTrip()
    {
        $out = PusherFrame::error(PusherEvent::ERROR_UNAUTHORIZED, 'Connection is unauthorized');
        ['frame' => $frame, 'data' => $data] = $this->assertDoubleEncoded($out);

        $this->assertEquals(PusherEvent::ERROR, $frame['event']);
        $this->assertArrayNotHasKey('channel', $frame);
        $this->assertEquals(4009, $data['code']);
        $this->assertEquals('Connection is unauthorized', $data['message']);
    }

    public function testPongRoundTrip()
    {
        $out = PusherFrame::pong();
        $frame = json_decode($out, true);

        $this->assertEquals(PusherEvent::PONG, $frame['event']);
        $this->assertIsString($frame['data']);
        $this->assertEquals('{}', $frame['data']);
    }

    public function testEventDeliveryRoundTrip()
    {
        $out = PusherFrame::encode('OrderShipped', ['id' => 1], 'orders.1');
        ['frame' => $frame, 'data' => $data] = $this->assertDoubleEncoded($out);

        $this->assertEquals('OrderShipped', $frame['event']);
        $this->assertEquals('orders.1', $frame['channel']);
        $this->assertEquals(1, $data['id']);
    }

    public function testEncodeOmitsChannelWhenNull()
    {
        $out = PusherFrame::encode('pusher:something', ['a' => 1]);
        $frame = json_decode($out, true);

        $this->assertArrayNotHasKey('channel', $frame);
    }

    public function testEncodeDoesNotDoubleStringifyAStringPayload()
    {
        $preEncoded = '{"id":1,"name":"foo"}';
        $out = PusherFrame::encode('OrderShipped', $preEncoded, 'orders.1');
        ['frame' => $frame, 'data' => $data] = $this->assertDoubleEncoded($out);

        // The verbatim string should be the `data` value, decoding straight to
        // the object — not a string-of-a-string.
        $this->assertEquals($preEncoded, $frame['data']);
        $this->assertEquals(1, $data['id']);
        $this->assertEquals('foo', $data['name']);
    }

    public function testDecodeMalformedReturnsEmptyArray()
    {
        $this->assertEquals([], PusherFrame::decode('not json'));
        $this->assertEquals([], PusherFrame::decode('"a-bare-string"'));
        $this->assertEquals([], PusherFrame::decode(''));
    }

    public function testDecodeExtractsFrameKeys()
    {
        $raw = '{"event":"OrderShipped","channel":"orders.1","data":"{\"id\":1}"}';
        $decoded = PusherFrame::decode($raw);

        $this->assertEquals('OrderShipped', $decoded['event']);
        $this->assertEquals('orders.1', $decoded['channel']);
        $this->assertEquals('{"id":1}', $decoded['data']);
    }

    public function testDataArrayHandlesStringInboundData()
    {
        // Client that sends `data` as a double-encoded JSON string.
        $raw = '{"event":"pusher:subscribe","data":"{\"channel\":\"c\",\"auth\":\"key:sig\"}"}';
        $decoded = PusherFrame::decode($raw);
        $data = PusherFrame::dataArray($decoded);

        $this->assertEquals('c', $data['channel']);
        $this->assertEquals('key:sig', $data['auth']);
    }

    public function testDataArrayHandlesObjectInboundData()
    {
        // Client that sends `data` as a nested object.
        $raw = '{"event":"pusher:subscribe","data":{"channel":"c","auth":"key:sig","channel_data":"{}"}}';
        $decoded = PusherFrame::decode($raw);
        $data = PusherFrame::dataArray($decoded);

        $this->assertEquals('c', $data['channel']);
        $this->assertEquals('key:sig', $data['auth']);
        $this->assertEquals('{}', $data['channel_data']);
    }

    public function testDataArrayReturnsEmptyForMissingOrUnparseableData()
    {
        $this->assertEquals([], PusherFrame::dataArray(['event' => 'pusher:ping']));
        $this->assertEquals([], PusherFrame::dataArray(['event' => 'x', 'data' => '']));
        $this->assertEquals([], PusherFrame::dataArray(['event' => 'x', 'data' => 'garbage']));
        $this->assertEquals([], PusherFrame::dataArray(['event' => 'x', 'data' => '{}']));
    }

    public function testPingPongConstantsAndDataArrayOnEmptyObject()
    {
        $raw = '{"event":"pusher:ping","data":"{}"}';
        $decoded = PusherFrame::decode($raw);

        $this->assertEquals(PusherEvent::PING, $decoded['event']);
        $this->assertEquals([], PusherFrame::dataArray($decoded));
    }
}

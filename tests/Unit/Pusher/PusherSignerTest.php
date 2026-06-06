<?php

namespace Tests\Unit\Pusher;

use Conveyor\SubProtocols\Pusher\PusherSigner;
use Tests\TestCase;

class PusherSignerTest extends TestCase
{
    private string $secret = '7ad3773142a6692b25b8';
    private string $key = '278d425bdf160c739803';

    private string $privateSig = '58df8b0c36d6982b82c3ecf6b4662e34fe8c25bba48f5369f135bf843651c3a4';
    private string $presenceSig = '31935e7d86dba64c2a90aed31fdc61869f9b22ba9d8863bba239c03ca481bc80';
    private string $bodyMd5 = 'ec365a775a4cd0599faeb73354201b6f';
    private string $restSig = 'da454824c97ba181a32ccc17a72625ba02771f50b50e1e7430e47a1f3f457e6c';

    public function testPrivateChannelSignature()
    {
        $signer = new PusherSigner();

        $this->assertEquals(
            $this->privateSig,
            $signer->channelSignature($this->secret, '1234.1234', 'private-foobar'),
        );
    }

    public function testPrivateChannelAuth()
    {
        $signer = new PusherSigner();

        $this->assertEquals(
            $this->key . ':' . $this->privateSig,
            $signer->channelAuth($this->key, $this->secret, '1234.1234', 'private-foobar'),
        );
    }

    public function testPresenceChannelSignature()
    {
        $signer = new PusherSigner();
        $channelData = '{"user_id":10,"user_info":{"name":"Mr. Channels"}}';

        $this->assertEquals(
            $this->presenceSig,
            $signer->channelSignature($this->secret, '1234.1234', 'presence-foobar', $channelData),
        );
    }

    public function testVerifyChannelAuthAcceptsGoodSignature()
    {
        $signer = new PusherSigner();

        $this->assertTrue($signer->verifyChannelAuth(
            $this->key,
            $this->secret,
            $this->key . ':' . $this->privateSig,
            '1234.1234',
            'private-foobar',
        ));
    }

    public function testVerifyChannelAuthRejectsBadSignature()
    {
        $signer = new PusherSigner();

        $this->assertFalse($signer->verifyChannelAuth(
            $this->key,
            $this->secret,
            $this->key . ':deadbeefdeadbeef',
            '1234.1234',
            'private-foobar',
        ));
    }

    public function testVerifyChannelAuthRejectsWrongKey()
    {
        $signer = new PusherSigner();

        $this->assertFalse($signer->verifyChannelAuth(
            $this->key,
            $this->secret,
            'someotherkey:' . $this->privateSig,
            '1234.1234',
            'private-foobar',
        ));
    }

    public function testBodyMd5()
    {
        $signer = new PusherSigner();
        $body = '{"name":"foo","channels":["project-3"],"data":"{\"some\":\"data\"}"}';

        $this->assertEquals($this->bodyMd5, $signer->bodyMd5($body));
    }

    public function testRequestSignature()
    {
        $signer = new PusherSigner();
        $params = [
            'auth_key' => $this->key,
            'auth_timestamp' => '1353088179',
            'auth_version' => '1.0',
            'body_md5' => $this->bodyMd5,
        ];

        $this->assertEquals(
            $this->restSig,
            $signer->requestSignature($this->secret, 'POST', '/apps/3/events', $params),
        );
    }

    public function testRequestSignatureIgnoresAuthSignatureAndOrder()
    {
        $signer = new PusherSigner();
        $params = [
            'body_md5' => $this->bodyMd5,
            'auth_version' => '1.0',
            'auth_signature' => 'should-be-dropped',
            'auth_timestamp' => '1353088179',
            'auth_key' => $this->key,
        ];

        $this->assertEquals(
            $this->restSig,
            $signer->requestSignature($this->secret, 'POST', '/apps/3/events', $params),
        );
    }

    public function testVerifyRequestAcceptsGoodSignature()
    {
        $signer = new PusherSigner();
        $params = [
            'auth_key' => $this->key,
            'auth_timestamp' => '1353088179',
            'auth_version' => '1.0',
            'body_md5' => $this->bodyMd5,
        ];

        $this->assertTrue(
            $signer->verifyRequest($this->secret, 'POST', '/apps/3/events', $params, $this->restSig),
        );
    }

    public function testVerifyRequestRejectsBadSignature()
    {
        $signer = new PusherSigner();
        $params = [
            'auth_key' => $this->key,
            'auth_timestamp' => '1353088179',
            'auth_version' => '1.0',
            'body_md5' => $this->bodyMd5,
        ];

        $this->assertFalse(
            $signer->verifyRequest($this->secret, 'POST', '/apps/3/events', $params, 'deadbeef'),
        );
    }
}

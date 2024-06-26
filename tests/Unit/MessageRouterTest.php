<?php

namespace Tests\Unit;

use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Actions\AcknowledgeAction;
use Conveyor\SubProtocols\Conveyor\Actions\AssocUserToFdAction;
use Conveyor\SubProtocols\Conveyor\Actions\BaseAction;
use Conveyor\SubProtocols\Conveyor\Actions\BroadcastAction;
use Conveyor\SubProtocols\Conveyor\Actions\ChannelConnectAction;
use Conveyor\SubProtocols\Conveyor\Actions\ChannelDisconnectAction;
use Conveyor\SubProtocols\Conveyor\Actions\FanoutAction;
use Conveyor\SubProtocols\Conveyor\Conveyor;
use Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table\SocketChannelPersistenceTable;
use Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table\SocketUserAssocPersistenceTable;
use Exception;
use Hook\Filter;
use Mockery;
use OpenSwoole\WebSocket\Server;
use Tests\Assets\SampleAction;
use Tests\Assets\SampleMiddleware;
use Tests\Assets\SampleMiddleware2;
use Tests\TestCase;

class MessageRouterTest extends TestCase
{
    public function testCanInitializeRouter()
    {
        $server = Mockery::mock(Server::class);

        $socketMessageRouter = Conveyor::init()
            ->server($server)
            ->fd(1)
            ->persistence();

        $this->assertEquals('persistence_set', $socketMessageRouter->getMessageRouter()->getState());
    }

    public function testCanSendBaseAction()
    {
        $fd = 1;
        $message = 'text';
        $expectedResponse = json_encode([
            'action' => BaseAction::NAME,
            'data' => $message,
            'fd' => $fd,
        ]);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('isEstablished')->andReturnTrue();
        $server->shouldReceive('push')
            ->andReturnUsing(function ($fd, $data) use ($expectedResponse) {
                $this->assertEquals($expectedResponse, $data);
                return true;
            });

        $clearVerification = false;

        Conveyor::init()
            ->server($server)
            ->fd($fd)
            ->persistence()
            ->run($message)
            ->finalize(function () use (&$clearVerification) {
                $clearVerification = true;
            });

        $this->assertTrue($clearVerification);
    }

    public function testCanSendBroadcastAction()
    {
        $fd = 1;
        $message = json_encode([
            'action' => BroadcastAction::NAME,
            'data' => 'text',
        ]);
        $expectedResponse = json_encode([
            'action' => BroadcastAction::NAME,
            'data' => 'text',
            'fd' => $fd,
        ]);

        $server = Mockery::mock(Server::class);
        $server->connections = [1, 2, 3];
        $server->shouldReceive('isEstablished')->andReturn(true);
        $server->shouldReceive('push')
            ->with(1, $expectedResponse)
            ->times(1);
        $server->shouldReceive('push')
            ->with(2, $expectedResponse)
            ->times(1);
        $server->shouldReceive('push')
            ->with(3, $expectedResponse)
            ->times(1);

        $result = Conveyor::init()
            ->server($server)
            ->fd($fd)
            ->persistence()
            ->run($message);

        $this->assertEquals('message_processed', $result->getMessageRouter()->getState());
    }

    public function testCanSendAssocUserToFdAction()
    {
        $fd = 1;
        $message = json_encode([
            'action' => AssocUserToFdAction::NAME,
            'userId' => 2,
        ]);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('push')->times(0);

        $associationPersistence = Mockery::mock(SocketUserAssocPersistenceTable::class);
        $associationPersistence->shouldReceive('assoc')
            ->andReturnUsing(function ($fd, $userId) {
                $this->assertEquals(1, $fd);
                $this->assertEquals(2, $userId);
                return true;
            })
            ->times(1);
        $associationPersistence->shouldReceive('getAssoc')
            ->andReturnUsing(function () {
                return 1;
            })
            ->times(1);

        Conveyor::init()
            ->server($server)
            ->fd($fd)
            ->persistence([
                $associationPersistence,
            ])
            ->run($message);
    }

    public function testCanSendChannelConnectAction()
    {
        $fd = 1;
        $expectedChannel = 'channel-1';
        $message = json_encode([
            'action' => ChannelConnectAction::NAME,
            'channel' => $expectedChannel,
        ]);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('push')->times(0);

        $channelsPersistence = Mockery::mock(SocketChannelPersistenceTable::class);
        $channelsPersistence->shouldReceive('getAllConnections')->andReturn([]);
        $channelsPersistence->shouldReceive('connect')
            ->andReturnUsing(function ($fd, $channel) use ($expectedChannel) {
                $this->assertEquals(1, $fd);
                $this->assertEquals($expectedChannel, $channel);
                return true;
            })
            ->times(1);

        Conveyor::init()
            ->server($server)
            ->fd($fd)
            ->persistence([
                $channelsPersistence,
            ])
            ->run($message);
    }

    public function testCanSendChannelDisconnectAction()
    {
        $fd = 1;
        $message = json_encode([
            'action' => ChannelDisconnectAction::NAME,
        ]);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('push')->times(0);

        $channelsPersistence = Mockery::mock(SocketChannelPersistenceTable::class);
        $channelsPersistence->shouldReceive('disconnect')
            ->andReturnUsing(function ($fd) {
                $this->assertEquals(1, $fd);
                return true;
            })
            ->times(1);

        Conveyor::init()
            ->server($server)
            ->fd($fd)
            ->persistence([
                $channelsPersistence,
            ])
            ->run($message);
    }

    public function testCanSendChannelFanoutAction()
    {
        $fd = 1;
        $message = json_encode([
            'action' => FanoutAction::NAME,
            'data' => 'text',
            'fd' => $fd,
        ]);

        $server = Mockery::mock(Server::class);
        $server->connections = [1, 2, 3];
        $server->shouldReceive('isEstablished')->times(3)->andReturn(true);
        $server->shouldReceive('push')
            ->with(1, $message)
            ->times(1);
        $server->shouldReceive('push')
            ->with(2, $message)
            ->times(1);
        $server->shouldReceive('push')
            ->with(3, $message)
            ->times(1);

        $result = Conveyor::init()
            ->server($server)
            ->fd($fd)
            ->persistence()
            ->run($message);

        $this->assertEquals('message_processed', $result->getMessageRouter()->getState());
    }

    public function testCanAddMultipleMiddlewaresAndFailFirst()
    {
        $fd = 1;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid Token');

        $server = Mockery::mock(Server::class);

        Conveyor::init()
            ->server($server)
            ->fd($fd)
            ->persistence()
            ->addActions([new SampleAction()])
            ->addMiddlewareToAction(SampleAction::NAME, new SampleMiddleware())
            ->addMiddlewareToAction(SampleAction::NAME, new SampleMiddleware2())
            ->run(json_encode([
                'action' => SampleAction::NAME,
                'token'  => 'invalid-token',
                'second-verification'  => 'valid',
            ]));
    }

    public function testCantSendPlainText()
    {
        $expectedFd = 1;
        $expectedMessage = 'some message';
        $expectedTrue = false;

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('isEstablished')->andReturnTrue();
        $server->shouldReceive('push')
            ->andReturnUsing(function ($fd, $data) use (&$expectedTrue, $expectedFd, $expectedMessage) {
                $this->assertEquals($expectedFd, $fd);
                $this->assertEquals(json_encode([
                    'action' => BaseAction::NAME,
                    'data' => $expectedMessage,
                    'fd' => $expectedFd,
                ]), $data);
                $expectedTrue = true;
                return true;
            });

        Conveyor::init()
            ->server($server)
            ->fd($expectedFd)
            ->persistence()
            ->run($expectedMessage);

        $this->assertTrue($expectedTrue);
    }

    public function testCanCallRefreshPersistence()
    {
        $fd = 1;

        $message = json_encode([
            'action' => ChannelConnectAction::NAME,
            'channel' => 'test-channel',
        ]);

        $channelPersistence = new SocketChannelPersistenceTable();

        Conveyor::refresh([$channelPersistence]);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('isEstablished')->andReturnTrue();
        $server->shouldReceive('push');

        Conveyor::init()
            ->server($server)
            ->fd($fd)
            ->persistence([$channelPersistence])
            ->run($message);

        $this->assertCount(1, $channelPersistence->getAllConnections());

        Conveyor::refresh([$channelPersistence]);

        $this->assertEmpty($channelPersistence->getAllConnections());
    }

    public function testTheAcknowledgmentSetMessageId()
    {
        Filter::addFilter(
            Constants::FILTER_PUSH_MESSAGE,
            function (string $data, int $fd, Server $server) {
                $parsedData = json_decode($data, true);
                $this->assertTrue(isset($parsedData['id']));
                unset($parsedData['id']);
                return json_encode($parsedData);
            },
            60, // bigger than default
        );

        $fd = 1;
        $message = 'text';
        $expectedResponse = json_encode([
            'action' => BaseAction::NAME,
            'data' => $message,
            'fd' => $fd,
        ]);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('isEstablished')->andReturnTrue();
        $server->shouldReceive('push')
            ->andReturnUsing(function ($fd, $data) use ($expectedResponse) {
                $this->assertEquals($expectedResponse, $data);
                return true;
            });

        $clearVerification = false;

        Conveyor::init([
                Constants::USE_ACKNOWLEDGMENT => true,
            ])
            ->server($server)
            ->fd($fd)
            ->persistence()
            ->run($message)
            ->finalize(function () use (&$clearVerification) {
                $clearVerification = true;
            });

        $this->assertTrue($clearVerification);
        Filter::removeAllFilters(Constants::FILTER_PUSH_MESSAGE);
    }

    public function testChannelAcknowledgmentAlone()
    {
        // channel connection assertion
        $fd = 1;
        $channel = 'my-channel';
        $messageId = 'my-awesome-id';
        $message = json_encode([
            'action' => ChannelConnectAction::NAME,
            'channel' => $channel,
            'id' => $messageId, // necessary for the acknowledgement!
        ]);
        $expectedResponse = json_encode([
            'action' => AcknowledgeAction::NAME,
            'data' => $messageId,
        ]);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('isEstablished')->andReturnTrue();
        $server->shouldReceive('push')
            ->andReturnUsing(function ($fd, $data) use ($expectedResponse) {
                $this->assertEquals($expectedResponse, $data);
                return true;
            });

        $clearVerification = false;

        Conveyor::init([
                Constants::USE_ACKNOWLEDGMENT => true,
            ])
            ->server($server)
            ->fd($fd)
            ->persistence()
            ->run($message)
            ->finalize(function () use (&$clearVerification) {
                $clearVerification = true;
            });

        $this->assertTrue($clearVerification);
    }

    public function testChannelPresenceAlone()
    {
        // channel connection assertion
        $fd = 1;
        $channel = 'my-channel';
        $messageId = 'my-awesome-id';
        $message = json_encode([
            'action' => ChannelConnectAction::NAME,
            'channel' => $channel,
            'id' => $messageId, // necessary for the acknowledgement!
        ]);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('isEstablished')->andReturnTrue();
        $server->shouldReceive('push')
            ->andReturnUsing(function ($fd, $data) {
                // presence assertion (after acknowledgment)
                $parsedData = json_decode($data, true);
                $parsedMessage = json_decode($parsedData['data'], true);
                $this->assertEquals(ChannelConnectAction::NAME, $parsedData['action']);
                $this->assertEquals(Constants::ACTION_EVENT_CHANNEL_PRESENCE, $parsedMessage['event']);
                $this->assertCount(1, $parsedMessage['fds']);
                return true;
            });

        $clearVerification = false;

        Conveyor::init([
                Constants::USE_PRESENCE => true,
            ])
            ->server($server)
            ->fd($fd)
            ->persistence()
            ->run($message)
            ->finalize(function () use (&$clearVerification) {
                $clearVerification = true;
            });

        $this->assertTrue($clearVerification);
    }

    public function testChannelAcknowledgmentAndPresence()
    {
        // channel connection assertion
        $fd = 1;
        $channel = 'my-channel';
        $messageId = 'my-awesome-id';
        $message = json_encode([
            'action' => ChannelConnectAction::NAME,
            'channel' => $channel,
            'id' => $messageId, // necessary for the acknowledgement!
        ]);
        $expectedResponse = json_encode([
            'action' => AcknowledgeAction::NAME,
            'data' => $messageId,
        ]);

        $counter = 0;
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('isEstablished')->andReturnTrue();
        $server->shouldReceive('push')
            ->andReturnUsing(function ($fd, $data) use ($expectedResponse, &$counter) {
                if ($counter === 0) {
                    $this->assertEquals($expectedResponse, $data);
                } else {
                    // presence assertion (after acknowledgment)
                    $parsedData = json_decode($data, true);
                    $parsedMessage = json_decode($parsedData['data'], true);
                    $this->assertEquals(ChannelConnectAction::NAME, $parsedData['action']);
                    $this->assertTrue(isset($parsedData['id']));
                    $this->assertEquals(Constants::ACTION_EVENT_CHANNEL_PRESENCE, $parsedMessage['event']);
                    $this->assertCount(1, $parsedMessage['fds']);
                }

                $counter++;
                return true;
            });

        $clearVerification = false;

        Conveyor::init([
                Constants::USE_ACKNOWLEDGMENT => true,
                Constants::USE_PRESENCE => true,
            ])
            ->server($server)
            ->fd($fd)
            ->persistence()
            ->run($message)
            ->finalize(function () use (&$clearVerification) {
                $clearVerification = true;
            });

        $this->assertTrue($clearVerification);
    }
}

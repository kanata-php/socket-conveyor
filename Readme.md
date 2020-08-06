![Conveyor](./imgs/logo.png)

# Socket Conveyor

![Tests](https://github.com/WordsTree/socket-conveyor/workflows/Tests/badge.svg)
[![Code Intelligence Status](https://scrutinizer-ci.com/g/WordsTree/socket-conveyor/badges/code-intelligence.svg?b=master)](https://scrutinizer-ci.com/code-intelligence)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/WordsTree/socket-conveyor/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/WordsTree/socket-conveyor/?branch=master)


A WebSocket/Socket message Router for PHP.

This package enables you to work with socket messages with routes strategy, just as if you were specifying routes in [Laravel](https://laravel.com/) or [Slim](https://www.slimframework.com/) projects. For that, you just add an Action Handler implementing the ActionInterface to the SocketMessageRouter and watch the magic happen!

This package assumes that the application is receiving socket messages with a socket server. As an example of how to accomplish that with PHP, you can use the [Swoole PHP Extension](https://www.swoole.co.uk/).



## How it works



The main example is set in the `tests ` directory, but here is how it works:



![Conveyor Process](./imgs/conveyor-process.png)



## Usage



In order to use it in your application, you would do something like this:

```php
// @var Conveyor\Actions\Interfaces\ActionInterface
$sampleAction = new SampleAction();

// @var Conveyor\SocketHandlers\SocketMessageRouter
$socketRouter = SocketMessageRouter::getInstance();

// add the action handler
$socketRouter->add($sampleAction);

// @var Conveyor\ActionMiddlewares\Interfaces\MiddlewareInterface
$sampleMiddleware = new SampleMiddleware;
$sampleMiddleware2 = new SampleMiddleware2;

// add middlewares
$socketRouter->middleware($sampleAction->getName(), $sampleMiddleware);
$socketRouter->middleware($sampleAction->getName(), $sampleMiddleware2);

// socket message must look like this:
// @var string
$data = json_encode([
    'action' => $sampleAction->getName(),
    'some-other-field'  => 'some value',
]);
$result = ($socketRouter)($data);
```



## Motivation



WebSocket procedures are more and more common with PHP, and realtime applications are becoming more often. That said, there is a need for solutions that help to work like that in PHP.



## Tests

Run Command:

```shell
./vendor/bin/phpunit
```



## TODO

- add support to protobuf
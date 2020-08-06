![Conveyor](./imgs/logo.png)

# Conveyor

![Tests](https://github.com/WordsTree/conveyor/workflows/Tests/badge.svg)



A WebSocket/Socket message Router fro PHP.

This packages enables you to work with socket messages with routes strategy, just as if you were specifying routes in [Laravel](https://laravel.com/) or [Slim](https://www.slimframework.com/) projects. For that, you just add an Action Handler implementing the ActionInterface to the SocketMessageRouter and watch the magic happening!

this package assumes that the application is receiving socket messages with a socket server. an example of how to accomplish that with PHP, you can use the [Swoole PHP Extension](https://www.swoole.co.uk/).



## How it works



The main example are set in the `tests ` directory, but here is how it works:



![Conveyor Process](./imgs/conveyor-process.png)



## Usage



To use it, in your application, you would do something like this:

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
$sampleAction->pipe($sampleAction->getName(), $sampleMiddleware);
$sampleAction->pipe($sampleAction->getName(), $sampleMiddleware2);

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
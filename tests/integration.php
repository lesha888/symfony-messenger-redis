<?php

namespace Soyuka\RedisMessengerAdapter\Tests;

require __DIR__.'/../vendor/autoload.php';

use Soyuka\RedisMessengerAdapter\Tests\Fixtures\Message;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Asynchronous\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Asynchronous\Routing\SenderLocator;
use Symfony\Component\DependencyInjection\Container;
use Soyuka\RedisMessengerAdapter\Connection;
use Symfony\Component\Messenger\Transport\Serialization\Serializer as MessageSerializer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Soyuka\RedisMessengerAdapter\Sender;
use Soyuka\RedisMessengerAdapter\Receiver;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Handler\Locator\HandlerLocator;
use Soyuka\RedisMessengerAdapter\RejectMessageException;

// Build a serializer
$encoders = array(new JsonEncoder());
$normalizers = array(new ObjectNormalizer());
$serializer = new Serializer($normalizers, $encoders);

// Messenger encoder/decoder
$messageSerializer = new MessageSerializer($serializer);

$queueName = 'test';
$data = 'Hello world!';
$failure = 'Fail Once';
$numFailure = 0;
$numAck = 0;

// This comes from the Soyuka\RedisMessengerAdapter
$redis = new Connection();
$senderId = 'redis_messenger.senders.test';
$sender = new Sender($messageSerializer, $redis, $queueName);
$receiver = new Receiver($messageSerializer, $redis, $queueName);

$container = new Container();
$container->set($senderId, $sender);

$handler = function ($t) use ($data, $failure, &$numFailure, &$numAck) {
    if ($t->foo === $failure) {
        if (0 === $numFailure) {
            ++$numFailure;
            throw new RejectMessageException('Fail');
        } else {
            ++$numAck;
        }
    } elseif ($t->foo === $data) {
        ++$numAck;
    }

    echo sprintf('Got message "%s". Num ACK %s, num failures %s', $t->foo, $numAck, $numFailure).PHP_EOL;

    if (2 === $numAck && 1 === $numFailure) {
        exit(0);
    }
};

$bus = new MessageBus(array(
    new SendMessageMiddleware(new SenderLocator($container, array(
        Message::class => $senderId,
    ))),
    new HandleMessageMiddleware(new HandlerLocator(array(
        Message::class => $handler,
    ))),
));

$bus->dispatch(new Message($data));
$bus->dispatch(new Message($failure));

$worker = new Worker($receiver, $bus);
$worker->run();

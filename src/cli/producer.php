<?php

require_once dirname(__DIR__) . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connected = false;
while (!$connected) {
    try {
        $connection = new AMQPStreamConnection('rabbit', 5672, 'guest', 'guest');
        $connected = true;
    } catch (Throwable $exception) {
        echo "Waiting for connection...\n";
        sleep(5);
    }
}

$channel = $connection->channel();

$queue = 'parser';
$exchange = 'app';

$channel->queue_declare($queue, false, true, false, false);

$channel->exchange_declare($exchange, 'direct', false, true, false);

$channel->queue_bind($queue, $exchange);

$messageBody = $argv[1];
$message = new AMQPMessage($messageBody,
    ['content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
$channel->basic_publish($message, $exchange);
$channel->close();
$connection->close();
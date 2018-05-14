<?php

require_once dirname(__DIR__) . '/../vendor/autoload.php';

use PhpAmqpLib\Channel\AMQPChannel;
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

$elasticClient = \Elasticsearch\ClientBuilder::fromConfig([
    'hosts' => [
        'elastic:9200',
    ],
]);

$builder = new \App\cli\Components\AnalyticsBuilder($elasticClient);

$channel->basic_consume(
    $queue,
    'parser',
    false,
    false,
    false,
    false,
    function (AMQPMessage $message) use ($builder) {
        /** @var AMQPChannel $channel */
        $channel = $message->delivery_info['channel'];

        $body = $message->getBody();
        $data = json_decode($body, true);

        if ($data !== null) {
            if ($data['action'] === 'quit') {
                $channel->basic_cancel($message->delivery_info['consumer_tag']);
            } else {
                $builder->build($data['login']);
//                $parser->parse($data['action'], $data['params']);
                $channel->basic_ack($message->delivery_info['delivery_tag']);
            }
        } else {
            $channel->basic_ack($message->delivery_info['delivery_tag']);
        }
    });

register_shutdown_function(function (AMQPChannel $channel, AMQPStreamConnection $connection) {
    $channel->close();
    $connection->close();
}, $channel, $connection);

while (count($channel->callbacks)) {
    $channel->wait();
}
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

$guzzleClient = new \GuzzleHttp\Client([
    'verify'     => false,
    'user-agent' => 'Opera/9.80 (Android; Opera Mini/8.0.1807/36.1609; U; en) Presto/2.12.423 Version/12.16',
    'base_uri'   => 'https://m.facebook.com',
    'cookies'    => new \GuzzleHttp\Cookie\CookieJar(false),
    'headers'    => [
        'Accept-Charset'  => 'utf-8',
        'Accept-Language' => 'en-us,en;q=0.7,bn-bd;q=0.3',
        'Accept'          => 'text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5',
    ],
]);

$elasticClient = \Elasticsearch\ClientBuilder::fromConfig([
    'hosts' => [
        'elastic:9200',
    ],
]);

$parser = new \App\cli\Components\Parser($elasticClient, $guzzleClient, $channel);

$channel->basic_consume(
    $queue,
    'parser',
    false,
    false,
    false,
    false,
    function (AMQPMessage $message) use ($parser) {
        /** @var AMQPChannel $channel */
        $channel = $message->delivery_info['channel'];

        $body = $message->getBody();
        $data = json_decode($body, true);
//        var_dump($data);

        if ($data !== null) {
            if ($data['action'] === 'quit') {
                $channel->basic_cancel($message->delivery_info['consumer_tag']);
            } else {
                $parser->parse($data['action'], $data['params']);
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

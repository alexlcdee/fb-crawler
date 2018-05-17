<?php

require_once dirname(__DIR__) . '/../vendor/autoload.php';

use App\cli\Components\Parser;
use App\FacebookCrawler\NotAuthenticatedException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$elasticClient = \Elasticsearch\ClientBuilder::fromConfig([
    'hosts' => [
        'elastic:9200',
    ],
]);

$connected = false;
$connection = null;
while (!$connected) {
    try {
        if ($connection === null) {
            $connection = new AMQPStreamConnection('rabbit', 5672, 'guest', 'guest');
        }
        $elasticClient->nodes()->info();
        $connected = true;
    } catch (Throwable $exception) {
        echo "Waiting for connection...\n";
        echo $exception->getMessage(), "\n";
        sleep(5);
    }
}

echo "Connected! \n";

$channel = $connection->channel();

$queue = 'parser';
$exchange = 'app';

$channel->queue_declare($queue, false, true, false, false);

$channel->exchange_declare($exchange, 'direct', false, true, false);

$channel->queue_bind($queue, $exchange);

try {
    $elasticClient->indices()->create([
        'index' => 'tracker',
        'body'  => [
            'mappings' => [
                'friend' => [
                    'properties' => [
                        'clientLogin' => ['type' => 'keyword'],
                        'name'        => ['type' => 'keyword'],
                        'userUrl'     => ['type' => 'keyword'],
                    ],
                ],
                'post'   => [
                    'properties' => [
                        'feedOwner'  => ['type' => 'keyword'],
                        'authorLink' => ['type' => 'keyword'],
                        'authorName' => ['type' => 'keyword'],
                    ],
                ],
            ],
        ],
    ]);
} catch (Throwable $exception) {
}

$logger = new \Monolog\Logger('parser', [
    new class extends \Monolog\Handler\StreamHandler
    {
        public function __construct(
            int $level = \Monolog\Logger::DEBUG,
            bool $bubble = true,
            ?int $filePermission = null,
            bool $useLocking = false
        ) {
            parent::__construct(STDOUT, $level, $bubble, $filePermission, $useLocking);
        }
    },
]);

$channel->basic_consume(
    $queue,
    'parser',
    false,
    false,
    false,
    false,
    function (AMQPMessage $message) use ($elasticClient, $logger) {
        $body = $message->getBody();
        $data = json_decode($body, true);


        /** @var AMQPChannel $channel */
        $channel = $message->delivery_info['channel'];

        if ($data !== null) {
            if ($data['action'] === 'quit') {
                $channel->basic_cancel($message->delivery_info['consumer_tag']);
            } else {
                try {
                    $cookieFile = md5($data['params']['login'] . $data['params']['password']) . '.txt';
                    $guzzleClient = new Client([
                        'verify'     => false,
                        'user-agent' => 'Opera/9.80 (Android; Opera Mini/8.0.1807/36.1609; U; en) Presto/2.12.423 Version/12.16',
                        'base_uri'   => 'https://m.facebook.com',
                        'cookies'    => new FileCookieJar(
                            __DIR__ . '/runtime/' . $cookieFile,
                            true
                        ),
                        'headers'    => [
                            'Accept-Charset'  => 'utf-8',
                            'Accept-Language' => 'en-us,en;q=0.7,bn-bd;q=0.3',
                            'Accept'          => 'text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5',
                        ],
                    ]);

                    $parser = new Parser($elasticClient, $guzzleClient, $channel);
                    $parser->setLogger($logger);

                    $parser->parse($data['action'], $data['params']);

                    $channel->basic_ack($message->delivery_info['delivery_tag']);
                } catch (NotAuthenticatedException $exception) {
                    echo $exception->getMessage(), "\n";
                    $channel->basic_ack($message->delivery_info['delivery_tag']);
                } catch (Throwable $exception) {
                    echo $exception->getMessage(), "\n";
                }
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

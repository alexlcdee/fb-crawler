<?php

namespace App\cli\Components;


use App\FacebookCrawler\Authenticator;
use App\FacebookCrawler\Friends;
use App\FacebookCrawler\Posts;
use Elasticsearch\Client as ElasticClient;
use GuzzleHttp\ClientInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Parser
{
    /**
     * @var ElasticClient
     */
    private $elasticClient;
    /**
     * @var ClientInterface
     */
    private $guzzleClient;
    /**
     * @var AMQPChannel
     */
    private $AMQPChannel;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ElasticClient $elasticClient, ClientInterface $guzzleClient, AMQPChannel $AMQPChannel)
    {
        $this->elasticClient = $elasticClient;
        $this->guzzleClient = $guzzleClient;
        $this->AMQPChannel = $AMQPChannel;
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $section
     * @param array $params
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function parse(string $section, array $params)
    {
        $authenticator = $this->createAuthenticator($params['login'], $params['password']);

        switch ($section) {
            case 'friends':
                $this->parseFriends($authenticator, $params);
                break;
            case 'posts':
                $this->parsePosts($authenticator, $params);
                break;
            default:
                throw new \InvalidArgumentException('$section must be either "friends" or "posts"');
        }
        $this->logger->info('Action done!');
    }

    private function createAuthenticator($login, $password)
    {
        return new Authenticator($this->guzzleClient, $login, $password);
    }

    /**
     * @param Authenticator $authenticator
     * @param array $params
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function parseFriends(Authenticator $authenticator, array $params)
    {
        $this->logger->info("Load friends list for user {$params['login']}...");
        $parser = new Friends($this->guzzleClient, $authenticator);
        $parser->setLogger($this->logger);

        $friends = $parser->getFriendsList();
        $this->logger->info("Friend list for user {$params['login']} loaded!");

        foreach ($friends as $friend) {
            $this->elasticClient->index([
                'index' => 'tracker',
                'type'  => 'friend',
                'id'    => $friend->getId(),
                'body'  => $friend->jsonSerialize() + [
                        'loaded'      => false,
                        'clientLogin' => $params['login'],
                    ],
            ]);

            $message = new AMQPMessage(json_encode([
                'action' => 'posts',
                'params' => $params + [
                        'userUrl' => $friend->getUserUrl(),
                        'userId'  => $friend->getId(),
                    ],
            ]), ['content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
            $this->AMQPChannel->basic_publish($message, 'app');
        }
    }

    /**
     * @param Authenticator $authenticator
     * @param array $params
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function parsePosts(Authenticator $authenticator, array $params)
    {
        $this->logger->info("Load posts for link {$params['userUrl']}...");
        $parser = new Posts($this->guzzleClient, $authenticator);
        $parser->setLogger($this->logger);

        $posts = $parser->getPosts($params['userUrl']);
        $this->logger->info("Posts list for link {$params['userUrl']} loaded!");

        foreach ($posts as $post) {
            $this->elasticClient->index([
                'index' => 'tracker',
                'type'  => 'post',
                'id'    => sha1(json_encode($post)),
                'body'  => [
                        'userId'      => $params['userId'],
                        'clientLogin' => $params['login'],
                    ] + $post->jsonSerialize(),
            ]);
        }

        $this->elasticClient->update([
            'index' => 'tracker',
            'type'  => 'friend',
            'id'    => $params['userId'],
            'body'  => [
                'doc' => [
                    'loaded' => true,
                ],
            ],
        ]);

        $postsCount = count($posts);
        $this->logger->info("Total posts found: {$postsCount}");
    }
}
<?php

namespace App\cli\Components;


use App\FacebookCrawler\Authenticator;
use App\FacebookCrawler\Friends;
use App\FacebookCrawler\Posts;
use Elasticsearch\Client as ElasticClient;
use GuzzleHttp\ClientInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

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

    public function __construct(ElasticClient $elasticClient, ClientInterface $guzzleClient, AMQPChannel $AMQPChannel)
    {
        $this->elasticClient = $elasticClient;
        $this->guzzleClient = $guzzleClient;
        $this->AMQPChannel = $AMQPChannel;
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
        echo "Parsing done!\n";
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
        echo "Load friends list for user {$params['login']}...\n";
        $parser = new Friends($this->guzzleClient, $authenticator);
        $friends = $parser->getFriendsList();

        foreach ($friends as $friend) {
            echo "Put friend into storage\n";
            $this->elasticClient->index([
                'index' => 'tracker',
                'type'  => 'friend',
                'id'    => $friend->getId(),
                'body'  => $friend->jsonSerialize() + ['loaded' => false],
            ]);

            $message = new AMQPMessage(json_encode([
                'action' => 'posts',
                'params' => $params + [
                        'userUrl' => $friend->getUserUrl(),
                        'userIr'  => $friend->getId(),
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
        echo "Load posts for link: {$params['userUrl']}...\n";
        $parser = new Posts($this->guzzleClient, $authenticator);
        $posts = $parser->getPosts($params['userUrl']);

        foreach ($posts as $post) {
            echo "Put post into storage\n";
            $this->elasticClient->index([
                'index' => 'tracker',
                'type'  => 'post',
                'id'    => sha1(json_encode($post)),
                'body'  => $post,
            ]);
        }

        $this->elasticClient->update([
            'index'  => 'tracker',
            'type'   => 'friend',
            'id'     => $params['userId'],
            'loaded' => true,
        ]);
    }
}
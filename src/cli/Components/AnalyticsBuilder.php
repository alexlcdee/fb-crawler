<?php

namespace App\cli\Components;


use App\Entities\Analytics;
use App\Entities\Friend;
use App\Entities\Post;
use Elasticsearch\Client as ElasticClient;

class AnalyticsBuilder
{
    /**
     * @var ElasticClient
     */
    private $elasticClient;

    public function __construct(ElasticClient $elasticClient)
    {
        $this->elasticClient = $elasticClient;
    }

    public function build($login, $clientLink)
    {
        $friends = $this->getFriends($login);

        $analytics = array_map(function (Friend $friend) use ($clientLink) {
            $posts = $this->getPosts($friend->getUserUrl(), $clientLink);

            return new Analytics($friend, $posts);
        }, $friends);

        array_map(function (Analytics $analytics) {
            $name = $analytics->getFriend()->getName();
            $isBot = $analytics->isBot();
            echo sprintf(
                "%s: %s\n",
                $name,
                $isBot ? 'Possibly BOT!' : 'Possibly not bot'
            );

            return [
                'name'  => $analytics->getFriend()->getName(),
                'isBot' => $analytics->isBot(),
            ];
        }, $analytics);

    }

    private function getFriends($login)
    {
        $data = [];

        $response = $this->elasticClient->search([
            'scroll' => '30s',
            'size'   => 50,
            'index'  => 'tracker',
            'type'   => 'friend',
            'body'   => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['term' => ['clientLogin' => $login]],
                        ],
                    ],
                ],
            ],
        ]);

        while (isset($response['hits']['hits']) && count($response['hits']['hits']) > 0) {
            array_push($data, ...$response['hits']['hits']);
            $response = $this->elasticClient->scroll([
                'scroll_id' => $response['_scroll_id'],
                'scroll'    => '30s',
            ]);
        }

        return array_map(function ($definition) {
            return Friend::fromArray($definition['_source']);
        }, $data);
    }

    private function getPosts($friendUrl, $authorUrl)
    {
        $data = [];
        $response = $this->elasticClient->search([
            'scroll' => '30s',
            'size'   => 50,
            'index'  => 'tracker',
            'type'   => 'post',
            'body'   => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['term' => ['authorLink' => (string)$authorUrl]],
                            ['term' => ['feedOwner' => (string)$friendUrl]],
                        ],
                    ],
                ],
            ],
        ]);

        while (isset($response['hits']['hits']) && count($response['hits']['hits']) > 0) {
            array_push($data, ...$response['hits']['hits']);
            $response = $this->elasticClient->scroll([
                'scroll_id' => $response['_scroll_id'],
                'scroll'    => '30s',
            ]);
        }

        return array_map(function ($definition) {
            return Post::fromArray($definition['_source']);
        }, $data);
    }
}
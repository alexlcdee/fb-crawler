<?php

namespace App\cli\Components;


use App\Entities\Comment;
use App\Entities\Friend;
use App\Entities\Like;
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

        $analytics = array_reduce($friends, function (array $carry, Friend $friend) use ($clientLink) {
            $posts = $this->getPosts($friend->getUserUrl(), $clientLink);
            $reactedPosts = array_reduce($posts, function (array $carry, Post $post) use ($friend) {
                $hasLike = array_reduce($post->getLikes(), function ($value, Like $like) use ($friend) {
                    if ($friend->getUserUrl() === $like->getUserUrl()) {
                        $value = true;
                    }

                    return $value;
                }, false);

                $hasComment = array_reduce($post->getComments(), function ($value, Comment $comment) use ($friend) {
                    if ($friend->getUserUrl() === $comment->getAuthorLink()) {
                        $value = true;
                    }

                    return $value;
                }, false);

                array_push($carry, [
                    'post'        => $post,
                    'hasReaction' => $hasLike || $hasComment,
                ]);

                return $carry;
            }, []);
            array_push($carry, [
                'friend'           => $friend,
                'totalClientPosts' => count($posts),
                'posts'            => $reactedPosts,
                'isBot'            => count($posts) <= count(array_filter($reactedPosts, function ($postDefinition) {
                        return $postDefinition['hasReaction'];
                    })),
            ]);

            return $carry;
        }, []);

        var_dump($analytics);
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
                            ['match' => ['clientLogin' => $login]],
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
                            ['match' => ['authorLink' => (string)$authorUrl]],
                            ['match' => ['feedOwner' => (string)$friendUrl]],
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
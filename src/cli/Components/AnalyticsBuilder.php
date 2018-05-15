<?php

namespace App\cli\Components;


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

    public function build($login)
    {
        $response = $this->elasticClient->search([
            'scroll' => '30s',
            'size'   => 50,
            'index'  => 'tracker',
            'type'   => 'post',
            'body'   => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['match' => ['clientLogin' => $login]],
                            ['match' => ['authorLink' => '/alexlcdee']],
                        ],
                    ],
                ],
            ],
        ]);

        $data = [];

        while (isset($response['hits']['hits']) && count($response['hits']['hits']) > 0) {
            array_push($data, ...$response['hits']['hits']);
            $response = $this->elasticClient->scroll([
                'scroll_id' => $response['_scroll_id'],
                'scroll'    => '30s',
            ]);
        }

        var_dump(array_map(function ($definition) {
            return Post::fromArray($definition['_source']);
        }, $data));
    }
}
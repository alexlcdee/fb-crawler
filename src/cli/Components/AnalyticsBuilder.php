<?php

namespace App\cli\Components;


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
            'index' => 'tracker',
            'type'  => 'post',
            'body'  => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['match' => ['clientLogin' => $login]],
                        ],
                    ],
                ],
            ],
        ]);

        var_dump($response['hits']['total']);
    }
}
<?php

namespace App\FacebookCrawler;


use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;

class Posts
{
    /**
     * @var ClientInterface
     */
    private $client;
    /**
     * @var Authenticator
     */
    private $authenticator;

    public function __construct(ClientInterface $client, Authenticator $authenticator)
    {
        $this->client = $client;
        $this->authenticator = $authenticator;
    }

    /**
     * @param string $userUrl
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getPosts(string $userUrl)
    {
        if (!$this->authenticator->authenticate()) {
            throw new NotAuthenticatedException('Failed to authenticate request');
        }

        $posts = $this->retrieveRecentPosts($userUrl);

        $yearLinks = $this->retrieveYearLinks($userUrl);
        foreach ($yearLinks as $link) {
            $nextPosts = $this->retrievePostsByYearLink($link);
            if (count($nextPosts)) {
                array_push($posts, ...$nextPosts);
            }
        }

        return array_filter($posts);
    }

    /**
     * @param $url
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function retrieveRecentPosts($url)
    {
        return $this->retrievePosts(
            $url,
            '#structured_composer_async_container .dj.dk.dl',
            [$this, 'retrieveRecentPosts']
        );
    }

    /**
     * @param $url
     * @param $postSelector
     * @param callable $next
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function retrievePosts($url, $postSelector, callable $next)
    {
        $response = $this->client->request('GET', $url);
        $body = $response->getBody()->getContents();

        $page = new Crawler($body);

        $posts = $page->filter($postSelector)->each(function (Crawler $node) {
            return $node->text();
        });

        $nextPage = $page->filter('#structured_composer_async_container > .g > a');
        if ($nextPage->count() && stripos($nextPage->first()->text(), 'Show more') !== false) {
            $nextPosts = $next($nextPage->first()->attr('href'));
            if (count($nextPosts)) {
                array_push($posts, ...$nextPosts);
            }
        }

        return $posts;
    }

    /**
     * @param $userUrl
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function retrieveYearLinks($userUrl)
    {
        $response = $this->client->request('GET', $userUrl);

        $page = new Crawler($response->getBody()->getContents());

        $yearLinks = $page->filter('#structured_composer_async_container .g a');

        $links = $yearLinks->each(function (Crawler $node) {
            $href = $node->attr('href');

            return stripos($href, 'yearSectionsYears') ? $href : null;
        });


        return array_filter($links);
    }

    /**
     * @param $url
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function retrievePostsByYearLink($url)
    {
        return $this->retrievePosts(
            $url,
            '#structured_composer_async_container .bk.by.bz',
            [$this, 'retrievePostsByYearLink']
        );
    }
}
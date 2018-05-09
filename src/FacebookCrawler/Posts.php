<?php

namespace App\FacebookCrawler;


use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
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
            '#structured_composer_async_container .dg.dh.di > .dj.dk.dl',
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

        $posts = $page->filter($postSelector)->each(function (Crawler $node) use ($postSelector) {
            $authorNode = $node->filter('table h3 a');
            $authorUrl = new Uri($authorNode->first()->attr('href'));

            return [
                'authorLink' => $authorUrl->getPath(),
                'authorName' => $authorNode->first()->text(),
                'content'    => $node->filter("div > div")->eq(2)->text(),
                'reactions'  => $this->crawlReactions($node),
            ];
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
     * @param Crawler $postNode
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function crawlReactions(Crawler $postNode)
    {
        $comments = [];
        $likes = [];
        $reactionsContainer = $postNode->filter('.dt, .co, .cu, .cv, .cg, .bq, .cg');
        $commentPageLink = $reactionsContainer->filter('a')->reduce(function (Crawler $node) {
            return stripos($node->text(), 'comment') !== false;
        });
        if ($commentPageLink->count()) {
            $url = $commentPageLink->attr('href');
            $response = $this->client->request('GET', $url);
            $commentsPage = new Crawler($response->getBody()->getContents());
            $commentsContainer = $commentsPage->filter('div')->reduce(function (Crawler $node) {
                return stripos($node->attr('id'), 'ufi_') !== false;
            });
            if ($commentsContainer->count()) {
                $comments = $this->crawlComments($commentsPage->first());
                $likes = $this->crawlLikes($commentsPage->first());
            }
//            file_put_contents(__DIR__.'/../../www/pages/'.md5($url).'.html', $response->getBody()->getContents());
        }

        return [
            'comments' => $comments,
            'likes'    => $likes,
        ];
    }

    /**
     * @param Crawler $commentsContainer
     * @return array
     */
    private function crawlComments(Crawler $commentsContainer)
    {
        return $commentsContainer->filter('div > div > div')
            ->reduce(function (Crawler $comment) {
                $id = $comment->attr('id');

                return $id === preg_replace('/\D/', '', $id);
            })
            ->each(function (Crawler $comment) {
                $nameNode = $comment->filter('h3 a')->first();
                $textNode = $comment->filter('div > div > div')->first();

                return [
                    'commentatorName' => $nameNode->count() ? $nameNode->text() : '',
                    'commentatorUrl'  => $nameNode->count() ? (new Uri($nameNode->attr('href')))->getPath() : '',
                    'comment'         => $textNode->count() ? $textNode->text() : '',
                ];
            });
    }

    /**
     * @param Crawler $commentsPage
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function crawlLikes(Crawler $commentsPage)
    {
        $reactionsContainer = $commentsPage->filter('div')->reduce(function (Crawler $node) {
            return stripos($node->attr('id'), 'sentence_') !== false;
        });
        if ($reactionsContainer->filter('a')->count()) {
            $response = $this->client->request('GET', $reactionsContainer->filter('a')->first()->attr('href'));
            $body = $response->getBody()->getContents();
            $reactionsPage = new Crawler($body);

            return $reactionsPage->filter('h3 a')->each(function (Crawler $reaction) {
                return [
                    'link' => $reaction->attr('href'),
                    'name' => $reaction->text(),
                ];
            });
        }

        return [];
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
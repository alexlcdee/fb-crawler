<?php

namespace App\FacebookCrawler;


use App\Entities\Link;
use App\Entities\Post;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
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
     * @return Post[]
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

        return array_map(function (array $post) use ($userUrl) {
            return Post::fromArray([
                'feedOwner'  => $userUrl,
                'authorLink' => $post['authorLink'],
                'authorName' => $post['authorName'],
                'content'    => $post['content'],
                'comments'   => $post['reactions']['comments'],
                'likes'      => $post['reactions']['likes'],
            ]);
        }, array_filter($posts));
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
            function (Crawler $page) {
                return $page->filter('#recent > div > div > div');
            },
            [$this, 'retrieveRecentPosts']
        );
    }

    /**
     * @param $url
     * @param callable $postFilter
     * @param callable $next
     * @return array
     */
    private function retrievePosts($url, callable $postFilter, callable $next)
    {
        try {
            $response = $this->client->request('GET', $url);
        } catch (GuzzleException $exception) {
            return [];
        }

        $body = $response->getBody()->getContents();

        $page = new Crawler($body);

        $posts = $postFilter($page)->each(function (Crawler $node) {
            $authorNode = $node->filter('table h3 a');
            $authorLink = Link::fromFacebookUri(new Uri($authorNode->first()->attr('href')));

            return [
                'authorLink' => (string)$authorLink,
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
            try {
                $response = $this->client->request('GET', $url);
            } catch (GuzzleException $exception) {
                return [
                    'comments' => [],
                    'likes'    => [],
                ];
            }

            $commentsPage = new Crawler($response->getBody()->getContents());
            $commentsContainer = $commentsPage->filter('div')->reduce(function (Crawler $node) {
                return stripos($node->attr('id'), 'ufi_') !== false;
            });
            if ($commentsContainer->count()) {
                $comments = $this->crawlComments($commentsPage->first());
                $likes = $this->crawlLikes($commentsPage->first());
            }
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

                $userUrl = '';
                if ($nameNode->count()) {
                    $userUrl = Link::fromFacebookUri(new Uri($nameNode->attr('href')));
                }

                return [
                    'authorName' => $nameNode->count() ? $nameNode->text() : '',
                    'authorLink' => (string)$userUrl,
                    'comment'    => $textNode->count() ? $textNode->text() : '',
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
            try {
                $response = $this->client->request('GET', $reactionsContainer->filter('a')->first()->attr('href'));
            } catch (GuzzleException $exception) {
                return [];
            }

            $body = $response->getBody()->getContents();
            $reactionsPage = new Crawler($body);

            return $reactionsPage->filter('h3 a')->each(function (Crawler $reaction) {
                $userUrl = Link::fromFacebookUri(new Uri($reaction->attr('href')));

                return [
                    'userUrl'  => (string)$userUrl,
                    'userName' => $reaction->text(),
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
        try {
            $response = $this->client->request('GET', $userUrl);
        } catch (GuzzleException $exception) {
            return [];
        }

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
            function (Crawler $page) {
                return $page->filter('#structured_composer_async_container > div > div:nth-child(2) > div > div');
            },
            [$this, 'retrievePostsByYearLink']
        );
    }
}
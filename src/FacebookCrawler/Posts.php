<?php

namespace App\FacebookCrawler;


use App\Entities\Link;
use App\Entities\Post;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ClientInterface $client, Authenticator $authenticator)
    {
        $this->client = $client;
        $this->authenticator = $authenticator;
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
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
        $this->logger->info('Retrieve recent posts: ' . $url);

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

        $this->logger->info('Retrieve posts by url: ' . $url);

        $body = $response->getBody()->getContents();

        $page = new Crawler($body);

        $posts = $this->parsePosts($postFilter, $page);

        $nextPage = $page->filter('#structured_composer_async_container > .g > a');
        if ($nextPage->count() && stripos($nextPage->first()->text(), 'Show more') !== false) {
            $nextPosts = $next($nextPage->first()->attr('href'));
            if (count($nextPosts)) {
                if (is_numeric(array_keys($nextPosts)[0])) {
                    foreach ($nextPosts as $postsOuter) {
                        array_push($posts, ...$postsOuter);
                    }
                } else {
                    array_push($posts, ...$nextPosts);
                }
            }
        }

        $result = [];
        foreach ($posts as $post) {
            if (is_numeric(array_keys($post)[0])) {
                $postsOuter = $post;
                foreach ($postsOuter as $post) {
                    $result[] = $post;
                }
            } else {
                $result[] = $post;
            }
        }

        return $result;
    }

    private function parsePosts(callable $postFilter, Crawler $page, bool $parseBDayPosts = true)
    {
        return $postFilter($page)->each(function (Crawler $node) use ($parseBDayPosts) {
            $bDaySubstoryLink = $node->children()->last()->filter('a')->reduce(function (Crawler $node) {
                return stripos($node->attr('href'), 'substories') !== false &&
                    stripos($node->attr('href'), 'cursor') !== false;
            });

            if ($bDaySubstoryLink->count() && $parseBDayPosts) {
                $uri = new Uri($bDaySubstoryLink->attr('href'));
                parse_str($uri->getQuery(), $queryParams);
                unset($queryParams['cursor']);

                return $this->parseBDaySubstory($uri->withQuery(http_build_query($queryParams)));
            }

            $authorNode = $node->filter('table h3 a');
            $authorLink = Link::fromFacebookUri(new Uri($authorNode->first()->attr('href')));

            return [
                'authorLink' => (string)$authorLink,
                'authorName' => $authorNode->first()->text(),
                'content'    => $node->filter("div > div")->eq(2)->text(),
                'reactions'  => $this->crawlReactions($node),
            ];
        });
    }

    /**
     * @param Uri $uri
     * @return array
     * @throws GuzzleException
     */
    private function parseBDaySubstory(Uri $uri)
    {
        $this->logger->info('Parse Birthday Substory: ' . $uri);
        $guzzleResponse = $this->client->request('GET', $uri);
        $body = $guzzleResponse->getBody()->getContents();

        $page = new Crawler($body);

        $posts = $this->parsePosts(function (Crawler $node) {
            return $node
                ->filter('#root > table > tbody > tr > td > div > div > div > div')
                ->reduce(function (Crawler $node) {
                    return $node->filter('table h3 a')->count() > 0;
                });
        }, $page, false);

        $bDaySubstoryLink = $page
            ->filter('#root > table > tbody > tr > td > div > div > div > div a')
            ->reduce(function (Crawler $node) {
                return stripos($node->attr('href'), 'substories') !== false &&
                    stripos($node->attr('href'), 'cursor') !== false;
            });

        if ($bDaySubstoryLink->count()) {
            $nextPosts = $this->parseBDaySubstory(new Uri($bDaySubstoryLink->attr('href')));
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
        $reactionsContainer = $postNode->filter('div:nth-child(2)');
        $commentPageLink = $reactionsContainer->filter('a')->reduce(function (Crawler $node) {
            return stripos($node->text(), 'comment') !== false ||
                mb_stripos($node->text(), 'комментарий') !== false;
        });
        if ($commentPageLink->count()) {
            $url = $commentPageLink->attr('href');
            try {
                $this->logger->info('Retrieve reactions page: ' . $url);
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
            $this->logger->info('Retrieve year links: ' . $userUrl);
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
        $this->logger->info('Retrieve posts by year link: ' . $url);

        return $this->retrievePosts(
            $url,
            function (Crawler $page) {
                return $page->filter('#structured_composer_async_container > div > div:nth-child(2) > div > div');
            },
            [$this, 'retrievePostsByYearLink']
        );
    }
}
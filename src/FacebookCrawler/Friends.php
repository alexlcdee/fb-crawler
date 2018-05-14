<?php

namespace App\FacebookCrawler;


use App\Entities\Friend;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use Symfony\Component\DomCrawler\Crawler;

class Friends
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
     * @return Friend[]
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getFriendsList()
    {
        if (!$this->authenticator->authenticate()) {
            throw new NotAuthenticatedException('Failed to authenticate request');
        }

        return array_map(function ($data) {
            return Friend::fromArray($data);
        }, $this->retrieveFriends());
    }

    /**
     * @param string $friendsUrl
     * @param int $nextPage
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function retrieveFriends($friendsUrl = '', $nextPage = 1)
    {
        $friendsUrl = $friendsUrl ? $friendsUrl : new Uri('/friends/center/friends/');

        $response = $this->client->request('GET', $friendsUrl);

        $body = $response->getBody()->getContents();

        $crawler = new Crawler($body);

        $friends = $this->parseFriends($crawler);

        if ($crawler->filter('#u_0_0')->count() > 0) {
            $nextFriends = $this->retrieveFriends($friendsUrl->withQuery('ppk=' . $nextPage), $nextPage + 1);
            if (count($nextFriends)) {
                array_push($friends, ...$nextFriends);
            }
        }

        return $friends;
    }

    /**
     * @param Crawler $page
     * @return array
     */
    private function parseFriends(Crawler $page)
    {
        return $page->filter('#friends_center_main > div')
            ->eq(1)
            ->filter('a')
            ->each(function (Crawler $node) {
                parse_str((new Uri($node->attr('href')))->getQuery(), $friendUriQuery);

                $response = $this->client->request('GET', $node->attr('href'));
                $crawler = new Crawler($response->getBody()->getContents());

                $link = $crawler->filter('.bg a');
                $url = $link->count() ? $link->first()->attr('href') : '';

                $image = $crawler->filter('img.ba');
                $photo = $image->count() ? $image->first()->attr('src') : '';

                $url = new Uri($url);
                $userUrl = $url->getPath();
                if ($url->getPath() === '/profile.php') {
                    parse_str($url->getQuery(), $queryParams);
                    $userUrl = (new Uri($url->getPath()))->withQuery('id=' . $queryParams['id']);
                }

                return [
                    'id'      => $friendUriQuery['uid'],
                    'name'    => $node->text(),
                    'userUrl' => (string)$userUrl,
                    'photo'   => $photo,
                ];
            });
    }
}
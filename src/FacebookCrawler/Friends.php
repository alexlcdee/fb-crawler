<?php

namespace App\FacebookCrawler;


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
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getFriendsList()
    {
        if (!$this->authenticator->authenticate()) {
            throw new NotAuthenticatedException('Failed to authenticate request');
        }

        return $this->retrieveFriends();
    }

    /**
     * @param string $friendsUrl
     * @param int $nextPage
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function retrieveFriends($friendsUrl = '', $nextPage = 1)
    {
//        sleep(mt_rand(5, 15));

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

    private function parseFriends(Crawler $page)
    {
        return $page->filter('a.bm')->each(function (Crawler $node) {
            parse_str((new Uri($node->attr('href')))->getQuery(), $friendUriQuery);

//            sleep(mt_rand(3, 5));
            $response = $this->client->request('GET', $node->attr('href'));
            $crawler = new Crawler($response->getBody()->getContents());

            $link = $crawler->filter('.bg a');
            $url = $link->count() ? $link->first()->attr('href') : '';

            $image = $crawler->filter('img.ba');
            $photo = $image->count() ? $image->first()->attr('src') : '';

            return [
                'id'        => $friendUriQuery['uid'],
                'name'      => $node->text(),
                'openerUrl' => $url,
                'photo'     => $photo,
            ];
        });
    }
}
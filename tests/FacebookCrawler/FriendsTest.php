<?php

namespace App\Tests\FacebookCrawler;

use App\FacebookCrawler\Authenticator;
use App\FacebookCrawler\Friends;
use App\FacebookCrawler\NotAuthenticatedException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FriendsTest extends TestCase
{
    public function test_can_get_friends_list()
    {
        /** @var MockObject|Authenticator $authenticator */
        $authenticator = $this->getMockBuilder(Authenticator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $authenticator->method('authenticate')->willReturn(true);

        /** @var MockObject|ClientInterface $client */
        $client = $this->getMockBuilder(ClientInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $firstPage = <<<HTML
<div id="main">
    <a href="2?uid=2" class="bm">Name 2</a>
    <a href="1?uid=1" class="bm">Name 1</a>
</div>
<a id="u_0_0">More</a>
HTML;
        $firstFriendPage = <<<HTML
<div class="bg">
    <a href="/friend1">Name 1</a>
</div>
<img src="img1" class="ba">
HTML;
        $secondFriendPage = <<<HTML
<div class="bg">
    <a href="/friend2">Name 2</a>
</div>
<img src="img2" class="ba">
HTML;
        $thirdFriendPage = <<<HTML
<div class="bg">
    <a href="/friend3">Name 3</a>
</div>
<img src="img3" class="ba">
HTML;
        $secondPage = <<<HTML
<div id="main">
    <a href="3?uid=3" class="bm">Name 3</a>
</div>
HTML;

        $client->method('request')->willReturn(
            $this->createResponse($firstPage),
            $this->createResponse($secondFriendPage),
            $this->createResponse($firstFriendPage),
            $this->createResponse($secondPage),
            $this->createResponse($thirdFriendPage)
        );

        $crawler = new Friends($client, $authenticator);

        $result = [
            [
                'id'        => '2',
                'name'      => 'Name 2',
                'openerUrl' => '/friend2',
                'photo'     => 'img2',
            ],
            [
                'id'        => '1',
                'name'      => 'Name 1',
                'openerUrl' => '/friend1',
                'photo'     => 'img1',
            ],
            [
                'id'        => '3',
                'name'      => 'Name 3',
                'openerUrl' => '/friend3',
                'photo'     => 'img3',
            ],
        ];

        $this->assertEquals($result, $crawler->getFriendsList());
    }

    private function createResponse($body)
    {
        return new Response(200, [], $body);
    }

    public function test_throw_exception_on_not_authenticated()
    {
        /** @var MockObject|Authenticator $authenticator */
        $authenticator = $this->getMockBuilder(Authenticator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $authenticator->method('authenticate')->willReturn(false);

        /** @var MockObject|ClientInterface $client */
        $client = $this->getMockBuilder(ClientInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $crawler = new Friends($client, $authenticator);

        $this->expectException(NotAuthenticatedException::class);

        $crawler->getFriendsList();
    }
}

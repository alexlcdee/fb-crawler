<?php

namespace App\Tests\FacebookCrawler;

use App\FacebookCrawler\Authenticator;
use App\FacebookCrawler\NotAuthenticatedException;
use App\FacebookCrawler\Posts;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PostsTest extends TestCase
{
    public function test_can_get_recent_posts()
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

        $firstPageHtml = <<<HTML
<div id="structured_composer_async_container">
    <div class="dj dk dl">Post Text</div>
    <div class="g"><a href="more">Show more</a></div>
</div>
HTML;
        $secondPageHtml = <<<HTML
<div id="structured_composer_async_container">
    <div class="dj dk dl">Post Text 2</div>
</div>
HTML;
        $client->method('request')->willReturn(
            $this->createResponse($firstPageHtml),
            $this->createResponse($secondPageHtml),
            $this->createResponse($firstPageHtml)
        );

        $crawler = new Posts($client, $authenticator);

        $result = [
            'Post Text',
            'Post Text 2',
        ];

        $this->assertEquals($result, $crawler->getPosts('link'));
    }

    private function createResponse($body)
    {
        return new Response(200, [], $body);
    }

    public function test_can_get_posts_by_years()
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

        $firstPageHtml = <<<HTML
<div id="structured_composer_async_container">
    <div class="dj dk dl">Post Text</div>
    <div>
        <div class="g"><a href="/more?yearSectionsYears=2018">2018</a></div>
    </div>
</div>
HTML;
        $secondPageHtml = <<<HTML
<div id="structured_composer_async_container">
    <div class="bk by bz">Post Text 2</div>
</div>
HTML;
        $client->method('request')->willReturn(
            $this->createResponse($firstPageHtml),
            $this->createResponse($firstPageHtml),
            $this->createResponse($secondPageHtml)
        );

        $crawler = new Posts($client, $authenticator);

        $result = [
            'Post Text',
            'Post Text 2',
        ];

        $this->assertEquals($result, $crawler->getPosts('link'));
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

        $crawler = new Posts($client, $authenticator);

        $this->expectException(NotAuthenticatedException::class);

        $crawler->getPosts('test');
    }
}

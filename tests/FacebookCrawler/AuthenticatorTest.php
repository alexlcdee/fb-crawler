<?php

namespace App\Tests\FacebookCrawler;

use App\FacebookCrawler\Authenticator;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuthenticatorTest extends TestCase
{
    public function test_can_authenticate()
    {
        /** @var ClientInterface|MockObject $client */
        $client = $this->getMockBuilder(ClientInterface::class)->getMock();
        $client->method('request')->willReturnCallback(function ($method) {
            if ($method === 'GET') {
                $html = <<<HTML
<form action="test" id="login_form">
    <input type="hidden" name="f1" value="f1">
    <input type="hidden" name="f2" value="f2">
    <input type="hidden" name="f3" value="f3">
</form>
HTML;
            } else {
                $html = '<div></div>';
            }

            return new Response(200, [], $html);
        });
        $authenticator = new Authenticator($client);

        $status = $authenticator->authenticate('test', 'test');

        $this->assertEquals(true, $status);
    }

    public function test_return_false_on_not_authenticated()
    {
        $response = '';
        /** @var ClientInterface|MockObject $client */
        $client = $this->getMockBuilder(ClientInterface::class)->getMock();
        $client->method('request')->willReturnCallback(function ($method, $url) use (&$response) {
            $html = <<<HTML
<form action="test" id="login_form">
    <input type="hidden" name="f1" value="f1">
    <input type="hidden" name="f2" value="f2">
    <input type="hidden" name="f3" value="f3">
</form>
HTML;

            return new Response(200, [], $html);
        });
        $authenticator = new Authenticator($client);

        $status = $authenticator->authenticate('test', 'test');

        $this->assertEquals(false, $status);
    }
}

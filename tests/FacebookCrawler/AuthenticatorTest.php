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

        $body1 = <<<HTML
<form action="test" id="login_form">
    <input type="hidden" name="f1" value="f1">
    <input type="hidden" name="f2" value="f2">
    <input type="hidden" name="f3" value="f3">
</form>
HTML;
        $body2 = '<div></div>';
        $client->method('request')->willReturn(
            $this->createResponse($body1),
            $this->createResponse($body2)
        );

        $authenticator = new Authenticator($client, 'test', 'test');

        $status = $authenticator->authenticate();

        $this->assertEquals(true, $status);
    }

    public function test_return_false_on_not_authenticated()
    {
        /** @var ClientInterface|MockObject $client */
        $client = $this->getMockBuilder(ClientInterface::class)->getMock();
        $body = <<<HTML
<form action="test" id="login_form">
    <input type="hidden" name="f1" value="f1">
    <input type="hidden" name="f2" value="f2">
    <input type="hidden" name="f3" value="f3">
</form>
HTML;

        $client->method('request')->willReturn(
            $this->createResponse($body),
            $this->createResponse($body)
        );
        $authenticator = new Authenticator($client, 'login', 'password');

        $status = $authenticator->authenticate();

        $this->assertEquals(false, $status);
    }

    public function test_fields_is_sent()
    {
        /** @var ClientInterface|MockObject $client */
        $client = $this->getMockBuilder(ClientInterface::class)->getMock();
        $client->method('request')->willReturnCallback(function ($method, $url, $config) {
            if (strtolower($method) === 'get') {
                $html = <<<HTML
<form action="/test_login.php" id="login_form">
    <input type="hidden" name="f1" value="f1">
    <input type="hidden" name="f2" value="f2">
    <input type="hidden" name="f3" value="f3">
</form>
HTML;

                return new Response(200, [], $html);
            }

            $this->assertEquals('post', strtolower($method));
            $this->assertEquals('/test_login.php', $url);
            $this->assertArrayHasKey('form_params', $config);
            $this->assertEquals([
                'f1'    => 'f1',
                'f2'    => 'f2',
                'f3'    => 'f3',
                'email' => 'test_login',
                'pass'  => 'test_password',
                'login' => 'Log In',
            ], $config['form_params']);

            return new Response(200, [], '<div></div>');
        });

        $authenticator = new Authenticator($client, 'test_login', 'test_password');
        $authenticator->authenticate();
    }

    public function test_authenticate_return_previous_result_when_already_authenticated()
    {
        /** @var ClientInterface|MockObject $client */
        $client = $this->getMockBuilder(ClientInterface::class)->getMock();

        $body1 = <<<HTML
<form action="test" id="login_form">
    <input type="hidden" name="f1" value="f1">
    <input type="hidden" name="f2" value="f2">
    <input type="hidden" name="f3" value="f3">
</form>
HTML;
        $body2 = '<div></div>';
        $client->expects($this->exactly(2))->method('request')->willReturn(
            $this->createResponse($body1),
            $this->createResponse($body2)
        );

        $authenticator = new Authenticator($client, 'test', 'test');

        $this->assertEquals(true, $authenticator->authenticate());
        $this->assertEquals(true, $authenticator->authenticate());
    }

    private function createResponse($body)
    {
        return new Response(200, [], $body);
    }
}

<?php

namespace App\FacebookCrawler;


use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class Authenticator
{
    /**
     * @var ClientInterface
     */
    private $client;

    private $isAuthenticated = false;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * @param string $login
     * @param string $password
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function authenticate(string $login, string $password)
    {
        if (!$this->isAuthenticated) {
            $this->isAuthenticated = true;
            [$fields, $loginUrl] = $this->getAuthParams();

            if ($loginUrl) {
                $response = $this->client->request('POST', $loginUrl, [
                    'form_params' => $fields + [
                            'email' => $login,
                            'pass'  => $password,
                            'login' => 'Log In',
                        ],
                ]);
                if ($this->hasLoginForm($response)) {
                    $this->isAuthenticated = false;
                }
            }
        }

        return $this->isAuthenticated;
    }

    /**
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getAuthParams()
    {
        $guzzleResponse = $this->client->request('GET', '/login.php');

        $crawler = new Crawler($guzzleResponse->getBody()->getContents());

        $fields = [];
        $crawler->filter('#login_form input[type=hidden]')->each(function (Crawler $node) use (&$fields) {
            $fields[$node->attr('name')] = $node->attr('value');
        });

        $nodeList = $crawler->filter('#login_form');
        $loginUrl = $nodeList->count() ? $nodeList->first()->attr('action') : '';

        return [$fields, $loginUrl];
    }

    private function hasLoginForm(ResponseInterface $response)
    {
        return (new Crawler($response->getBody()->getContents()))->filter('#login_form')->count();
    }
}
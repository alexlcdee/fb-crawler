<?php

namespace App\FacebookCrawler;


use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;

class Authenticator
{
    /**
     * @var ClientInterface
     */
    private $client;

    private $isAuthenticated = false;
    /**
     * @var string
     */
    private $login;
    /**
     * @var string
     */
    private $password;

    public function __construct(ClientInterface $client, string $login, string $password)
    {
        $this->client = $client;
        $this->login = $login;
        $this->password = $password;
    }

    /**
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function authenticate()
    {
        if (!$this->isAuthenticated) {
            $guzzleResponse = $this->client->request('GET', '/');
            $responseBody = $guzzleResponse->getBody()->getContents();
            if (!$this->hasLoginForm($responseBody)) {
                $this->isAuthenticated = true;

                return true;
            }

            [$fields, $loginUrl] = $this->getAuthParams($responseBody);

            $response = $this->client->request('POST', $loginUrl, [
                'form_params' => $fields + [
                        'email' => $this->login,
                        'pass'  => $this->password,
                        'login' => 'Log In',
                    ],
            ]);

            if (!$this->hasLoginForm($response->getBody()->getContents())) {
                $this->isAuthenticated = true;

                return true;
            }

            return false;
        }

        return $this->isAuthenticated;
    }

    /**
     * @param string $responseBody
     * @return array
     */
    private function getAuthParams(string $responseBody)
    {
        $crawler = new Crawler($responseBody);

        $fields = [];
        $crawler->filter('#login_form input[type=hidden]')->each(function (Crawler $node) use (&$fields) {
            $fields[$node->attr('name')] = $node->attr('value');
        });

        $nodeList = $crawler->filter('#login_form');
        $loginUrl = $nodeList->count() ? $nodeList->first()->attr('action') : '';

        return [$fields, $loginUrl];
    }

    private function hasLoginForm(string $responseBody)
    {
        return (new Crawler($responseBody))->filter('#login_form')->count();
    }
}
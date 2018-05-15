<?php

namespace App\FacebookCrawler;


use App\Entities\Link;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
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

    private $clientLink = null;

    private $clientName = null;

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
                $this->loadClientData();

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

            $responseBody = $response->getBody()->getContents();
            if (!$this->hasLoginForm($responseBody)) {
                $this->isAuthenticated = true;
                $this->loadClientData();

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

    public function getLogin()
    {
        return $this->login;
    }

    public function getClientLink()
    {
        if (!$this->clientLink) {
            $this->loadClientData();
        }

        return $this->clientLink;
    }

    public function getClientName()
    {
        if (!$this->clientName) {
            $this->loadClientData();
        }

        return $this->clientName;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function loadClientData()
    {
        if (!$this->authenticate()) {
            throw new NotAuthenticatedException('Not authenticated!');
        }

        $guzzleResponse = $this->client->request('GET', '/');
        $responseBody = $guzzleResponse->getBody()->getContents();
        $crawler = new Crawler($responseBody);

        $userLinkNode = $crawler->filter('#mbasic_inline_feed_composer > form > table a');
        if ($userLinkNode->count()) {
            $userLink = $userLinkNode->first();
            $this->clientLink = Link::fromFacebookUri(new Uri($userLink->attr('href')));
            $this->clientName = $userLink->filter('img')->first()->attr('alt');
        }
    }
}
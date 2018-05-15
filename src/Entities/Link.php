<?php

namespace App\Entities;


use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

class Link implements \JsonSerializable
{
    /**
     * @var Uri
     */
    private $url;

    public function __construct(string $url)
    {
        $this->url = new Uri($url);
    }

    public static function fromFacebookUri(UriInterface $uri)
    {
        $userUrl = $uri->getPath();
        if ($uri->getPath() === '/profile.php') {
            parse_str($uri->getQuery(), $queryParams);
            $userUrl = (new Uri($uri->getPath()))->withQuery('id=' . $queryParams['id']);
        }

        return new static((string)$userUrl);
    }

    public function equals($link)
    {
        return $this->__toString() === (string)$link;
    }

    /**
     * Specify data which should be serialized to JSON
     * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return (string)$this;
    }

    public function __toString()
    {
        return (string)$this->url;
    }
}
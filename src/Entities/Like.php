<?php

namespace App\Entities;


class Like implements \JsonSerializable
{
    /**
     * @var string
     */
    private $userName;
    /**
     * @var string
     */
    private $userUrl;

    public function __construct(string $userName, string $userUrl)
    {
        $this->userName = $userName;
        $this->userUrl = $userUrl;
    }

    public static function fromArray(array $data)
    {
        return new static($data['userName'], $data['userUrl']);
    }

    /**
     * @return string
     */
    public function getUserName(): string
    {
        return $this->userName;
    }

    /**
     * @return string
     */
    public function getUserUrl(): string
    {
        return $this->userUrl;
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
        return [
            'userName' => $this->userName,
            'userUrl'  => $this->userUrl,
        ];
    }
}
<?php

namespace App\Entities;


class Friend implements \JsonSerializable
{
    /**
     * @var string
     */
    private $id;
    /**
     * @var string
     */
    private $name;
    /**
     * @var string
     */
    private $userUrl;
    /**
     * @var string
     */
    private $photo;
    /**
     * @var string
     */
    private $clientLogin;

    public function __construct(string $id, string $clientLogin, string $name, string $userUrl, string $photo)
    {
        $this->id = $id;
        $this->name = $name;
        $this->userUrl = $userUrl;
        $this->photo = $photo;
        $this->clientLogin = $clientLogin;
    }

    public static function fromArray(array $data)
    {
        return new static($data['id'], $data['clientLogin'], $data['name'], $data['userUrl'], $data['photo']);
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getUserUrl(): string
    {
        return $this->userUrl;
    }

    /**
     * @return string
     */
    public function getPhoto(): string
    {
        return $this->photo;
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
            'id'          => $this->id,
            'clientLogin' => $this->clientLogin,
            'name'        => $this->name,
            'userUrl'     => $this->userUrl,
            'photo'       => $this->photo,
        ];
    }
}
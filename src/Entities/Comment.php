<?php

namespace App\Entities;


class Comment implements \JsonSerializable
{
    /**
     * @var Link
     */
    private $authorLink;
    /**
     * @var string
     */
    private $authorName;
    /**
     * @var string
     */
    private $comment;

    public function __construct(Link $authorLink, string $authorName, string $comment)
    {
        $this->authorLink = $authorLink;
        $this->authorName = $authorName;
        $this->comment = $comment;
    }

    public static function fromArray(array $data)
    {
        return new static(
            new Link($data['authorLink']),
            $data['authorName'],
            $data['comment']
        );
    }

    /**
     * @return Link
     */
    public function getAuthorLink(): Link
    {
        return $this->authorLink;
    }

    /**
     * @return string
     */
    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    /**
     * @return string
     */
    public function getComment(): string
    {
        return $this->comment;
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
            'authorLink' => $this->authorLink,
            'authorName' => $this->authorName,
            'comment'    => $this->comment,
        ];
    }
}
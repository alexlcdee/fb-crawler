<?php

namespace App\Entities;


class Post implements \JsonSerializable
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
    private $content;
    /**
     * @var array
     */
    private $comments;
    /**
     * @var array
     */
    private $likes;

    public function __construct(Link $authorLink, string $authorName, string $content, array $comments, array $likes)
    {
        $this->authorLink = $authorLink;
        $this->authorName = $authorName;
        $this->content = $content;
        $this->comments = $comments;
        $this->likes = $likes;
    }

    public static function fromArray(array $data)
    {
        return new static(
            new Link($data['authorLink']),
            $data['authorName'],
            $data['content'],
            array_map(function ($comment) {
                return Comment::fromArray($comment);
            }, $data['comments']),
            array_map(function ($like) {
                return Like::fromArray($like);
            }, $data['likes'])
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
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return Comment[]
     */
    public function getComments(): array
    {
        return $this->comments;
    }

    /**
     * @return array
     */
    public function getLikes(): array
    {
        return $this->likes;
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
            'content'    => $this->content,
            'comments'   => $this->comments,
            'likes'      => $this->likes,
        ];
    }
}
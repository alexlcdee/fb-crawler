<?php

namespace App\Entities;


class Analytics
{
    /**
     * @var Friend
     */
    private $friend;
    /**
     * @var Post[]
     */
    private $posts;

    private $reactedPosts = null;

    public function __construct(Friend $friend, array $posts)
    {
        $this->friend = $friend;
        $this->posts = $posts;
    }

    public function getFriend()
    {
        return $this->friend;
    }

    public function getPosts()
    {
        return $this->posts;
    }

    public function isBot()
    {
        return count($this->posts) > 0 && count($this->getReactedPosts()) === 0;
    }

    public function getReactedPosts()
    {
        if ($this->reactedPosts === null) {
            $this->reactedPosts = array_filter($this->posts, function (Post $post) {
                return $post->hasReactionFrom((string)$this->friend->getUserUrl());
            });
        }

        return $this->reactedPosts;
    }
}
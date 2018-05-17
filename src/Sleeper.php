<?php

namespace App;


class Sleeper
{
    public static function sleep($duration)
    {
        sleep($duration);
    }

    public static function sleepRandom($min, $max)
    {
        sleep(mt_rand($min, $max));
    }
}
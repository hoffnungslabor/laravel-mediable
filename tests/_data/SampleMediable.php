<?php

use Jenssegers\Mongodb\Eloquent\Model;
use Plank\Mediable\Mediable;

class SampleMediable extends Model
{
    use Mediable;

    public $rehydrates_media = true;
}

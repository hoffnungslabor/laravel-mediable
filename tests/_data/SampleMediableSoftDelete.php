<?php

use Illuminate\Database\Eloquent\SoftDeletes;
use Jenssegers\Mongodb\Eloquent\Model;
use Plank\Mediable\Mediable;

class SampleMediableSoftDelete extends Model
{
    use Mediable;
    use SoftDeletes;

    public $rehydrates_media = true;
    protected $table = 'sample_mediables';
}

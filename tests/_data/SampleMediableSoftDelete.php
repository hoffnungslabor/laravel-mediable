<?php

use Illuminate\Database\Eloquent\SoftDeletes;
use Jenssegers\Mongodb\Eloquent\Model;
use Plank\Mediable\Mediable;

class SampleMediableSoftDelete extends Model
{
    use Mediable;
    use SoftDeletes;

    protected $table = 'sample_mediables';
    public $rehydrates_media = true;
}

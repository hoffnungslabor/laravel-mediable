<?php

/* @var \Illuminate\Database\Eloquent\Factory $factory */

use Plank\Mediable\Media;

$factory->define(Media::class, static function (Faker\Generator $faker) {
    $types = config('mediable.aggregate_types');
    $type = $faker->randomElement(array_keys($types));

    return [
        'disk' => 'tmp',
        'directory' => implode('/', $faker->words($faker->randomDigit)),
        'filename' => $faker->word,
        'extension' => $faker->randomElement($types[$type]['extensions']),
        'mime_type' => $faker->randomElement($types[$type]['mime_types']),
        'aggregate_type' => $type,
        'size' => $faker->randomNumber(),
    ];
});

$factory->define(MediaSoftDelete::class, static function (Faker\Generator $faker) {
    $types = config('mediable.aggregate_types');
    $type = $faker->randomElement(array_keys($types));

    return [
        'disk' => 'tmp',
        'directory' => implode('/', $faker->words($faker->randomDigit)),
        'filename' => $faker->word,
        'extension' => $faker->randomElement($types[$type]['extensions']),
        'mime_type' => $faker->randomElement($types[$type]['mime_types']),
        'aggregate_type' => $type,
        'size' => $faker->randomNumber(),
    ];
});

$factory->define(SampleMediable::class, static function (Faker\Generator $faker) {
    return [];
});

$factory->define(SampleMediableSoftDelete::class, static function (Faker\Generator $faker) {
    return [];
});

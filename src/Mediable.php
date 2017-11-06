<?php

namespace Plank\Mediable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Jenssegers\Mongodb\Eloquent\Model;

/**
 * Mediable Trait.
 *
 * Provides functionality for attaching media to an eloquent model.
 *
 * @author Sean Fraser <sean@plankdesign.com>
 *
 * Whether the model should automatically reload its media relationship after modification.
 */
trait Mediable
{
    /**
     * List of media tags that have been modified since last load.
     * @var array
     */
    private $media_dirty_tags = [];

    /**
     * Boot the Mediable trait.
     *
     * @return void
     */
    public static function bootMediable()
    {
        static::deleted(function (Model $model) {
            $model->handleMediableDeletion();
        });
    }

    /**
     * Relationship for all attached media.
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function media()
    {
        return $this->morphMany(config('mediable.model'), 'mediable');
    }

    /**
     * Query scope to detect the presence of one or more attached media for a given tag.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  string|array $tags
     * @param  bool $match_all
     * @return void
     */
    public function scopeWhereHasMedia(Builder $q, $tags, $match_all = false)
    {
        // whereHas is not supported on MorphMany
        throw new \Exception("Not supported anymore");

        /*
        if ($match_all && is_array($tags) && count($tags) > 1) {
            return $this->scopeWhereHasMediaMatchAll($q, $tags);
        }
        $q->whereHas('media', function (Builder $q) use ($tags) {
            $q->whereIn('tags', (array) $tags);
        });
        */
    }

    /**
     * Query scope to detect the presence of one or more attached media that is bound to all of the specified tags simultaneously.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  array $tags
     * @return void
     */
    public function scopeWhereHasMediaMatchAll(Builder $q, array $tags)
    {
        // whereHas is not supported on MorphMany
        throw new \Exception("Not supported anymore");

        /*
        $q->whereHas('media', function (Builder $q) use ($tags) {
            $q->where('tags', 'all', (array) $tags);
        });
        */
    }

    /**
     * Query scope to eager load attached media.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  string|array $tags If one or more tags are specified, only media attached to those tags will be loaded.
     * @param  bool $match_all Only load media matching all provided tags
     * @return void
     */
    public function scopeWithMedia(Builder $q, $tags = [], $match_all = false)
    {
        $tags = (array)$tags;

        if (empty($tags)) {
            return $q->with('media');
        }

        if ($match_all) {
            return $q->withMediaMatchAll($tags);
        }

        $q->with(['media' => function (MorphMany $q) use ($tags) {
            $q->whereIn('tags', $tags);
        }]);
    }

    /**
     * Query scope to eager load attached media assigned to multiple tags.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  string|array $tags
     * @return void
     */
    public function scopeWithMediaMatchAll(Builder $q, $tags = [])
    {
        $tags = (array)$tags;
        $q->with(['media' => function (MorphMany $q) use ($tags) {
            $this->addMatchAllToEagerLoadQuery($q, $tags);
        }]);
    }

    /**
     * Lazy eager load attached media relationships.
     * @param  string|array $tags If one or more tags are specified, only media attached to those tags will be loaded.
     * @param  bool $match_all Only load media matching all provided tags
     * @return $this
     */
    public function loadMedia($tags = [], $match_all = false)
    {
        $tags = (array)$tags;

        if (empty($tags)) {
            return $this->load('media');
        }

        if ($match_all) {
            return $this->loadMediaMatchAll($tags);
        }

        $this->load(['media' => function (MorphMany $q) use ($tags) {
            $q->whereIn('tags', $tags);
        }]);

        return $this;
    }

    /**
     * Lazy eager load attached media relationships matching all provided tags.
     * @param  string|array $tags one or more tags
     * @return $this
     */
    public function loadMediaMatchAll($tags = [])
    {
        $tags = (array)$tags;
        $this->load(['media' => function (MorphMany $q) use ($tags) {
            $this->addMatchAllToEagerLoadQuery($q, $tags);
        }]);

        return $this;
    }

    /**
     * Attach a media entity to the model with one or more tags.
     * @param mixed $media an instance of `Media` or an instance of `\Illuminate\Database\Eloquent\Collection`
     * @param string|array $tags One or more tags to define the relation
     * @return void
     */
    public function attachMedia($media, $tags)
    {
        $tags = (array)$tags;

        if (is_array($media)) {
            $media = collect($media);
        }
        if ($media instanceof \Illuminate\Support\Collection) {
            $media = $media->map(function ($medium) use ($tags) {
                if (!($medium instanceof Media)) {
                    $medium = Media::find($medium);
                } elseif ($this->rehydratesMedia()) {
                    $medium = Media::find($medium->id) ?: $medium;
                }
                $medium->addTags($tags);
                return $medium;
            });
            $this->media()->saveMany($media);
        } else {
            if ($this->rehydratesMedia()) {
                $media = Media::find($media->id) ?: $media;
            }
            $media->addTags($tags);
            $this->media()->save($media);
            $media->save();
        }

        $this->markMediaDirty($tags);
    }

    /**
     * Replace the existing media collection for the specified tag(s).
     * @param mixed $media
     * @param string|array $tags
     * @return void
     */
    public function syncMedia($media, $tags)
    {
        $this->detachMediaTags($tags);
        $this->attachMedia($media, $tags);
    }

    /**
     * Detach a media item from the model.
     * @param  mixed $media
     * @param  string|array|null $tags
     * If provided, will remove the media from the model for the provided tag(s) only
     * If omitted, will remove the media from the media for all tags
     * @return void
     */
    public function detachMedia($media, $tags = null)
    {
        if ($tags === null) {
            $media->tags = [];
        } else {
            $media->removeTags((array)$tags);
        }
        $media->save();

        /*
        $media->removeTags($tags);
        if ($tags === null || empty($media->tags)) {
            $media->delete();
        } else {
            $media->save();
        }
        */

        $this->markMediaDirty($tags);
    }

    /**
     * Remove one or more tags from the model, detaching any media using those tags.
     * @param  string|array $tags
     * @return void
     */
    public function detachMediaTags($tags)
    {
        $media = $this->media()->whereIn('tags', (array)$tags)->get();
        foreach ($media as $medium) {
            /** @var Media $medium */
            $medium->removeTags((array)$tags);
            /*
            if (empty($medium->tags)) {
                $medium->delete();
            } else {
                $medium->save();
            }
            */
            $medium->save();
        }
        $this->markMediaDirty($tags);
    }

    /**
     * Check if the model has any media attached to one or more tags.
     * @param  string|array $tags
     * @param  bool $match_all
     * If false, will return true if the model has any attach media for any of the provided tags
     * If true, will return true is the model has any media that are attached to all of provided tags simultaneously
     * @return bool
     */
    public function hasMedia($tags, $match_all = false)
    {
        return count($this->getMedia($tags, $match_all)) > 0;
    }

    /**
     * Retrieve media attached to the model.
     * @param  string|array $tags
     * @param  bool $match_all
     * If false, will return media attached to any of the provided tags
     * If true, will return media attached to all of the provided tags simultaneously
     * @return array
     */
    public function getMedia($tags, $match_all = false)
    {
        if ($match_all) {
            return $this->getMediaMatchAll($tags);
        }

        $this->rehydrateMediaIfNecessary($tags);

        return $this->media
            //exclude media not matching at least one tag
            ->filter(function (Media $media) use ($tags) {
                $intersection = array_intersect($media->tags, (array)$tags);
                return !empty($intersection);
            });
    }

    /**
     * Retrieve media attached to multiple tags simultaneously.
     * @param array $tags
     * @return array
     */
    public function getMediaMatchAll(array $tags)
    {
        $this->rehydrateMediaIfNecessary($tags);

        return $this->media()->where('tags', 'all', $tags)->get();
    }

    /**
     * Shorthand for retrieving the first attached media item.
     * @param  string|array $tags
     * @param  bool $match_all
     * @see \Plank\Mediable\Mediable::getMedia()
     * @return bool
     */
    public function firstMedia($tags, $match_all = false)
    {
        return $this->getMedia($tags, $match_all)->first();
    }

    /**
     * Shorthand for retrieving the last attached media item.
     * @param  string|array  $tags
     * @param  bool         $match_all
     * @see \Plank\Mediable\Mediable::getMedia()
     * @return bool
     */
    public function lastMedia($tags, $match_all = false)
    {
        return $this->getMedia($tags, $match_all)->last();
    }

    /**
     * Retrieve all media grouped by tag name.
     * @return \Illuminate\Support\Collection
     */
    public function getAllMediaByTag()
    {
        $this->rehydrateMediaIfNecessary();

        $result = collect();
        foreach ($this->media as $medium) {
            foreach ($medium->tags as $tag) {
                $coll = $result->get($tag);
                if (!$coll) {
                    $coll = collect();
                    $result->put($tag, $coll);
                }
                $coll->push($medium);
            }
        }
        return $result;
    }

    /**
     * Get a list of all tags that the media is attached to.
     * @param  \Plank\Mediable\Media $media
     * @return array
     */
    public function getTagsForMedia(Media $media)
    {
        $this->rehydrateMediaIfNecessary();

        if ($this->rehydratesMedia()) {
            $media = Media::find($media->id) ?: $media;
        }

        return $media->tags ?: [];
    }

    /**
     * Indicate that the media attached to the provided tags has been modified.
     * @param  string|array $tags
     * @return void
     */
    protected function markMediaDirty($tags)
    {
        foreach ((array) $tags as $tag) {
            $this->media_dirty_tags[$tag] = $tag;
        }
    }

    /**
     * Check if media attached to the specified tags has been modified.
     * @param  null|string|array $tags
     * If omitted, will return `true` if any tags have been modified
     * @return bool
     */
    protected function mediaIsDirty($tags = null)
    {
        if (is_null($tags)) {
            return count($this->media_dirty_tags);
        } else {
            return count(array_intersect((array)$tags, $this->media_dirty_tags));
        }
    }

    /**
     * Reloads media relationship if allowed and necessary.
     * @param  null|string|array $tags
     * @return void
     */
    protected function rehydrateMediaIfNecessary($tags = null)
    {
        if ($this->rehydratesMedia() && $this->mediaIsDirty($tags)) {
            $this->loadMedia();
        }
    }

    /**
     * Check whether the model is allowed to automatically reload media relationship.
     *
     * Can be overridden by setting protected property `$rehydrates_media` on the model.
     * @return bool
     */
    protected function rehydratesMedia()
    {
        if (property_exists($this, 'rehydrates_media')) {
            return $this->rehydrates_media;
        }

        return config('mediable.rehydrate_media', true);
    }

    /**
     * Generate a query builder for.
     * @param  array|string $tags
     * @return Builder
     */
    protected function newMatchAllQuery($tags = [])
    {
        return $this->media()->where('tags', 'all', (array)$tags);
    }

    /**
     * Modify an eager load query to only load media assigned to all provided tags simultaneously.
     * @param  \Illuminate\Database\Eloquent\Relations\MorphToMany $q
     * @param  array|string $tags
     * @return void
     */
    protected function addMatchAllToEagerLoadQuery(MorphMany $q, $tags = [])
    {
        $q->where('tags', 'all', (array)$tags);
    }

    /**
    * Determine whether media relationships should be detached when the model is deleted or soft deleted.
    * @return void
    */
    protected function handleMediableDeletion()
    {
        // only cascade soft deletes when configured
        if (static::hasGlobalScope(SoftDeletingScope::class) && !$this->forceDeleting) {
            if (config('mediable.detach_on_soft_delete')) {
                $this->media()->each(function ($medium) {
                    $medium->delete();
                });
            }
            // always cascade for hard deletes
        } else {
            $this->media()->each(function ($medium) {
                $medium->delete();
            });
        }
    }

    /**
     * Determine the highest order value assigned to each provided tag.
     * @param  string|array $tags
     * @return int
     * @throws \Exception
     */
    private function getOrderValueForTags($tags)
    {
        throw new \Exception("Not supported anymore");
    }

    /**
     * Convert mixed input to array of ids.
     * @param  mixed $input
     * @return array
     */
    private function extractIds($input)
    {
        if ($input instanceof Collection) {
            return $input->modelKeys();
        }

        if ($input instanceof Media) {
            return [$input->getKey()];
        }

        return (array) $input;
    }

    /**
     * {@inheritdoc}
     */
    public function load($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        if (array_key_exists('media', $relations) || in_array('media', $relations)) {
            $this->media_dirty_tags = [];
        }

        return parent::load($relations);
    }

    /**
     * {@inheritdoc}
     * @return \Plank\Mediable\MediableCollection
     */
    public function newCollection(array $models = [])
    {
        return new MediableCollection($models);
    }

    /**
     * Key the name of the foreign key field of the media relation
     *
     * Accounts for the change of method name in Laravel 5.4
     * @return string
     * @throws \Exception
     */
    private function mediaQualifiedForeignKey()
    {
        throw new \Exception("Not supported anymore");
    }

    /**
     * Key the name of the related key field of the media relation
     *
     * Accounts for the change of method name in Laravel 5.4
     * @return string
     * @throws \Exception
     */
    private function mediaQualifiedRelatedKey()
    {
        throw new \Exception("Not supported anymore");
    }

    /**
     * perform a WHERE IN on the pivot table's tags column
     *
     * Adds support for Laravel <= 5.2, which does not provide a `wherePivotIn()` method
     * @param  MorphToMany $q
     * @param  array $tags
     * @throws \Exception
     */
    private function wherePivotTagIn(MorphToMany $q, $tags = [])
    {
        throw new \Exception("Not supported anymore");
    }
}

<?php
declare(strict_types=1);

namespace Plank\Mediable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Jenssegers\Mongodb\Relations\MorphMany;

/**
 * Mediable Trait.
 *
 * Provides functionality for attaching media to an eloquent model.
 * Whether the model should automatically reload its media relationship after modification.
 *
 * @property MediableCollection $media
 * @property Pivot $pivot
 * @method static Builder withMedia($tags = [], bool $matchAll = false)
 * @method static Builder withMediaMatchAll($tags = [])
 * @method static Builder whereHasMedia($tags, bool $matchAll = false)
 * @method static Builder whereHasMediaMatchAll($tags)
 *
 */
trait Mediable
{
    /**
     * List of media tags that have been modified since last load.
     * @var string[]
     */
    private $mediaDirtyTags = [];

    /**
     * Boot the Mediable trait.
     *
     * @return void
     */
    public static function bootMediable(): void
    {
        static::deleted(static function (self $model) {
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
     * @param  Builder $q
     * @param  string|string[] $tags
     * @param  bool $matchAll
     * @return void
     */
    public function scopeWhereHasMedia(Builder $q, $tags, bool $matchAll = false): void
    {
        throw new \Exception('Not supported with mongodb');

        /*
        if ($matchAll && is_array($tags) && count($tags) > 1) {
            $this->scopeWhereHasMediaMatchAll($q, $tags);
            return;
        }
        $q->whereHas('media', function (Builder $q) use ($tags) {
            $q->whereIn('tag', (array)$tags);
        });
        */
    }

    /**
     * Query scope to detect the presence of one or more attached media that is bound to all of the specified tags simultaneously.
     * @param  Builder $q
     * @param  string|string[] $tags
     * @return void
     */
    public function scopeWhereHasMediaMatchAll(Builder $q, array $tags): void
    {
        throw new \Exception('Not supported with mongodb');

        /*
        $grammar = $q->getQuery()->getGrammar();
        $subquery = $this->newMatchAllQuery($tags)
            ->selectRaw('count(*)')
            ->whereRaw(
                $grammar->wrap($this->media()->getQualifiedForeignPivotKeyName())
                . ' = ' . $grammar->wrap($this->getQualifiedKeyName())
            );
        $q->whereRaw('(' . $subquery->toSql() . ') >= 1', $subquery->getBindings());
        */
    }

    /**
     * Query scope to eager load attached media.
     *
     * @param  Builder|Mediable $q
     * @param  string|string[] $tags If one or more tags are specified, only media attached to those tags will be loaded.
     * @param  bool $matchAll Only load media matching all provided tags
     * @return void
     */
    public function scopeWithMedia(Builder $q, $tags = [], bool $matchAll = false): void
    {
        $tags = (array)$tags;

        if (empty($tags)) {
            $q->with('media');
            return;
        }

        if ($matchAll) {
            $q->withMediaMatchAll($tags);
            return;
        }

        $q->with([
            'media' => function (MorphMany $q) use ($tags) {
                $q->whereIn('tags', $tags);
            }
        ]);
    }

    /**
     * Query scope to eager load attached media assigned to multiple tags.
     * @param  Builder $q
     * @param  string|string[] $tags
     * @return void
     */
    public function scopeWithMediaMatchAll(Builder $q, $tags = []): void
    {
        $tags = (array)$tags;
        $q->with([
            'media' => function (MorphMany $q) use ($tags) {
                $this->addMatchAllToEagerLoadQuery($q, $tags);
            }
        ]);
    }

    /**
     * Lazy eager load attached media relationships.
     * @param  string|string[] $tags If one or more tags are specified, only media attached to those tags will be loaded.
     * @param  bool $matchAll Only load media matching all provided tags
     * @return $this
     */
    public function loadMedia($tags = [], bool $matchAll = false): self
    {
        $tags = (array)$tags;

        if (empty($tags)) {
            return $this->load('media');
        }

        if ($matchAll) {
            return $this->loadMediaMatchAll($tags);
        }

        $this->load([
            'media' => function (MorphMany $q) use ($tags) {
                $q->whereIn('tags', $tags);
            }
        ]);

        return $this;
    }

    /**
     * Lazy eager load attached media relationships matching all provided tags.
     * @param  string|string[] $tags one or more tags
     * @return $this
     */
    public function loadMediaMatchAll($tags = []): self
    {
        $tags = (array)$tags;
        $this->load([
            'media' => function (MorphMany $q) use ($tags) {
                $this->addMatchAllToEagerLoadQuery($q, $tags);
            }
        ]);

        return $this;
    }

    /**
     * Attach a media entity to the model with one or more tags.
     * @param string|int|Media|Collection $media Either a string or numeric id, an array of ids, an instance of `Media` or an instance of `Collection`
     * @param string|string[] $tags One or more tags to define the relation
     * @return void
     */
    public function attachMedia($media, $tags): void
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
     * @param string|int|Media|Collection $media
     * @param string|string[] $tags
     * @return void
     */
    public function syncMedia($media, $tags): void
    {
        $this->detachMediaTags($tags);
        $this->attachMedia($media, $tags);
    }

    /**
     * Detach a media item from the model.
     * @param  string|int|Media|Collection $media
     * @param  string|string[]|null $tags
     * If provided, will remove the media from the model for the provided tag(s) only
     * If omitted, will remove the media from the media for all tags
     * @return void
     */
    public function detachMedia($media, $tags = null): void
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
     * @param  string|string[] $tags
     * @return void
     */
    public function detachMediaTags($tags): void
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
     * @param  string|string[] $tags
     * @param  bool $matchAll
     * If false, will return true if the model has any attach media for any of the provided tags
     * If true, will return true is the model has any media that are attached to all of provided tags simultaneously
     * @return bool
     */
    public function hasMedia($tags, bool $matchAll = false): bool
    {
        return count($this->getMedia($tags, $matchAll)) > 0;
    }

    /**
     * Retrieve media attached to the model.
     * @param  string|string[] $tags
     * @param  bool $matchAll
     * If false, will return media attached to any of the provided tags
     * If true, will return media attached to all of the provided tags simultaneously
     * @return Collection|Media[]
     */
    public function getMedia($tags, bool $matchAll = false): Collection
    {
        if ($matchAll) {
            return $this->getMediaMatchAll($tags);
        }

        $this->rehydrateMediaIfNecessary($tags);

        return $this->media
            //exclude media not matching at least one tag
            ->filter(static function (Media $media) use ($tags) {
                $intersection = array_intersect($media->tags, (array)$tags);
                return !empty($intersection);
            })->keyBy(static function (Media $media) {
                return $media->getKey();
            })->values();
    }

    /**
     * Retrieve media attached to multiple tags simultaneously.
     * @param string[] $tags
     * @return Collection|Media[]
     */
    public function getMediaMatchAll(array $tags): Collection
    {
        $this->rehydrateMediaIfNecessary($tags);

        return $this->media()->where('tags', 'all', $tags)->get();
    }

    /**
     * Shorthand for retrieving the first attached media item.
     * @param  string|string[] $tags
     * @param  bool $matchAll
     * @see \Plank\Mediable\Mediable::getMedia()
     * @return Media|null
     */
    public function firstMedia($tags, bool $matchAll = false): ?Media
    {
        return $this->getMedia($tags, $matchAll)->first();
    }

    /**
     * Shorthand for retrieving the last attached media item.
     * @param  string|string[] $tags
     * @param  bool $matchAll
     * @see \Plank\Mediable\Mediable::getMedia()
     * @return Media|null
     */
    public function lastMedia($tags, $matchAll = false): ?Media
    {
        return $this->getMedia($tags, $matchAll)->last();
    }

    /**
     * Retrieve all media grouped by tag name.
     * @return \Illuminate\Support\Collection|Media[]
     */
    public function getAllMediaByTag(): \Illuminate\Support\Collection
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
     * @param  Media $media
     * @return string[]
     */
    public function getTagsForMedia(Media $media): array
    {
        $this->rehydrateMediaIfNecessary();

        if ($this->rehydratesMedia()) {
            $media = Media::find($media->id) ?: $media;
        }

        return $media->tags ?: [];
    }

    /**
     * Indicate that the media attached to the provided tags has been modified.
     * @param  string|string[] $tags
     * @return void
     */
    protected function markMediaDirty($tags): void
    {
        foreach ((array)$tags as $tag) {
            $this->mediaDirtyTags[$tag] = $tag;
        }
    }

    /**
     * Check if media attached to the specified tags has been modified.
     * @param  null|string|string[] $tags
     * If omitted, will return `true` if any tags have been modified
     * @return bool
     */
    protected function mediaIsDirty($tags = null): bool
    {
        if (is_null($tags)) {
            return count($this->mediaDirtyTags) > 0;
        } else {
            return count(array_intersect((array)$tags, $this->mediaDirtyTags)) > 0;
        }
    }

    /**
     * Reloads media relationship if allowed and necessary.
     * @param  null|string|string[] $tags
     * @return void
     */
    protected function rehydrateMediaIfNecessary($tags = null): void
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
    protected function rehydratesMedia(): bool
    {
        if (property_exists($this, 'rehydrates_media')) {
            return $this->rehydrates_media;
        }

        return (bool)config('mediable.rehydrate_media', true);
    }

    /**
     * Generate a query builder for.
     * @param  string|string[] $tags
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newMatchAllQuery($tags = []): \Illuminate\Database\Query\Builder
    {
        $tags = (array)$tags;
        return $this->media()->where('tags', 'all', $tags);
    }

    /**
     * Modify an eager load query to only load media assigned to all provided tags simultaneously.
     * @param  \Illuminate\Database\Eloquent\Relations\MorphMany $q
     * @param  string|string[] $tags
     * @return void
     */
    protected function addMatchAllToEagerLoadQuery(MorphMany $q, $tags = []): void
    {
        $tags = (array)$tags;
        $q->where('tags', 'all', $tags);
    }

    /**
     * Determine whether media relationships should be detached when the model is deleted or soft deleted.
     * @return void
     */
    protected function handleMediableDeletion(): void
    {
        // only cascade soft deletes when configured
        if (static::hasGlobalScope(SoftDeletingScope::class) && !$this->forceDeleting) {
            if (config('mediable.detach_on_soft_delete')) {
                $this->media()->each(static function ($medium) {
                    $medium->delete();
                });
            }
            // always cascade for hard deletes
        } else {
            $this->media()->each(static function ($medium) {
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
        throw new \Exception('Not supported with mongodb');
    }

    /**
     * Convert mixed input to array of ids.
     * @param  mixed $input
     * @return int[]|string[]
     */
    private function extractPrimaryIds($input): array
    {
        if ($input instanceof Collection) {
            return $input->modelKeys();
        }

        if ($input instanceof Media) {
            return [$input->getKey()];
        }

        return (array)$input;
    }

    /**
     * {@inheritdoc}
     */
    public function load($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        if (array_key_exists('media', $relations)
            || in_array('media', $relations)
        ) {
            $this->mediaDirtyTags = [];
        }

        return parent::load($relations);
    }

    /**
     * {@inheritdoc}
     * @return MediableCollection
     */
    public function newCollection(array $models = [])
    {
        return new MediableCollection($models);
    }
}

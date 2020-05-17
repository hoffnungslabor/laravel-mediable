<?php
declare(strict_types=1);

namespace Plank\Mediable;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Jenssegers\Mongodb\Relations\MorphMany;

/**
 * Collection of Mediable Models.
 */
class MediableCollection extends Collection
{
    /**
     * Lazy eager load media attached to items in the collection.
     * @param  string|string[] $tags
     * If one or more tags are specified, only media attached to those tags will be loaded.
     * @param bool $match_all If true, only load media attached to all tags simultaneously
     * @return $this
     */
    public function loadMedia($tags = [], bool $match_all = false): self
    {
        $tags = (array)$tags;

        if (empty($tags)) {
            return $this->load('media');
        }

        if ($match_all) {
            return $this->loadMediaMatchAll($tags);
        }

        $closure = function (MorphMany $q) use ($tags) {
            $q->whereIn('tags', $tags);
        };

        return $this->load(['media' => $closure]);
    }

    /**
     * Lazy eager load media attached to items in the collection bound all of the provided tags simultaneously.
     * @param  string|string[] $tags
     * If one or more tags are specified, only media attached to those tags will be loaded.
     * @return $this
     */
    public function loadMediaMatchAll($tags = []): self
    {
        $tags = (array)$tags;
        $closure = function (MorphMany $q) use ($tags) {
            $this->addMatchAllToEagerLoadQuery($q, $tags);
        };
        $closure = Closure::bind($closure, $this->first(), $this->first());

        return $this->load(['media' => $closure]);
    }

    public function delete(): void
    {
        if (count($this) == 0) {
            return;
        }

        throw new \Exception('Not supported with mongodb');
    }
}

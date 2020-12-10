<?php

namespace Venturecraft\Revisionable\Traits;

trait ExtendFireModelEventTrait
{
    /**
     * Fire the given event for the model.
     *
     * @param  string  $event
     * @param  bool  $halt
     */
    public function fireModelEvent($event, $halt = true, ...$additionalData)
    {
        if (!isset(static::$dispatcher)) {
            return true;
        }

        // First, we will get the proper method to call on the event dispatcher, and then we
        // will attempt to fire a custom, object based event for the given event. If that
        // returns a result we can return that result, or we'll call the string events.
        $method = $halt ? 'until' : 'dispatch';

        $result = $this->filterModelEventResults(
            $this->fireCustomModelEvent($event, $method)
        );

        if ($result === false) {
            return false;
        }

        $payload = [$this, ...$additionalData];

        return !empty($result) ? $result : static::$dispatcher->{$method}(
            "eloquent.{$event}: " . static::class,
            $payload
        );
    }
}

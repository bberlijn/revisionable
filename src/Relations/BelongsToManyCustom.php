<?php

namespace Venturecraft\Revisionable\Relations;

use Venturecraft\Revisionable\Traits\FiresPivotEventsTrait;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BelongsToManyCustom extends BelongsToMany
{
    use FiresPivotEventsTrait;
}

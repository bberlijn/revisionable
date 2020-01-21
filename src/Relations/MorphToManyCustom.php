<?php

namespace Venturecraft\Revisionable\Relations;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Venturecraft\Revisionable\Traits\FiresPivotEventsTrait;

class MorphToManyCustom extends MorphToMany
{
    use FiresPivotEventsTrait;
}

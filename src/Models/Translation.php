<?php

namespace Karpack\Translations\Models;

use Illuminate\Database\Eloquent\Model;

class Translation extends Model
{
    /**
     * Returns the model to which this translation belongs to.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function translatable()
    {
        return $this->morphTo();
    }
}
<?php namespace Venturecraft\Revisionable;

/*
 * This file is part of the Revisionable package by Venture Craft
 *
 * (c) Venture Craft <http://www.venturecraft.com.au>
 *
 */

trait RevisionableTrait
{
    private $originalData;
    private $updatedData;
    private $updating;
    private $dontKeep = array();
    private $doKeep = array();

    /**
     * Keeps the list of values that have been updated
     *
     * @var array
     */
    protected $dirtyData = array();

    /**
     * Store model creation by default
     *
     * @var boolean
     */
    protected $revisionCreationsEnabled = true;

    /**
     * Create the event listeners for the saving and saved events
     * This lets us save revisions whenever a save is made, no matter the
     * http method.
     *
     */
    public static function bootRevisionableTrait()
    {
        static::saving(function ($model) {
            $model->preSave();
        });

        static::saved(function ($model) {
            $model->postSave();
        });

        static::deleted(function ($model) {
            $model->preSave();
            $model->postDelete();
        });

        static::created(function ($model) {
            $model->postCreate();
        });
    }

    public function revisionHistory()
    {
        return $this->morphMany('\Venturecraft\Revisionable\Revision', 'revisionable');
    }

    /**
     * Invoked before a model is saved. Return false to abort the operation.
     *
     * @return bool
     */
    public function preSave()
    {
        if (!isset($this->revisionEnabled) || $this->revisionEnabled) {
            // if there's no revisionEnabled. Or if there is, if it's true

            $this->originalData = $this->original;
            $this->updatedData  = $this->attributes;

            // we can only safely compare basic items,
            // so for now we drop any object based items, like DateTime
            foreach ($this->updatedData as $key => $val) {
                if (gettype($val) == 'object' && ! method_exists($val, '__toString')) {
                    unset($this->originalData[$key]);
                    unset($this->updatedData[$key]);
                }
            }

            // the below is ugly, for sure, but it's required so we can save the standard model
            // then use the keep / dontkeep values for later, in the isRevisionable method
            $this->dontKeep = isset($this->dontKeepRevisionOf) ?
                array_merge($this->dontKeepRevisionOf, $this->dontKeep)
                : $this->dontKeep;

            $this->doKeep = isset($this->keepRevisionOf) ?
                array_merge($this->keepRevisionOf, $this->doKeep)
                : $this->doKeep;

            unset($this->attributes['dontKeepRevisionOf']);
            unset($this->attributes['keepRevisionOf']);

            $this->dirtyData = $this->getDirty();
            $this->updating = $this->exists;
        }
    }


    /**
     * Called after a model is successfully saved.
     *
     * @return void
     */
    public function postSave()
    {

        // check if the model already exists
        if ((!isset($this->revisionEnabled) || $this->revisionEnabled) && $this->updating) {
            // if it does, it means we're updating

            $changes_to_record = $this->changedRevisionableFields();

            $revisions = array();

            foreach ($changes_to_record as $key => $change) {
                $revisions[] = array(
                    'revisionable_type' => $this->getMorphClass(),
                    'revisionable_id'   => $this->getKey(),
                    'key'               => $key,
                    'old_value'         => array_get($this->originalData, $key),
                    'new_value'         => $this->updatedData[$key],
                    'user_type'         => $this->getUserType(),
                    'user_id'           => $this->getSystemUserId(),
                    'created_at'        => new \DateTime(),
                    'updated_at'        => new \DateTime(),
                );
            }

            if (count($revisions) > 0) {
                $revision = new Revision;
                \DB::table($revision->getTable())->insert($revisions);
                \Event::dispatch('revisionable.saved', array('model' => $this, 'revisions' => $revisions));
            }
        }
    }

    /**
    * Called after record successfully created
    */
    public function postCreate()
    {

        // Check if we should store creations in our revision history
        // Set this value to true in your model if you want to
        if (empty($this->revisionCreationsEnabled)) {
            // We should not store creations.
            return false;
        }

        if ((!isset($this->revisionEnabled) || $this->revisionEnabled)) {
            $revisions[] = array(
                'revisionable_type' => $this->getMorphClass(),
                'revisionable_id'   => $this->getKey(),
                'key'               => self::CREATED_AT,
                'old_value'         => null,
                'new_value'         => $this->{self::CREATED_AT},
                'user_type'         => $this->getUserType(),
                'user_id'           => $this->getSystemUserId(),
                'created_at'        => new \DateTime(),
                'updated_at'        => new \DateTime(),
            );

            $revision = new Revision;
            \DB::table($revision->getTable())->insert($revisions);
            \Event::dispatch('revisionable.created', array('model' => $this, 'revisions' => $revisions));
        }
    }

    /**
     * If softdeletes are enabled, store the deleted time
     */
    public function postDelete()
    {
        if ((!isset($this->revisionEnabled) || $this->revisionEnabled)
            && $this->isSoftDelete()
            && $this->isRevisionable($this->getDeletedAtColumn())
        ) {
            $revisions[] = array(
                'revisionable_type' => $this->getMorphClass(),
                'revisionable_id'   => $this->getKey(),
                'key'               => $this->getDeletedAtColumn(),
                'old_value'         => null,
                'new_value'         => $this->{$this->getDeletedAtColumn()},
                'user_type'         => $this->getUserType(),
                'user_id'           => $this->getSystemUserId(),
                'created_at'        => new \DateTime(),
                'updated_at'        => new \DateTime(),
            );
            $revision = new \Venturecraft\Revisionable\Revision;
            \DB::table($revision->getTable())->insert($revisions);
            \Event::dispatch('revisionable.deleted', array('model' => $this, 'revisions' => $revisions));
        }
    }

    /**
     * Attempt to find the user id of the currently logged in user
     * Supports Cartalyst Sentry/Sentinel based authentication, as well as stock Auth
     * MultiAuth support added
     **/
    public function getSystemUserId()
    {
        try {
            if (!is_null($multi = app('config')->get('auth.multi'))) {
                foreach ($multi as $user_type => $value) {
                    if (\Auth::$user_type()->check()) {
                        return \Auth::$user_type()->get()->getAuthIdentifier();
                    }
                }
            } elseif (class_exists($class = '\Cartalyst\Sentry\Facades\Laravel\Sentry')
                    || class_exists($class = '\Cartalyst\Sentinel\Laravel\Facades\Sentinel')) {
                return ($class::check()) ? $class::getUser()->id : null;
            } elseif (\Auth::guard('user')->check()) {
                return \Auth::guard('user')->user()->getAuthIdentifier();
            } elseif (\Auth::guard('customer')->check()) {
                return \Auth::guard('customer')->user()->getAuthIdentifier();
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Attempt to find the user type of the currently logged in user
     * Supports Cartalyst Sentry/Sentinel based authentication, as well as stock Auth
     * MultiAuth support added
     **/
    private function getUserType()
    {
        try {
            if (!is_null($multi = app('config')->get('auth.multi'))) {
                foreach ($multi as $user_type => $value) {
                    if (\Auth::$user_type()->check()) {
                        return $user_type;
                    }
                }
            } elseif (class_exists($class = '\Cartalyst\Sentry\Facades\Laravel\Sentry')
                    || class_exists($class = '\Cartalyst\Sentinel\Laravel\Facades\Sentinel')) {
                return ($class::check()) ? $class::getUser()->id : null;
            } elseif (\Auth::guard('user')->check()) {
                return get_class(\Auth::guard('user')->user());
            } elseif (\Auth::guard('customer')->check()) {
                return get_class(\Auth::guard('customer')->user());
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Get all of the changes that have been made, that are also supposed
     * to have their changes recorded
     *
     * @return array fields with new data, that should be recorded
     */
    private function changedRevisionableFields()
    {
        $changes_to_record = array();
        foreach ($this->dirtyData as $key => $value) {
            // check that the field is revisionable, and double check
            // that it's actually new data in case dirty is, well, clean
            if ($this->isRevisionable($key) && !is_array($value)) {
                if (!array_key_exists($key, $this->originalData) || $this->originalData[$key] != $this->updatedData[$key]) {
                    $changes_to_record[$key] = $value;
                }
            } else {
                // we don't need these any more, and they could
                // contain a lot of data, so lets trash them.
                unset($this->updatedData[$key]);
                unset($this->originalData[$key]);
            }
        }

        return $changes_to_record;
    }

    /**
     * Check if this field should have a revision kept
     *
     * @param string $key
     *
     * @return bool
     */
    private function isRevisionable($key)
    {

        // If the field is explicitly revisionable, then return true.
        // If it's explicitly not revisionable, return false.
        // Otherwise, if neither condition is met, only return true if
        // we aren't specifying revisionable fields.
        if (isset($this->doKeep) && in_array($key, $this->doKeep)) {
            return true;
        }
        if (isset($this->dontKeep) && in_array($key, $this->dontKeep)) {
            return false;
        }
        return empty($this->doKeep);
    }

    /**
     * Check if soft deletes are currently enabled on this model
     *
     * @return bool
     */
    private function isSoftDelete()
    {
        // check flag variable used in laravel 4.2+
        if (isset($this->forceDeleting)) {
            return !$this->forceDeleting;
        }

        // otherwise, look for flag used in older versions
        if (isset($this->softDelete)) {
            return $this->softDelete;
        }

        return false;
    }

    public function getRevisionFormattedFields()
    {
        return $this->revisionFormattedFields;
    }

    public function getRevisionFormattedFieldNames()
    {
        return $this->revisionFormattedFieldNames;
    }

    /**
     * Identifiable Name
     * When displaying revision history, when a foreigh key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function identifiableName()
    {
        return $this->getKey();
    }

    /**
     * Revision Unknown String
     * When displaying revision history, when a foreigh key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function getRevisionNullString()
    {
        return isset($this->revisionNullString)?$this->revisionNullString:'nothing';
    }

    /**
     * No revision string
     * When displaying revision history, if the revisions value
     * cant be figured out, this is used instead.
     * It can be overridden.
     * @return string an identifying name for the model
     */
    public function getRevisionUnknownString()
    {
        return isset($this->revisionUnknownString)?$this->revisionUnknownString:'unknown';
    }

    /**
     * Disable a revisionable field temporarily
     * Need to do the adding to array longhanded, as there's a
     * PHP bug https://bugs.php.net/bug.php?id=42030
     *
     * @param mixed $field
     *
     * @return void
     */
    public function disableRevisionField($field)
    {
        if (!isset($this->dontKeepRevisionOf)) {
            $this->dontKeepRevisionOf = array();
        }
        if (is_array($field)) {
            foreach ($field as $one_field) {
                $this->disableRevisionField($one_field);
            }
        } else {
            $donts = $this->dontKeepRevisionOf;
            $donts[] = $field;
            $this->dontKeepRevisionOf = $donts;
            unset($donts);
        }
    }

    public function wasCreatedBySystem()
    {
        return $this->revisionHistory
            ->where('key', 'created_at')
            ->where('user_type', null)
            ->where('user_id', null)
            ->isNotEmpty();
    }

    public function hasBeenEditedByUser()
    {
        return $this->revisionHistory
            ->where('user_type', '!=', null)
            ->where('user_id', '!=', null)
            ->isNotEmpty();
    }
}

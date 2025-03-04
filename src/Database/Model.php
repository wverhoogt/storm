<?php namespace Winter\Storm\Database;

use Cache;
use Closure;
use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Collection as CollectionBase;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Throwable;
use Winter\Storm\Argon\Argon;
use Winter\Storm\Support\Arr;
use Winter\Storm\Support\Str;

/**
 * Active Record base class.
 *
 * Extends Eloquent with added extendability and deferred bindings.
 *
 * @author Alexey Bobkov, Samuel Georges
 *
 * @phpstan-property \Illuminate\Contracts\Events\Dispatcher|null $dispatcher
 * @method static void extend(callable $callback, bool $scoped = false, ?object $outerScope = null)
 */
class Model extends EloquentModel implements ModelInterface
{
    use Concerns\GuardsAttributes;
    use Concerns\HasRelationships;
    use Concerns\HidesAttributes;
    use Traits\Purgeable;
    use \Winter\Storm\Support\Traits\Emitter;
    use \Winter\Storm\Extension\ExtendableTrait {
        addDynamicProperty as protected extendableAddDynamicProperty;
    }
    use \Winter\Storm\Database\Traits\DeferredBinding;

    /**
     * @var string|array|null Extensions implemented by this class.
     */
    public $implement = null;

    /**
     * @var array Make the model's attributes public so behaviors can modify them.
     */
    public $attributes = [];

    /**
     * @var array List of attribute names which are json encoded and decoded from the database.
     */
    protected $jsonable = [];

    /**
     * @var array List of datetime attributes to convert to an instance of Carbon/DateTime objects.
     */
    protected $dates = [];

    /**
     * @var array List of attributes which should not be saved to the database.
     */
    protected $purgeable = [];

    /**
     * @var bool Indicates if duplicate queries from this model should be cached in memory.
     */
    public $duplicateCache = true;

    /**
     * @var bool Indicates if all string model attributes will be trimmed prior to saving.
     */
    public $trimStringAttributes = true;

    /**
     * @var array The array of models booted events.
     */
    protected static $eventsBooted = [];

    /**
     * Constructor
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct();

        $this->bootNicerEvents();

        $this->extendableConstruct();

        $this->fill($attributes);
    }

    /**
     * Static helper for isDatabaseReady()
     */
    public static function hasDatabaseTable(): bool
    {
        return (new static)->isDatabaseReady();
    }

    /**
     * Check if the model's database connection is ready
     */
    public function isDatabaseReady(): bool
    {
        $cacheKey = sprintf('winter.storm::model.%s.isDatabaseReady.%s.%s', get_class($this), $this->getConnectionName() ?? '', $this->getTable());
        if ($result = Cache::get($cacheKey)) {
            return $result;
        }

        // Resolver hasn't been set yet
        /** @phpstan-ignore-next-line */
        if (!static::getConnectionResolver()) {
            return false;
        }

        // Connection hasn't been set yet or the database doesn't exist
        try {
            $connection = $this->getConnection();
            $connection->getPdo();
        } catch (Throwable $ex) {
            return false;
        }

        // Database exists but table doesn't
        try {
            $schema = $connection->getSchemaBuilder();
            $table = $this->getTable();
            if (!$schema->hasTable($table)) {
                return false;
            }
        } catch (Throwable $ex) {
            return false;
        }

        Cache::forever($cacheKey, true);

        return true;
    }

    /**
     * Create a new model and return the instance.
     * @param array $attributes
     * @return \Illuminate\Database\Eloquent\Model|static
     */
    public static function make($attributes = [])
    {
        return new static($attributes);
    }

    /**
     * Save a new model and return the instance.
     * @param array $attributes
     * @param string $sessionKey
     * @return \Illuminate\Database\Eloquent\Model|static
     */
    public static function create(array $attributes = [], $sessionKey = null)
    {
        $model = new static($attributes);

        $model->save([], $sessionKey);

        return $model;
    }

    /**
     * Reloads the model attributes from the database.
     * @return \Illuminate\Database\Eloquent\Model|static
     */
    public function reload()
    {
        static::flushDuplicateCache();

        if (!$this->exists) {
            $this->syncOriginal();
        }
        elseif ($fresh = static::find($this->getKey())) {
            $this->setRawAttributes($fresh->getAttributes(), true);
        }

        return $this;
    }

    /**
     * Reloads the model relationship cache.
     * @param string  $relationName
     * @return void
     */
    public function reloadRelations($relationName = null)
    {
        static::flushDuplicateCache();

        if (!$relationName) {
            $this->setRelations([]);
        }
        else {
            unset($this->relations[$relationName]);
        }
    }

    /**
     * Bind some nicer events to this model, in the format of method overrides.
     */
    protected function bootNicerEvents()
    {
        $class = get_called_class();

        // If the $dispatcher hasn't been set yet don't bother trying
        // to register the nicer model events yet since it will silently fail
        if (!isset(static::$dispatcher)) {
            return;
        }

        // Events have already been booted, continue
        if (isset(static::$eventsBooted[$class])) {
            return;
        }

        $radicals = ['creat', 'sav', 'updat', 'delet', 'fetch'];
        $hooks = ['before' => 'ing', 'after' => 'ed'];

        foreach ($radicals as $radical) {
            foreach ($hooks as $hook => $event) {
                $eventMethod = $radical . $event; // saving / saved
                $method = $hook . ucfirst($radical); // beforeSave / afterSave

                if ($radical != 'fetch') {
                    $method .= 'e';
                }

                self::$eventMethod(function ($model) use ($method) {
                    if ($model->methodExists($method)) {
                        // Register the method as a listener with default priority
                        // to allow for complete control over the execution order
                        $model->bindEvent('model.' . $method, [$model, $method]);
                    }
                    // First listener that returns a non-null result will cancel the
                    // further propagation of the event; If that result is false, the
                    // underlying action will get cancelled (e.g. creating, saving, deleting)
                    return $model->fireEvent('model.' . $method, halt: true);
                });
            }
        }

        /*
         * Hook to boot events
         */
        static::registerModelEvent('booted', function ($model) {
            /**
             * @event model.afterBoot
             * Called after the model is booted
             * > **Note:** also triggered in Winter\Storm\Halcyon\Model
             *
             * Example usage:
             *
             *     $model->bindEvent('model.afterBoot', function () use (\Winter\Storm\Database\Model $model) {
             *         \Log::info(get_class($model) . ' has booted');
             *     });
             *
             */
            $model->fireEvent('model.afterBoot');

            if ($model->methodExists('afterBoot')) {
                return $model->afterBoot();
            }
        });

        static::$eventsBooted[$class] = true;
    }

    /**
     * Remove all of the event listeners for the model
     * Also flush registry of models that had events booted
     * Allows painless unit testing.
     *
     * @override
     * @return void
     */
    public static function flushEventListeners()
    {
        parent::flushEventListeners();
        static::$eventsBooted = [];
    }

    /**
     * Handle the "creating" model event
     */
    protected function beforeCreate()
    {
        /**
         * @event model.beforeCreate
         * Called before the model is created
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.beforeCreate', function () use (\Winter\Storm\Database\Model $model) {
         *         if (!$model->isValid()) {
         *             throw new \Exception("Invalid Model!");
         *         }
         *     });
         *
         */
    }

    /**
     * Handle the "created" model event
     */
    protected function afterCreate()
    {
        /**
         * @event model.afterCreate
         * Called after the model is created
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.afterCreate', function () use (\Winter\Storm\Database\Model $model) {
         *         \Log::info("{$model->name} was created!");
         *     });
         *
         */
    }

    /**
     * Handle the "updating" model event
     */
    protected function beforeUpdate()
    {
        /**
         * @event model.beforeUpdate
         * Called before the model is updated
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.beforeUpdate', function () use (\Winter\Storm\Database\Model $model) {
         *         if (!$model->isValid()) {
         *             throw new \Exception("Invalid Model!");
         *         }
         *     });
         *
         */
    }

    /**
     * Handle the "updated" model event
     */
    protected function afterUpdate()
    {
        /**
         * @event model.afterUpdate
         * Called after the model is updated
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.afterUpdate', function () use (\Winter\Storm\Database\Model $model) {
         *         if ($model->title !== $model->original['title']) {
         *             \Log::info("{$model->name} updated its title!");
         *         }
         *     });
         *
         */
    }

    /**
     * Handle the "saving" model event
     */
    protected function beforeSave()
    {
        /**
         * @event model.beforeSave
         * Called before the model is saved
         * > **Note:** This is called both when creating and updating and is also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.beforeSave', function () use (\Winter\Storm\Database\Model $model) {
         *         if (!$model->isValid()) {
         *             throw new \Exception("Invalid Model!");
         *         }
         *     });
         *
         */
    }

    /**
     * Handle the "saved" model event
     */
    protected function afterSave()
    {
        /**
         * @event model.afterSave
         * Called after the model is saved
         * > **Note:** This is called both when creating and updating and is also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.afterSave', function () use (\Winter\Storm\Database\Model $model) {
         *         if ($model->title !== $model->original['title']) {
         *             \Log::info("{$model->name} updated its title!");
         *         }
         *     });
         *
         */
    }

    /**
     * Handle the "deleting" model event
     */
    protected function beforeDelete()
    {
        /**
         * @event model.beforeDelete
         * Called before the model is deleted
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.beforeDelete', function () use (\Winter\Storm\Database\Model $model) {
         *         if (!$model->isAllowedToBeDeleted()) {
         *             throw new \Exception("You cannot delete me!");
         *         }
         *     });
         *
         */
    }

    /**
     * Handle the "deleted" model event
     */
    protected function afterDelete()
    {
        /**
         * @event model.afterDelete
         * Called after the model is deleted
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.afterDelete', function () use (\Winter\Storm\Database\Model $model) {
         *         \Log::info("{$model->name} was deleted");
         *     });
         *
         */
    }

    /**
     * Handle the "fetching" model event
     */
    protected function beforeFetch()
    {
        /**
         * @event model.beforeFetch
         * Called before the model is fetched
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.beforeFetch', function () use (\Winter\Storm\Database\Model $model) {
         *         if (!\Auth::getUser()->hasAccess('fetch.this.model')) {
         *             throw new \Exception("You shall not pass!");
         *         }
         *     });
         *
         */
    }

    /**
     * Handle the "fetched" model event
     */
    protected function afterFetch()
    {
        /**
         * @event model.afterFetch
         * Called after the model is fetched
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.afterFetch', function () use (\Winter\Storm\Database\Model $model) {
         *         \Log::info("{$model->name} was retrieved from the database");
         *     });
         *
         */
    }

    /**
     * Flush the memory cache.
     * @return void
     */
    public static function flushDuplicateCache()
    {
        MemoryCache::instance()->flush();
    }

    /**
     * Create a new model instance that is existing.
     * @param  array  $attributes
     * @return \Illuminate\Database\Eloquent\Model|static
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $instance = $this->newInstance([], true);

        if ($instance->fireModelEvent('fetching') === false) {
            return $instance;
        }

        $instance->setRawAttributes((array) $attributes, true);

        $instance->fireModelEvent('fetched', false);

        $instance->setConnection($connection ?: $this->connection);

        $instance->fireModelEvent('retrieved', false);

        return $instance;
    }

    /**
     * Create a new native event for handling beforeFetch().
     * @param Closure|string $callback
     * @return void
     */
    public static function fetching($callback)
    {
        static::registerModelEvent('fetching', $callback);
    }

    /**
     * Create a new native event for handling afterFetch().
     * @param Closure|string $callback
     * @return void
     */
    public static function fetched($callback)
    {
        static::registerModelEvent('fetched', $callback);
    }

    /**
     * Checks if an attribute is jsonable or not.
     *
     * @return bool
     */
    public function isJsonable($key)
    {
        return in_array($key, $this->jsonable);
    }

    /**
     * Get the jsonable attributes name
     *
     * @return array
     */
    public function getJsonable()
    {
        return $this->jsonable;
    }

    /**
     * Set the jsonable attributes for the model.
     *
     * @param  array  $jsonable
     * @return $this
     */
    public function jsonable(array $jsonable)
    {
        $this->jsonable = $jsonable;

        return $this;
    }

    //
    // Overrides
    //

    /**
     * Get the observable event names.
     * @return array
     */
    public function getObservableEvents()
    {
        return array_merge(
            [
                'creating', 'created', 'updating', 'updated',
                'deleting', 'deleted', 'saving', 'saved',
                'restoring', 'restored', 'fetching', 'fetched'
            ],
            $this->observables
        );
    }

    /**
     * Get a fresh timestamp for the model.
     *
     * @return \Illuminate\Support\Carbon
     */
    public function freshTimestamp()
    {
        return new Argon;
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @param  mixed  $value
     * @return \Carbon\Carbon
     */
    protected function asDateTime($value)
    {
        // If this value is already a Argon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Argon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof Argon) {
            return $value;
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return new Argon(
                $value->format('Y-m-d H:i:s.u'),
                $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Argon::createFromTimestamp($value);
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if ($this->isStandardDateFormat($value)) {
            return Argon::createFromFormat('Y-m-d', $value)->startOfDay();
        }

        $format = $this->getDateFormat();

        // https://bugs.php.net/bug.php?id=75577
        if (version_compare(PHP_VERSION, '7.3.0-dev', '<')) {
            $format = str_replace('.v', '.u', $format);
        }

        // If the value is expected to end in milli or micro seconds but doesn't
        // then we should attempt to fix it as it's most likely from the datepicker
        // which doesn't support sending micro or milliseconds
        // @see https://github.com/rainlab/blog-plugin/issues/334
        if (str_contains($format, '.') && !str_contains($value, '.')) {
            if (ends_with($format, '.u')) {
                $value .= '.000000';
            }
            if (ends_with($format, '.v')) {
                $value .= '.000';
            }
        }

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        return Argon::createFromFormat($format, $value);
    }

    /**
     * Convert a DateTime to a storable string.
     *
     * @param  \DateTime|int|null  $value
     * @return string|null
     */
    public function fromDateTime($value = null)
    {
        if (is_null($value)) {
            return $value;
        }

        return parent::fromDateTime($value);
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  \Winter\Storm\Database\QueryBuilder $query
     * @return \Winter\Storm\Database\Builder
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * Get a new query builder instance for the connection.
     *
     * @return \Winter\Storm\Database\QueryBuilder
     */
    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection();

        $grammar = $conn->getQueryGrammar();

        $builder = new QueryBuilder($conn, $grammar, $conn->getPostProcessor());

        if ($this->duplicateCache) {
            $builder->enableDuplicateCache();
        }

        return $builder;
    }

    /**
     * Create a new Model Collection instance.
     *
     * @param  array  $models
     * @return \Winter\Storm\Database\Collection
     */
    public function newCollection(array $models = [])
    {
        return new Collection($models);
    }

    //
    // Magic
    //

    /**
     * Programmatically adds a property to the extendable class
     *
     * @param string $dynamicName The name of the property to add
     * @param mixed $value The value of the property
     * @return void
     */
    public function addDynamicProperty($dynamicName, $value = null)
    {
        if (array_key_exists($dynamicName, $this->getDynamicProperties())) {
            return;
        }

        // Ensure that dynamic properties are automatically purged
        $this->addPurgeable($dynamicName);

        // Add the dynamic property
        $this->extendableAddDynamicProperty($dynamicName, $value);
    }

    public function __get($name)
    {
        return $this->extendableGet($name);
    }

    public function __set($name, $value)
    {
        $this->extendableSet($name, $value);
    }

    public function __call($name, $params)
    {
        if ($name === 'extend') {
            if (empty($params[0]) || !is_callable($params[0])) {
                throw new \InvalidArgumentException('The extend() method requires a callback parameter or closure.');
            }
            if ($params[0] instanceof \Closure) {
                return $params[0]->call($this, $params[1] ?? $this);
            }
            return \Closure::fromCallable($params[0])->call($this, $params[1] ?? $this);
        }

        /*
         * Never call handleRelation() anywhere else as it could
         * break getRelationCaller(), use $this->{$name}() instead
         */
        if ($this->hasRelation($name)) {
            return $this->handleRelation($name);
        }

        return $this->extendableCall($name, $params);
    }

    public static function __callStatic($name, $params)
    {
        if ($name === 'extend') {
            if (empty($params[0])) {
                throw new \InvalidArgumentException('The extend() method requires a callback parameter or closure.');
            }
            self::extendableExtendCallback($params[0], $params[1] ?? false, $params[2] ?? null);
            return;
        }

        return parent::__callStatic($name, $params);
    }

    /**
     * Determine if an attribute or relation exists on the model.
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset($key)
    {
        return !is_null($this->getAttribute($key));
    }

    /**
     * This a custom piece of logic specifically to satisfy Twig's
     * desire to return a relation object instead of loading the
     * related model.
     *
     * @param  mixed  $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        if ($result = parent::offsetExists($offset)) {
            return $result;
        }

        return $this->hasRelation($offset);
    }

    //
    // Pivot
    //

    /**
     * Create a generic pivot model instance.
     * @param  \Winter\Storm\Database\Model  $parent
     * @param  array  $attributes
     * @param  string  $table
     * @param  bool  $exists
     * @param  string|null  $using
     * @return \Winter\Storm\Database\Pivot
     */
    public function newPivot(EloquentModel $parent, array $attributes, $table, $exists, $using = null)
    {
        return $using
            ? $using::fromRawAttributes($parent, $attributes, $table, $exists)
            : Pivot::fromAttributes($parent, $attributes, $table, $exists);
    }

    /**
     * Create a pivot model instance specific to a relation.
     * @param  \Winter\Storm\Database\Model  $parent
     * @param  string  $relationName
     * @param  array   $attributes
     * @param  string  $table
     * @param  bool    $exists
     * @return \Winter\Storm\Database\Pivot|null
     */
    public function newRelationPivot($relationName, $parent, $attributes, $table, $exists)
    {
        $definition = $this->getRelationDefinition($relationName);

        if (!is_null($definition) && array_key_exists('pivotModel', $definition)) {
            $pivotModel = $definition['pivotModel'];
            return $pivotModel::fromAttributes($parent, $attributes, $table, $exists);
        }
    }

    //
    // Saving
    //

    /**
     * Save the model to the database. Is used by {@link save()} and {@link forceSave()}.
     * @param array $options
     * @return bool
     */
    protected function saveInternal(array $options = [])
    {
        /**
         * @event model.saveInternal
         * Called before the model is saved
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.saveInternal', function ((array) $attributes, (array) $options) use (\Winter\Storm\Database\Model $model) {
         *         // Prevent anything from saving ever!
         *         return false;
         *     });
         *
         */
        if ($this->fireEvent('model.saveInternal', [$this->attributes, $options], true) === false) {
            return false;
        }

        /*
         * Validate attributes before trying to save
         */
        foreach ($this->attributes as $attribute => $value) {
            if (is_array($value)) {
                throw new Exception(sprintf('Unexpected type of array when attempting to save attribute "%s", try adding it to the $jsonable property.', $attribute));
            }
        }

        // Apply pre deferred bindings
        if ($this->sessionKey !== null) {
            $this->commitDeferredBefore($this->sessionKey);
        }

        // Save the record
        $result = parent::save($options);

        // Halted by event
        if ($result === false) {
            return $result;
        }

        // Apply post deferred bindings
        if ($this->sessionKey !== null) {
            $this->commitDeferredAfter($this->sessionKey);
        }

        return $result;
    }

    /**
     * Save the model to the database.
     * @param array $options
     * @param string|null $sessionKey
     * @return bool
     */
    public function save(?array $options = [], $sessionKey = null)
    {
        $this->sessionKey = $sessionKey;
        return $this->saveInternal(['force' => false] + (array) $options);
    }

    /**
     * Save the model and all of its relationships.
     *
     * @param array $options
     * @param string|null $sessionKey
     * @return bool
     */
    public function push(?array $options = [], $sessionKey = null)
    {
        $always = Arr::get($options, 'always', false);

        if (!$this->save([], $sessionKey) && !$always) {
            return false;
        }

        foreach ($this->relations as $name => $models) {
            if (!$this->isRelationPushable($name)) {
                continue;
            }

            if ($models instanceof CollectionBase) {
                $models = $models->all();
            }
            elseif ($models instanceof EloquentModel) {
                $models = [$models];
            }
            else {
                $models = (array) $models;
            }

            foreach (array_filter($models) as $model) {
                if (!$model->push(null, $sessionKey)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Pushes the first level of relations even if the parent
     * model has no changes.
     *
     * @param array $options
     * @param string|null $sessionKey
     * @return bool
     */
    public function alwaysPush(?array $options = [], $sessionKey = null)
    {
        return $this->push(['always' => true] + (array) $options, $sessionKey);
    }

    //
    // Deleting
    //

    /**
     * Perform the actual delete query on this model instance.
     * @return void
     */
    protected function performDeleteOnModel()
    {
        $this->performDeleteOnRelations();

        $this->setKeysForSaveQuery($this->newQueryWithoutScopes())->delete();
    }

    /**
     * Locates relations with delete flag and cascades the delete event.
     * @return void
     */
    protected function performDeleteOnRelations()
    {
        $definitions = $this->getRelationDefinitions();
        foreach ($definitions as $type => $relations) {
            /*
             * Hard 'delete' definition
             */
            foreach ($relations as $name => $options) {
                if (!Arr::get($options, 'delete', false)) {
                    continue;
                }

                if (!$relation = $this->{$name}) {
                    continue;
                }

                if ($relation instanceof EloquentModel) {
                    $relation->forceDelete();
                }
                elseif ($relation instanceof CollectionBase) {
                    $relation->each(function ($model) {
                        $model->forceDelete();
                    });
                }
            }

            /*
             * Belongs-To-Many should clean up after itself always
             */
            if ($type == 'belongsToMany') {
                foreach ($relations as $name => $options) {
                    if (Arr::get($options, 'detach', true)) {
                        $this->{$name}()->detach();
                    }
                }
            }
        }
    }

    //
    // Adders
    //

    /**
     * Add attribute casts for the model.
     *
     * @param  array $attributes
     * @return void
     */
    public function addCasts($attributes)
    {
        $this->casts = array_merge($this->casts, $attributes);
    }

    /**
     * Adds a datetime attribute to convert to an instance of Carbon/DateTime object.
     * @param string   $attribute
     * @return void
     */
    public function addDateAttribute($attribute)
    {
        if (in_array($attribute, $this->dates)) {
            return;
        }

        $this->dates[] = $attribute;
    }

    /**
     * Add fillable attributes for the model.
     *
     * @param  array|string|null  $attributes
     * @return void
     */
    public function addFillable($attributes = null)
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->fillable = array_merge($this->fillable, $attributes);
    }

    /**
     * Add jsonable attributes for the model.
     *
     * @param  array|string|null  $attributes
     * @return void
     */
    public function addJsonable($attributes = null)
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->jsonable = array_merge($this->jsonable, $attributes);
    }

    //
    // Getters
    //

    /**
     * Determine if the given attribute will be processed by getAttributeValue().
     */
    public function hasAttribute(string $key): bool
    {
        return (
            array_key_exists($key, $this->attributes)
            || array_key_exists($key, $this->casts)
            || $this->hasGetMutator($key)
            || $this->hasAttributeMutator($key)
            || $this->isClassCastable($key)
        );
    }

    /**
     * Get an attribute from the model.
     * Overrides {@link Eloquent} to support loading from property-defined relations.
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if (!$key) {
            return;
        }

        // If the attribute exists in the attribute array or has a "get" mutator we will
        // get the attribute's value. Otherwise, we will proceed as if the developers
        // are asking for a relationship's value. This covers both types of values.
        if ($this->hasAttribute($key)) {
            return $this->getAttributeValue($key);
        }

        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if ($this->hasRelation($key)) {
            if ($this->preventsLazyLoading) {
                $this->handleLazyLoadingViolation($key);
            }

            return $this->getRelationshipFromMethod($key);
        }
    }

    /**
     * Get a plain attribute (not a relationship).
     * @param  string  $key
     * @return mixed
     */
    public function getAttributeValue($key)
    {
        /**
         * @event model.beforeGetAttribute
         * Called before the model attribute is retrieved (only when the attribute exists in `$model->attributes` or has a get mutator method defined; i.e. `getFooAttribute()`)
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.beforeGetAttribute', function ((string) $key) use (\Winter\Storm\Database\Model $model) {
         *         if ($key === 'not-for-you-to-look-at') {
         *             return 'you are not allowed here';
         *         }
         *     });
         *
         */
        if (($attr = $this->fireEvent('model.beforeGetAttribute', [$key], true)) !== null) {
            return $attr;
        }

        $attr = parent::getAttributeValue($key);

        /*
         * Return valid json (boolean, array) if valid, otherwise
         * jsonable fields will return a string for invalid data.
         */
        if ($this->isJsonable($key) && !empty($attr)) {
            $_attr = json_decode($attr, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $attr = $_attr;
            }
        }

        /**
         * @event model.getAttribute
         * Called after the model attribute is retrieved (only when the attribute exists in `$model->attributes` or has a get mutator method defined; i.e. `getFooAttribute()`)
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.getAttribute', function ((string) $key, $value) use (\Winter\Storm\Database\Model $model) {
         *         if ($key === 'not-for-you-to-look-at') {
         *             return "Totally not $value";
         *         }
         *     });
         *
         */
        if (($_attr = $this->fireEvent('model.getAttribute', [$key, $attr], true)) !== null) {
            return $_attr;
        }

        return $attr;
    }

    /**
     * Determine if a get mutator exists for an attribute.
     * @param  string  $key
     * @return bool
     */
    public function hasGetMutator($key)
    {
        return $this->methodExists('get'.Str::studly($key).'Attribute');
    }

    /**
     * Convert the model's attributes to an array.
     * @return array
     */
    public function attributesToArray()
    {
        $attributes = $this->getArrayableAttributes();

        /*
         * Before Event
         */
        foreach ($attributes as $key => $value) {
            if (($eventValue = $this->fireEvent('model.beforeGetAttribute', [$key], true)) !== null) {
                $attributes[$key] = $eventValue;
            }
        }

        /*
         * Dates
         */
        foreach ($this->getDates() as $key) {
            if (!isset($attributes[$key])) {
                continue;
            }

            $attributes[$key] = $this->serializeDate(
                $this->asDateTime($attributes[$key])
            );
        }

        /*
         * Mutate
         */
        $mutatedAttributes = $this->getMutatedAttributes();

        foreach ($mutatedAttributes as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $attributes[$key] = $this->mutateAttributeForArray(
                $key,
                $attributes[$key]
            );
        }

        /*
         * Casts
         */
        foreach ($this->casts as $key => $value) {
            if (
                !array_key_exists($key, $attributes) ||
                in_array($key, $mutatedAttributes)
            ) {
                continue;
            }

            $attributes[$key] = $this->castAttribute(
                $key,
                $attributes[$key]
            );
        }

        /*
         * Appends
         */
        foreach ($this->getArrayableAppends() as $key) {
            $attributes[$key] = $this->mutateAttributeForArray($key, null);
        }

        /*
         * Jsonable
         */
        foreach ($this->jsonable as $key) {
            if (
                !array_key_exists($key, $attributes) ||
                in_array($key, $mutatedAttributes)
            ) {
                continue;
            }

            // Prevent double decoding of jsonable attributes.
            if (!is_string($attributes[$key])) {
                continue;
            }

            $jsonValue = json_decode($attributes[$key], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $attributes[$key] = $jsonValue;
            }
        }

        /*
         * After Event
         */
        foreach ($attributes as $key => $value) {
            if (($eventValue = $this->fireEvent('model.getAttribute', [$key, $value], true)) !== null) {
                $attributes[$key] = $eventValue;
            }
        }

        return $attributes;
    }

    //
    // Setters
    //

    /**
     * Set a given attribute on the model.
     * @param string $key
     * @param mixed $value
     * @return mixed|null
     */
    public function setAttribute($key, $value)
    {
        /*
         * Attempting to set attribute [null] on model.
         */
        if (empty($key)) {
            throw new Exception('Cannot access empty model attribute.');
        }

        /*
         * Handle direct relation setting
         */
        if ($this->hasRelation($key) && !$this->hasSetMutator($key)) {
            $this->setRelationValue($key, $value);
            return;
        }

        /**
         * @event model.beforeSetAttribute
         * Called before the model attribute is set
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.beforeSetAttribute', function ((string) $key, $value) use (\Winter\Storm\Database\Model $model) {
         *         if ($key === 'not-for-you-to-touch') {
         *             return '$value has been touched! The humanity!';
         *         }
         *     });
         *
         */
        if (($_value = $this->fireEvent('model.beforeSetAttribute', [$key, $value], true)) !== null) {
            $value = $_value;
        }

        /*
         * Jsonable
         */
        if ($this->isJsonable($key) && (!empty($value) || is_array($value))) {
            $value = json_encode($value);
        }

        /*
         * Trim strings
         */
        if ($this->trimStringAttributes && is_string($value)) {
            $value = trim($value);
        }

        $result = parent::setAttribute($key, $value);

        /**
         * @event model.setAttribute
         * Called after the model attribute is set
         * > **Note:** also triggered in Winter\Storm\Halcyon\Model
         *
         * Example usage:
         *
         *     $model->bindEvent('model.setAttribute', function ((string) $key, $value) use (\Winter\Storm\Database\Model $model) {
         *         if ($key === 'not-for-you-to-touch') {
         *             \Log::info("{$key} has been touched and set to {$value}!")
         *         }
         *     });
         *
         */
        $this->fireEvent('model.setAttribute', [$key, $value]);

        return $result;
    }

    /**
     * Determine if a set mutator exists for an attribute.
     * @param  string  $key
     * @return bool
     */
    public function hasSetMutator($key)
    {
        return $this->methodExists('set'.Str::studly($key).'Attribute');
    }
}

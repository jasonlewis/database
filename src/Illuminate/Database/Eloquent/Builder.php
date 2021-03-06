<?php namespace Illuminate\Database\Eloquent;

use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Builder {

	/**
	 * The base query builder instance.
	 *
	 * @var Illuminate\Database\Query\Builder
	 */
	protected $query;

	/**
	 * The model being queried.
	 *
	 * @var Illuminate\Database\Eloquent\Model
	 */
	protected $model;

	/**
	 * The relationships that should be eager loaded.
	 *
	 * @var array
	 */
	protected $eagerLoad = array();

	/**
	 * The methods that should be returned from query builder.
	 *
	 * @var array
	 */
	protected $passthru = array(
		'lists', 'insert', 'insertGetId', 'update', 'delete',
		'pluck', 'count', 'min', 'max', 'avg', 'sum',
	);

	/**
	 * Create a new Eloquent query builder instance.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @return void
	 */
	public function __construct(QueryBuilder $query)
	{
		$this->query = $query;
	}

	/**
	 * Find a model by its primary key.
	 *
	 * @param  mixed  $id
	 * @param  array  $columns
	 * @return Illuminate\Database\Eloquent\Model
	 */
	public function find($id, $columns = array('*'))
	{
		$this->query->where($this->model->getKeyName(), '=', $id);

		return $this->first($columns);
	}

	/**
	 * Execute the query and get the first result.
	 *
	 * @param  array   $columns
	 * @return array
	 */
	public function first($columns = array('*'))
	{
		return $this->get($columns)->first();
	}

	/**
	 * Execute the query as a "select" statement.
	 *
	 * @param  array  $columns
	 * @return Illuminate\Database\Eloquent\Collection
	 */
	public function get($columns = array('*'))
	{
		$models = $this->getModels($columns);

		// If we actually found models we will also eager load any relationships that
		// have been specified as needing to be eager loaded, which will solve the
		// n+1 query issue for the developers to avoid running a lot of queries.
		if (count($models) > 0)
		{
			$models = $this->eagerLoadRelations($models);
		}

		return $this->model->newCollection($models);
	}

	/**
	 * Increment a column's value by a given amount.
	 *
	 * @param  string  $column
	 * @param  int     $amount
	 * @return int
	 */
	public function increment($column, $amount = 1)
	{
		$this->query->where($this->model->getKeyName(), '=', $this->model->getKey());

		return $this->query->increment($column, $amount);
	}

	/**
	 * Decrement a column's value by a given amount.
	 *
	 * @param  string  $column
	 * @param  int     $amount
	 * @return int
	 */
	public function decrement($column, $amount = 1)
	{
		$this->query->where($this->model->getKeyName(), '=', $this->model->getKey());

		return $this->query->decrement($column, $amount);
	}

	/**
	 * Get a paginator for the "select" statement.
	 *
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return Illuminate\Pagination\Paginator
	 */
	public function paginate($perPage = null, $columns = array('*'))
	{
		$perPage = $perPage ?: $this->model->getPerPage();

		$paginator = $this->query->getConnection()->getPaginator();

		if (isset($this->query->groups))
		{
			return $this->groupedPaginate($paginator, $perPage, $columns);
		}
		else
		{
			return $this->ungroupedPaginate($paginator, $perPage, $columns);
		}
	}

	/**
	 * Get a paginator for a grouped statement.
	 *
	 * @param  Illuminate\Pagination\Environment  $paginator
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return Illuminate\Pagination\Paginator
	 */
	protected function groupedPaginate($paginator, $perPage, $columns)
	{
		$results = $this->get($columns)->all();

		return $this->query->buildRawPaginator($paginator, $results, $perPage);
	}

	/**
	 * Get a paginator for an ungrouped statement.
	 *
	 * @param  Illuminate\Pagination\Environment  $paginator
	 * @param  int    $perPage
	 * @param  array  $columns
	 * @return Illuminate\Pagination\Paginator
	 */
	protected function ungroupedPaginate($paginator, $perPage, $columns)
	{
		$total = $this->query->getPaginationCount();

		// Once we have the paginator we need to set the limit and offet values for
		// the query so we can get the properly paginated items. Once we have an
		// array of items we can create the paginator instances for the items.
		$page = $paginator->getCurrentPage();

		$this->query->forPage($page, $perPage);

		return $paginator->make($this->get($columns)->all(), $total, $perPage);
	}

	/**
	 * Get the hydrated models without eager loading.
	 *
	 * @param  array  $columns
	 * @return array
	 */
	public function getModels($columns = array('*'))
	{
		// First, we will simply get the raw results from the query builders which we
		// can use to populate an array with Eloquent models. We will pass columns
		// that should be selected as well, which are typically just everything.
		$results = $this->query->get($columns);

		$connection = $this->model->getConnectionName();

		$models = array();

		// Once we have the results, we can spin through them and instantiate a fresh
		// model instance for each records we retrieved from the database. We will
		// also set the proper connection name for the model after we create it.
		foreach ($results as $result)
		{
			$models[] = $model = $this->model->newExisting();

			$model->setAttributes((array) $result);

			$model->setConnection($connection);
		}

		return $models;
	}

	/**
	 * Eager load the relationships for the models.
	 *
	 * @param  array  $models
	 * @return array
	 */
	public function eagerLoadRelations(array $models)
	{
		foreach ($this->eagerLoad as $name => $constraints)
		{
			// For nested eager loads we'll skip loading them here and they will be set as an
			// eager load on the query to retrieve the relation so that they will be eager
			// loaded on that query, because that is where they get hydrated as models.
			if (strpos($name, '.') === false)
			{
				$models = $this->loadRelation($models, $name, $constraints);
			}
		}

		return $models;
	}

	/**
	 * Eagerly load the relationship on a set of models.
	 *
	 * @param  string   $relation
	 * @param  array    $models
	 * @param  Closure  $constraints
	 * @return array
	 */
	protected function loadRelation(array $models, $name, Closure $constraints)
	{
		// First we will "back up" the existing where conditions on the query so we can
		// add our eager constraints. Then we will merge the wheres that were on the
		// query back to it in order that any where conditions might be specified.
		$relation = $this->getRelation($name);

		list($wheres, $bindings) = $relation->getAndResetWheres();

		$relation->addEagerConstraints($models);

		// We allow the developers to specify constraints on eager loads and we'll just
		// call the constraints Closure, passing along the query so they will simply
		// do all they need to the queries, and even may specify non-where things.
		$relation->mergeWheres($wheres, $bindings);

		call_user_func($constraints, $relation);

		$models = $relation->initRelation($models, $name);

		// Once we have the results, we just match those back up to their parent models
		// using the relationship instance. Then we just return the finished arrays
		// of models which have been eagerly hydrated and are readied for return.
		$results = $relation->get();

		return $relation->match($models, $results, $name);
	}

	/**
	 * Get the relation instance for the given relation name.
	 *
	 * @param  string  $relation
	 * @return Illuminate\Database\Eloquent\Relations\Relation
	 */
	public function getRelation($relation)
	{
		$query = $this->getModel()->$relation();

		// If there are nosted relationships set on the query, we will put those onto
		// the query instances so that they can be handled after this relationship
		// is loaded. In this way they will all trickle down as they are loaded.
		$nested = $this->nestedRelations($relation);

		if (count($nested) > 0)
		{
			$query->getQuery()->with($nested);
		}

		return $query;
	}

	/**
	 * Get the deeply nested relations for a given top-level relation.
	 *
	 * @param  string  $relation
	 * @return array
	 */
	protected function nestedRelations($relation)
	{
		$nested = array();

		// We are basically looking for any relationships that are nested deeper than
		// the given top-level relationship. We will just check for any relations
		// that start with the given top relations and adds them to our arrays.
		foreach ($this->eagerLoad as $name => $constraints)
		{
			if (strpos($name, $relation) === 0 and $name !== $relation)
			{
				$nested[substr($name, strlen($relation.'.'))] = $constraints;
			}
		}

		return $nested;
	}

	/**
	 * Set the relationships that should be eaager loaded.
	 *
	 * @param  array  $relations
	 * @return Illuminate\Database\Eloquent\Builder
	 */
	public function with(array $relations)
	{
		$this->eagerLoad = $this->parseRelations($relations);

		return $this;
	}

	/**
	 * Parse a list of relations into individuals.
	 *
	 * @param  array  $relations
	 * @return array
	 */
	protected function parseRelations(array $relations)
	{
		$results = array();

		foreach ($relations as $relation => $constraints)
		{
			// If the "relation" value is actually a numeric key, we can assume that no
			// constraints have been specified for the eager load and we'll just put
			// an empty Closure with the loader so that we can treat all the same.
			if (is_numeric($relation))
			{
				$f = function() {};

				list($relation, $constraints) = array($constraints, $f);
			}

			// We need to separate out any nested includes. Which allows the developers
			// to load deep relatoinships using "dots" without stating each level of
			// the relationship with its own key in the array of eager load names.
			$progress = array();

			foreach (explode('.', $relation) as $segment)
			{
				$progress[] = $segment;

				$results[$last = implode('.', $progress)] = function() {};
			}

			// The eager load could have had constrains specified on it. We'll put them
			// on the last eager load segment, which means that for the nested eager
			// load includes only the final segments will get constrained queries.
			$results[$last] = $constraints;
		}

		return $results;
	}

	/**
	 * Get the underlying query builder instance.
	 *
	 * @return Illuminate\Database\Query\Builder
	 */
	public function getQuery()
	{
		return $this->query;
	}

	/**
	 * Set the underlying query builder instance.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @return void
	 */
	public function setQuery($query)
	{
		$this->query = $query;
	}

	/**
	 * Get the relationships being eagerly laoded.
	 *
	 * @return array
	 */
	public function getEagerLoads()
	{
		return $this->eagerLoad;
	}

	/**
	 * Set the relationships being eagerly laoded.
	 *
	 * @param  array  $eagerLoad
	 * @return void
	 */
	public function setEagerLoads(array $eagerLoad)
	{
		$this->eagerLoad = $eagerLoad;
	}

	/**
	 * Get the model instance being queried.
	 *
	 * @return Illuminate\Database\Eloquent\Model
	 */
	public function getModel()
	{
		return $this->model;
	}

	/**
	 * Set a model instance for the model being queried.
	 *
	 * @param  Illuminate\Database\Eloquent\Model  $model
	 * @return void
	 */
	public function setModel(Model $model)
	{
		$this->model = $model;

		$this->query->from($model->getTable());
	}

	/**
	 * Dynamically handle calls into the query instance.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public function __call($method, $parameters)
	{
		$result = call_user_func_array(array($this->query, $method), $parameters);

		return in_array($method, $this->passthru) ? $result : $this;
	}

}
<?php namespace Illuminate\Database\Schema\Grammars;

use Illuminate\Support\Fluent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Grammar as BaseGrammar;

abstract class Grammar extends BaseGrammar {

	/**
	 * Compile a foreign key command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileForeign(Blueprint $blueprint, Fluent $command)
	{
		$table = $this->wrapTable($blueprint);

		$on = $this->wrapTable($command->on);

		$columns = $this->columnize($command->columns);

		$onColumns = $this->columnize($command->references);

		$sql = "alter table {$table} add constraint {$command->name} ";

		$sql .= "foreign key {$columns} references {$on} ({$onColumns})";

		// Once we have the basic foreign key creation statement constructed we can
		// build out the syntax for what should happen on an update or delete of
		// the affected columns, which will get something like "cascade", etc.
		if ( ! is_null($command->onDelete))
		{
			$sql .= " on delete {$command->onDelete}";
		}

		if ( ! is_null($command->onUpdate))
		{
			$sql .= " on update {$command->onUpdate}";
		}

		return $sql;
	}

	/**
	 * Get the SQL for the column data type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function getType(Fluent $column)
	{
		return $this->{"type".ucfirst($column->type)}($column);
	}

	/**
	 * Add a prefix to an array of values.
	 *
	 * @param  string  $prefix
	 * @param  array   $values
	 * @return array
	 */
	public function prefixArray($prefix, array $values)
	{
		return array_map(function($value) use ($prefix)
		{
			return $prefix.' '.$value;

		}, $values);
	}

	/**
	 * Wrap a table in keyword identifiers.
	 *
	 * @param  mixed   $table
	 * @return string
	 */
	public function wrapTable($table)
	{
		if ($table instanceof Blueprint) $table = $table->getTable();

		return parent::wrapTable($table);
	}

	/**
	 * Wrap a value in keyword identifiers.
	 *
	 * @param  string  $value
	 * @return string
	 */
	public function wrap($value)
	{
		if ($value instanceof Fluent) $value = $value->name;

		return parent::wrap($value);
	}

}
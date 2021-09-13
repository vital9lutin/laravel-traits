<?php


namespace App\Traits;

use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

trait SortTrait
{
    public function sort(string $field, string $direction = 'asc'): self
    {
        $direction = Str::lower($direction);

        if (in_array($field, $this->allowedOrders(), true) && in_array($direction, $this->allowedDirections())) {
            if (array_key_exists($field, $this->allowedOrdersRelations())) {
                $this->orderRelationQuery($field, $direction, $this->allowedOrdersRelations()[$field]);
            } elseif ($this->existCustomMethod($field)) {
                $this->buildCustomMethod($field, $direction);
            } else {
                $this->orderQuery($field, $direction);
            }
        }

        return $this;
    }

    protected function allowedOrders(): array
    {
        return [];
    }

    protected function allowedDirections(): array
    {
        return ['asc', 'desc'];
    }

    protected function allowedOrdersRelations(): array
    {
        return [];
    }

    protected function orderRelationQuery(string $field, string $direction, string $method): void
    {
        $methods = explode('.', $method);
        $key = array_pop($methods);
        $keyWithTable = "`" . $this->getTableForOrderRelation($methods) . "`.`$key`";
        $field .= "_sort_$direction";

        $model = $this->getModel();
        $table = $model->getTable();
        $tableSub = $table . "_sub";
        $keyForReplace = '__LOCAL_KEY__';

        $query = $model->from("$table as $tableSub")
            ->selectRaw("$keyWithTable as `$field`")
            ->joinRelationship(
                implode('.', $methods)
            )
            ->whereRaw("`$tableSub`.`id` = $keyForReplace")
            ->orderBy($field, $direction)
            ->limit(1)
            ->getQuery();

        $sql = str_replace(
            $keyForReplace,
            "`$table`.`id`",
            preg_replace(
                "/`$table`\./",
                "`$tableSub`.",
                $this->getSqlForBuilder($query)
            )
        );

        $this->selectRaw("($sql) as `$field`")->orderBy($field, $direction);
    }

    private function getTableForOrderRelation(array $relations): string
    {
        $models = [];

        foreach ($relations as $relation) {
            $model = empty($models) ? $this->getModel() : end($models)->getModel();

            if (!method_exists($model, $relation)) {
                throw new Exception(__('exceptions.method_not_found', ['method ' => $relation]));
            }

            $models[] = $model->{$relation}();
        }

        return (array_pop($models))->getModel()->getTable();
    }

    private function getSqlForBuilder(Builder $model): string
    {
        $replace = static function ($sql, $bindings) {
            $needle = '?';
            foreach ($bindings as $replace) {
                $pos = strpos($sql, $needle);
                if ($pos !== false) {
                    if (is_string($replace)) {
                        $replace = " '" . addslashes($replace) . "' ";
                    }
                    $sql = substr_replace($sql, $replace, $pos, strlen($needle));
                }
            }

            return $sql;
        };

        return $replace($model->toSql(), $model->getBindings());
    }

    private function existCustomMethod(string $field): bool
    {
        return method_exists($this, $this->getCustomMethodName($field));
    }

    private function getCustomMethodName(string $field): string
    {
        return 'custom' . Str::studly($field) . 'Sort';
    }

    private function buildCustomMethod(string $field, string $direction): void
    {
        if ($this->existCustomMethod($field)) {
            $this->{$this->getCustomMethodName($field)}($field, $direction);
        }
    }

    protected function orderQuery(string $field, string $direction): void
    {
        $this->orderBy($field, $direction);
    }
}

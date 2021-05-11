<?php

namespace App\Traits;


use App\Models\Contact;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait TemplateFilter
{
    /**
     * @return Builder
     * @deprecated
     */
    public function filter(): Builder
    {
        return $this->search();
    }

    /**
     * @param Builder|Model|null $model
     * @return Builder
     */
    public function search($model = null): Builder
    {
        /**
         * Если мы не передаем $model в параметре, то мы используем текущую.
         * Подразумеваем что этот trait используется непосредственно в модели.
         *
         * @var Builder $model
         */
        $model = $model ?: $this;

        $model = $model->where(
            function (Builder $query) {
                $query->where(
                    function (Builder $query) {
                        $this->builderFilter($query);
                    }
                )->where(
                    function (Builder $query) {
                        $this->builderSearch($query);
                    }
                )->where(
                    function (Builder $query) {
                        $this->builderGlobalSearch($query);
                    }
                )->where(
                    function (Builder $query) {
                        $this->builderHas($query);
                    }
                )->where(
                    function (Builder $query) {
                        $this->builderDoesntHave($query);
                    }
                );
            }
        )->orWhere(
            function (Builder $query) {
                $this->builderEither($query);
            }
        );


        $this->builderSorting($model);

        return $model;
    }

    private function builderFilter(Builder $model): void
    {
        $data = request()->input('filter', null);

        if (!$data || !is_array($data)) {
            return;
        }

        foreach ($this->convertDateForBuilder($data) as $key => $val) {
            if (is_array($val)) {
                foreach ($val as $k => $v) {
                    $model->whereHas(
                        $key,
                        function ($q) use ($k, $v) {
                            if ($this->builderHasKeyInModel($q, $k)) {
                                $this->customWhere($q, $k, $v);
                            }
                        }
                    );
                }
            } elseif ($this->builderHasKeyInModel($model, $key)) {
                $this->customWhere($model, $key, $val);
            }
        }
    }

    private function convertDateForBuilder(array $data): array
    {
        $data = Arr::dot($data);

        $whereHas = [];

        foreach ($data as $key => $val) {
            $key = preg_replace('/\.\d+/', '', $key);
            if (strpos($key, '.')) {
                $k = explode('.', $key);
                $key = array_pop($k);
                foreach ($k as $index => $name) {
                    $k[$index] = Str::camel($name);
                }
                $keys = implode('.', $k);
                $whereHas[$keys][$key] = $val;
            } else {
                $whereHas[$key] = $val;
            }
        }

        return $whereHas;
    }

    private function builderHasKeyInModel(Builder $model, $key): bool
    {
        $model = $model->getModel();

        return in_array(
            $key,
            array_merge(
                [
                    'full_name',
                    $model->getKeyName()
                ],
                $model->getFillable()
            ),
            true
        );
    }

    private function customWhere(Builder $model, string $key, $value): void
    {
        if (strpos($value, '|')) {
            if (strpos($value, '&')) {
                $model->where(
                    function ($q) use ($value, $key) {
                        foreach (explode('&', $value) as $v) {
                            $this->customWherePrefix($q, $key, $v, 'AND');
                        }
                    }
                );
            } elseif (strpos($value, '||')) {
                $model->where(
                    function ($q) use ($value, $key) {
                        foreach (explode('||', $value) as $v) {
                            $this->customWherePrefix($q, $key, $v, 'OR');
                        }
                    }
                );
            } else {
                $this->customWherePrefix($model, $key, $value, 'AND');
            }
        } elseif (is_null($this->getColumnType($model, $key, $value))) {
            $model->whereNull($key);
        } elseif (strpos($value, ';')) {
            $model->whereIn(
                $key,
                array_map(
                    function ($v) use ($model, $key) {
                        return $this->getColumnType($model, $key, $v);
                    },
                    explode(';', $value)
                )
            );
        } else {
            $model->where($key, $this->getColumnType($model, $key, $value));
        }
    }

    private function customWherePrefix(Builder $model, string $key, $value, string $type): void
    {
        if (!strpos($value, '|')) {
            return;
        }

        $value = trim($value);
        $array = explode('|', $value);
        $prefix = array_shift($array);

        if (in_array($prefix, ["<", ">", "<=", ">=", "<>", "!=", "="])) {
            $val = $this->getColumnType($model, $key, implode(' ', $array));

            if ($type === 'OR') {
                $model->orWhere($key, $prefix, $val);
            } elseif ($type === 'AND') {
                $model->where($key, $prefix, $val);
            }
        }
    }

    private function getColumnType(Builder $model, string $key, $val)
    {
        $val = trim($val);

        switch (strtolower($val)) {
            case 'false':
                $val = false;
                break;
            case 'true':
                $val = true;
                break;
            case 'null':
                $val = null;
                break;
        }

        $types = [
            'smallint' => 'intval',
            'integer' => 'intval',
            'bigint' => 'intval',
            'string' => 'strval',
            'text' => 'strval',
            'boolean' => 'boolval',
        ];

        $table = app(get_class($model->getModel()))->getTable();

        $type = Cache::rememberForever(
            "filter.table.$table.$key",
            function () use ($model, $table, $key) {
                return $model->getConnection()->getDoctrineColumn($table, $key)->getType()->getName();
            }
        );


        if (array_key_exists($type, $types) === true) {
            $f = $types[$type];
            return $this->getValueFromAttribute($model, $key, $f($val));
        }

        if ($type === 'datetime') {
            try {
                return new Carbon($val);
            } catch (Exception $e) {
                Log::error(
                    "В TemplateFilter@getColumnType. Carbon не смог конвертировать дату. key => $key, val => $val"
                );

                return null;
            }
        }

        if ($val === -1) {
            return null;
        }

        return $val;
    }

    private function getValueFromAttribute(Builder $model, string $key, $val)
    {
        $table = $model->getModel();
        $name = 'set' . Str::studly($key) . 'Attribute';

        if (method_exists($table, $name)) {
            $table->{$name}($val);

            return $table->attributes[$key];
        }

        return $val;
    }

    private function builderSearch(Builder $model): void
    {
        $data = request()->input('search', null);

        if (!$data || !is_array($data)) {
            return;
        }

        $this->searchQuery($model, $data);
    }

    private function searchQuery(Builder $model, $data, string $specifies = 'where'): void
    {
        foreach ($this->convertDateForBuilder($data) as $key => $val) {
            if (is_array($val)) {
                foreach ($val as $k => $v) {
                    if (strpos($v, ';')) {
                        foreach (explode(';', $v) as $value) {
                            $this->conditionsWhereHas($model, $key, $k, $value, $specifies);
                        }
                    } else {
                        $this->conditionsWhereHas($model, $key, $k, $v, $specifies);
                    }
                }
            } elseif ($this->builderHasKeyInModel($model, $key)) {
                if (strpos($val, ';')) {
                    foreach (explode(';', $val) as $value) {
                        $model->where(
                            function (Builder $q) use ($value, $key, $specifies) {
                                $this->conditionsWhere($q, $key, $value, $specifies);
                            }
                        );
                    }
                } else {
                    $this->conditionsWhere($model, $key, $val, $specifies);
                }
            }
        }
    }

    private function conditionsWhereHas(Builder $model, string $keyRelation, string $key, $val, string $specifies): void
    {
        $model->{$specifies . 'Has'}(
            $keyRelation,
            function ($query) use ($key, $val) {
                if ($this->builderHasKeyInModel($query, $key)) {
                    $this->conditionsWhere($query, $key, $val, 'where');
                }
            }
        );
    }

    private function conditionsWhere(Builder $query, string $key, $val, string $specifies): void
    {
        $where = $this->getFieldsLike($query, $key, $val);

        if ($where instanceof Expression) {
            $query->{$specifies . 'Raw'}($where);
        } else {
            $query->{$specifies}($where);
        }
    }

    private function getFieldsLike(Builder $query, string $key, $value)
    {
        if ($key === 'full_name' && !in_array($key, $query->getModel()->getFillable())) {
            return DB::raw(
                "CONCAT(last_name, ' ', first_name, ' ', middle_name) ILIKE '%" . str_replace("'", "''", $value) . "%'"
            );
        }

        $value = preg_replace("/[\r\n\t]+/", " ", $value);
        $value = trim(preg_replace("/\s+/", ' ', $value));

        $arr = preg_match('/^(\d{2}).(\d{2}).(\d{4})$/', $value) || preg_match('/^(\W{1}.){1,}(\W)?$/', $value)
            ? [$value]
            : preg_split("/[\s,.]+/", $value);

        $search = [];

        foreach ($arr as $v) {
            $search[] = [$key, 'ILIKE', '%' . quotemeta($v) . '%'];
        }

        return $search;
    }

    private function builderGlobalSearch(Builder $model): void
    {
        $string = request()->input('global-search', null);

        if (!$string || !is_string($string)) {
            return;
        }

        $this->searchQuery($model, $this->getArrayForGlobalSearch($model, $string), 'orWhere');
    }

    private function getArrayForGlobalSearch(Builder $model, string $string): array
    {
        $data = array_diff($model->getModel()->getFillable(), $model->getModel()->getHidden());

        $data = array_flip(array_merge($data, [$model->getModel()->getKeyName()]));

        array_walk_recursive(
            $data,
            function (&$val) use ($string) {
                $val = $string;
            }
        );

        return $this->convertDateForBuilder(array_filter($data));
    }

    private function builderHas(Builder $model): void
    {
        $data = request()->input('has', null);

        if (!$data || !is_array($data)) {
            return;
        }

        $this->builderHasQuery($model, $data);
    }

    private function builderHasQuery(Builder $model, $data): void
    {
        foreach (Arr::dot($data) as $key => $val) {
            $model->has(Str::camel($key));
        }
    }

    private function builderDoesntHave(Builder $model): void
    {
        $data = request()->input('doesnt', null);

        if (!$data || !is_array($data)) {
            return;
        }

        $this->builderDoesntHaveQuery($model, $data);
    }

    private function builderDoesntHaveQuery(Builder $model, $data): void
    {
        foreach (Arr::dot($data) as $key => $val) {
            $model->doesntHave(Str::camel($key));
        }
    }

    private function builderEither(Builder $model): void
    {
        $data = request()->input('either', null);

        if (!$data || !is_array($data)) {
            return;
        }

        $this->searchQuery($model, $data, 'orWhere');
    }

    private function builderSorting(Builder $model): void
    {
        $data = request()->input('sorting', null);

        if (!$data && !is_array($data)) {
            return;
        }

        $model = $model->withoutGlobalScope('order');

        foreach ($data as $column => $direction) {
            if ($this->builderHasKeyInModel($model, $column)) {
                if ($column === 'full_name'
                    && $model->getModel() instanceof Contact
                    && !in_array($column, $model->getModel()->getFillable(), true)
                ) {
                    $model->orderBy(DB::raw("CONCAT(last_name, ' ', first_name, ' ', middle_name)"), $direction);
                } elseif (in_array($column, $model->getModel()->getFillable(), true)) {
                    $model->orderBy($column, $direction);
                }
            }
        }
    }
}

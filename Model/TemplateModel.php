<?php

namespace App\Traits;


use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany, HasOne, MorphMany};
use Illuminate\Http\Request;
use Illuminate\Support\{Arr, Str};
use Illuminate\Support\Facades\Log;

trait TemplateModel
{
    /**
     * @var bool
     */
    protected $syncDetaching = true;

    /**
     * Файлы работаю глобально, это связь для каждой таблицы.
     *
     * @return MorphMany
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'model');
    }

    /**
     * Метод, который отвечает за создание записи и всех связей.
     *
     * @param array $data
     * @param Model|null $model
     * @return Model
     */
    public function createItem(array $data, ?Model $model = null): Model
    {
        return $this->sync($data, $model);
    }

    /**
     * Метод, который отвечает за создание и обновления записи.
     * Так же самостоятельно устанавливает связи согласно описанным стандартам в Confluence.
     *
     * @param array $data
     * @param Model|null $model
     * @param int|null $id
     * @return Model
     */
    private function sync(array $data, ?Model $model = null, ?int $id = null): Model
    {
        $files = null;

        /**
         * Если в $data есть файл, то мы его вырезаем, что бы после создания модели мы могли к ней прикрепить этот файл.
         */
        if (!empty($data['files']) && is_array($data['files'])) {
            $files = $data['files'];
            unset($data['files']);
        }

        /**
         * Если мы не передаем $model в параметре, то мы используем текущую.
         * Подразумеваем что этот trait используется непосредственно в модели.
         *
         * @var Model $model
         */
        $model = $model ?: $this;

        $keyName = $model->getKeyName();

        /**
         * Если нам нужно обновлять не по $primaryKey, а по другому полю
         */
        if (isset($this->keyForStore) && !empty($data[$this->keyForStore])) {
            $id = $data[$this->keyForStore];
            $keyName = $this->keyForStore;
        }

        $updateOrCreate = $this->filterData($data, $model);

        if ($id && $model::where($keyName, $id)->exists()) {
            $model = $model::where($keyName, $id)->first();
            $model->update($updateOrCreate);
        } else {
            $model = $model::create($updateOrCreate);
        }

        $this->relationsSync($model, $data);

        if (!empty($files)) {
            $this->uploadFiles($files, $model);
        }

        return $model;
    }

    /**
     * Здесь мы фильтруем входные данные.
     * Получаем только те ключи которые объявлены в model -> fillable, все остальное удаляем.
     *
     * @param array $data
     * @param Model $model
     * @return array
     */
    private function filterData(array $data, Model $model): array
    {
        return array_filter(
            $data,
            function ($key) use ($model) {
                return in_array($key, $model->getFillable(), true);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Здесь мы создаем связи, если они есть в model.
     *
     * @param Model $model
     * @param array $data
     */
    private function relationsSync(Model $model, array $data): void
    {
        foreach ($data as $key => $val) {
            $relatedName = Str::camel($key);

            if (!method_exists($model, $relatedName)) {
                continue;
            }

            $related = $model->{$relatedName}();

            if ($related instanceof BelongsToMany) {
                $this->syncBelongsToMany($related, $val);
            } elseif ($related instanceof HasMany) {
                $this->syncHasMany($related, $val);
            } elseif ($related instanceof HasOne || $related instanceof BelongsTo) {
                $model->update([$key . "_id" => empty($val['id']) ? null : $val['id']]);
            }
        }
    }

    /**
     * Метод sync удаляет все записи и создает все записи заново.
     * Поэтому этот метод определяет сам что нужно удалить, а что создать.
     *
     * @param $related
     * @param $val
     */
    private function syncBelongsToMany($related, $val): void
    {
        $existingIds = $related->get()->pluck('id')->toArray();
        $ids = $this->getIds($val);

        if (empty($ids)) {
            $related->sync([]);
        }

        if (empty($existingIds) || !$this->syncDetaching) {
            $related->sync($ids, $this->syncDetaching);

            return;
        }

        $attach = array_diff($ids, $existingIds);
        $detach = array_diff($existingIds, $ids);

        if (!empty($attach)) {
            $related->sync($attach, false);
        }

        if (!empty($detach)) {
            $related->detach($detach);
        }
    }

    /**
     * @param array $array
     * @return array
     */
    private function getIds(array $array): array
    {
        if (!Arr::has($array, 'id')) {
            $array = Arr::pluck($array, 'id');

            if (!empty(array_filter($array))) {
                return $array;
            }
        }

        return [];
    }

    /**
     * Метод для установки или разрушения связи по HasMany.
     * Поэтому этот метод определяет сам что нужно удалить, а что создать.
     *
     * @param $related
     * @param $val
     */
    private function syncHasMany(HasMany $related, $val): void
    {
        $existingIds = $related->get()->pluck($related->getLocalKeyName())->toArray();
        $ids = $this->getIds($val);

        if (empty($ids)) {
            return;
        }

        if (empty($existingIds)) {
            $related->saveMany($related->getModel()->whereIn($related->getLocalKeyName(), $ids)->get());

            return;
        }

        $attach = array_diff($ids, $existingIds);
        $detach = array_diff($existingIds, $ids);

        if (!empty($attach)) {
            $related->saveMany($related->getModel()->whereIn($related->getLocalKeyName(), $attach)->get());
        }

        if (!empty($detach)) {
            $related->getModel()
                ->whereIn($related->getLocalKeyName(), $detach)
                ->update([$related->getForeignKeyName() => null]);
        }
    }

    /**
     * Загружаем файл и создаем с ним связь.
     *
     * @param array $files
     * @param Model $model
     */
    private function uploadFiles(array $files, Model $model): void
    {
        try {
            foreach ($files as $val) {
                if (empty($model->id)) {
                    continue;
                }

                //Здесь реализуем метод загрузки файла
                //Например: app(FileService::class)->store(new Request($val));
            }
        } catch (Exception $e) {
            Log::error(
                'При сохранении файла произошла ошибка: ' . $e->getMessage()
            );
        }
    }

    /**
     * Если вы не хотите отделять существующие идентификаторы, которые отсутствуют в данном массиве
     *
     * @param bool $val
     * @return $this
     */
    public function syncDetaching(bool $val = false): self
    {
        $this->syncDetaching = $val;

        return $this;
    }

    /**
     * Метод, который отвечает за обновление записи и всех связей.
     *
     * @param int $id
     * @param array $data
     * @param Model|null $model
     * @return Model
     */
    public function updateItem(int $id, array $data, ?Model $model = null): Model
    {
        return $this->sync($data, $model, $id);
    }

    /**
     * Метод, который отвечает за удаления записи и ее связей если они передаются в REQUEST
     * Если в REQUEST есть параметр force, то мы удаляем полностью запись, доступно только роли super-admin
     *
     * @param int $id
     * @param Model|null $model
     * @return bool
     */
    public function deleteItem(int $id, ?Model $model = null): bool
    {
        /** @var Model $model */
        $model = $model ?: $this;

        $item = $model->where('id', $id)->first();

        if (!$item) {
            return false;
        }

        $data = request()->all();

        if (!empty($data)) {
            $this->relationDetach($item, $data);
            return true;
        }

        $this->deleteItems($item);

        return true;
    }

    /**
     * Обнуляем или удаляем связи с моделью.
     *
     * @param Model $model
     * @param array $data
     */
    private function relationDetach(Model $model, array $data): void
    {
        foreach ($data as $key => $val) {
            $relatedName = Str::camel($key);
            if (method_exists($this, $relatedName) === false) {
                continue;
            }

            $related = $model->{$relatedName}();
            $m = get_class($related->getModel());

            switch (true) {
                case $related instanceof BelongsToMany:
                    foreach ($val as $v) {
                        $related->detach([$v]);
                    }
                    break;
                case $related instanceof HasMany:
                    foreach ($val as $v) {
                        (new $m())->where('id', $v)->update([$related->getForeignKeyName() => null]);
                    }
                    break;
                case ($related instanceof HasOne):
                    $k = $related->getForeignKeyName() === 'id'
                        ? $related->getLocalKeyName()
                        : $related->getForeignKeyName();

                    $model->update([$k => null]);
                    break;
                case ($related instanceof BelongsTo):
                    if ($related->getForeignKeyName() === 'id') {
                        foreach ($val as $v) {
                            (new $m())->where('id', $v)->update([$related->getOwnerKeyName() => null]);
                        }
                    } else {
                        $model->update([$related->getForeignKeyName() => null]);
                    }
                    break;
            }
        }
    }

    /**
     * Удаляем запись и файл если он есть.
     *
     * @param Model $item
     */
    private function deleteItems(Model $item): void
    {
        if (!request()->input('force')) {
            if ($item instanceof File) {
                $item->delete();
                return;
            }

            if (method_exists($item, 'files')) {
                $item->files()->delete();
            }

            $item->delete();

            return;
        }

        if ($item instanceof File) {
            $item->forceDelete();
            return;
        }

        if (method_exists($item, 'files')) {
            $item->files()->forceDelete();
        }

        $item->forceDelete();
    }
}

<?php

namespace App\Traits;


use App\Exceptions\API\SuccessException;
use App\Models\DealLocation;
use App\Models\File;
use App\Models\User;
use App\Services\API\FileService;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait TemplateModel
{
    /**
     * @var bool
     */
    protected $syncDetaching = true;

    /**
     * Файлы у нас работаю глобально, это связь для каждой таблицы.
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
    public function tmStore(array $data, ?Model $model = null): Model
    {
        return $this->tmSync($data, $model);
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
    private function tmSync(array $data, ?Model $model = null, ?int $id = null): Model
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
         * Это нужно для старой системе, когда в пост мы передавали ID, если он был то мы обновляли запись если нет то создавали.
         * Нужно удалить когда на фронте для обновления будет использоваться PUT, а не POST
         */
        if (!$id && !empty($data['id'])) {
            $id = $data['id'];
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

        $updateOrCreate = $this->tmFilterData($data, $model);

        if ($id && $model::where($keyName, $id)->exists()) {
            $model = $model::where($keyName, $id)->first();
            $model->update($updateOrCreate);
        } else {
            $model = $model::create($updateOrCreate);
        }

        $this->tmRelationsSync($model, $data);

        if (!empty($files)) {
            $this->tmUploadFiles($files, $model);
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
    private function tmFilterData(array $data, Model $model): array
    {
        return array_filter(
            $data,
            function ($key) use ($model) {
                return in_array($key, $model->getFillable(), true);
            }
            ,
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Здесь мы создаем связи, если они есть в model.
     *
     * @param Model $model
     * @param array $data
     */
    private function tmRelationsSync(Model $model, array $data): void
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
                if ($related->getModel() instanceof DealLocation) {
                    $this->syncHasMany($related, $val);
                }
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
        $ids = $this->tmGetIds($val);

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
    private function tmGetIds(array $array): array
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
        $ids = $this->tmGetIds($val);

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
    private function tmUploadFiles(array $files, Model $model): void
    {
        try {
            foreach ($files as $val) {
                if (empty($model->id)) {
                    continue;
                }

                $val['model_id'] = $model->id;
                $val['model'] = get_class($model);

                if (isset($val['tmp_path'])) {
                    $val['files'] = [$val['tmp_path']];
                }

                (new FileService())->store(new Request($val));
            }
        } catch (Exception $e) {
            Log::error(
                'При сохранении файла в классе App\Traits\TemplateModel произошла ошибка: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param $data
     * @param bool $syncDetaching
     * @return Model
     * @see tmStore
     * @deprecated
     */
    public function store($data, bool $syncDetaching = true): Model
    {
        if ($data instanceof Request) {
            $data = $data->all();
        }

        return $this->syncDetaching($syncDetaching)->tmSync($data);
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
    public function tmUpdate(int $id, array $data, ?Model $model = null): Model
    {
        return $this->tmSync($data, $model, $id);
    }

    /**
     * @param int $id
     *
     * @see tmDelete
     * @deprecated
     */
    public function deletion(int $id): void
    {
        $delete = $this->tmDelete($id);

        if ($delete) {
            throw new SuccessException('Успешно удалено', 200);
        }

        throw new Exception('Не найдено', 404);
    }

    /**
     * Метод, который отвечает за удаления записи и ее связей если они передаются в REQUEST
     * Если в REQUEST есть параметр force, то мы удаляем полностью запись, доступно только роли super-admin
     *
     * @param int $id
     * @param Model|null $model
     * @return bool
     */
    public function tmDelete(int $id, ?Model $model = null): bool
    {
        /** @var Model $model */
        $model = $model ?: $this;

        $item = $model->where('id', $id)->first();

        if (!$item) {
            return false;
        }

        $data = request()->all();

        if (!empty($data)) {
            $this->tmRelationDetach($item, $data);
            return true;
        }

        $this->tmDeleteItems($item);

        return true;
    }

    /**
     * Обнуляем или удаляем связи с моделью.
     *
     * @param Model $model
     * @param array $data
     */
    private function tmRelationDetach(Model $model, array $data): void
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
    private function tmDeleteItems(Model $item): void
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

        /** @var User $roles */
        $roles = auth()->guard('api')->user();

        if ($roles && $roles->hasRole(User::ROLE_ADMIN)) {
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
}

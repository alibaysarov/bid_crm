<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Provides common Eloquent CRUD operations for a configured model class.
 *
 * @template TModel of Model
 */
abstract class AbstractCrudRepository
{
    /**
     * Create a repository for the supplied Eloquent model class.
     *
     * @param  class-string<TModel>  $modelClass
     */
    public function __construct(
        private readonly string $modelClass,
    ) {
        if (! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException('The repository model must extend Eloquent Model.');
        }
    }

    /**
     * Start a query for the configured model.
     *
     * @return Builder<TModel>
     */
    public function query(): Builder
    {
        $modelClass = $this->modelClass;

        return $modelClass::query();
    }

    /**
     * Find a model by its primary key or throw a model-not-found exception.
     *
     * @return TModel
     */
    public function findOrFail(int|string $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * Create and persist a model using validated attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function create(array $attributes): Model
    {
        return $this->query()->create($attributes);
    }

    /**
     * Update and persist a model identified by its primary key.
     *
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function update(int|string $id, array $attributes): Model
    {
        $model = $this->findOrFail($id);
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * Delete a model identified by its primary key.
     */
    public function delete(int|string $id): bool
    {
        return (bool) $this->findOrFail($id)->delete();
    }
}

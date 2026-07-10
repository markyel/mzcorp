<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Шаблон письма для вкладки «Переписка».
 *
 * Adjacency-list дерево (parent_id self-FK, как Request.inheritance_parent_id):
 *   is_folder=true  — папка (body игнорируется), группирует детей;
 *   is_folder=false — шаблон (body = PLAIN-TEXT тело письма).
 *
 * Загрузка всего дерева: LetterTemplate::roots()->with('childrenRecursive')->get().
 *
 * @property int $id
 * @property ?int $parent_id
 * @property bool $is_folder
 * @property string $name
 * @property ?string $subject
 * @property ?string $body
 * @property int $sort_order
 * @property ?int $created_by_user_id
 * @property ?int $updated_by_user_id
 */
class LetterTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'is_folder',
        'name',
        'subject',
        'body',
        'sort_order',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_folder' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Дочерние узлы: сначала папки, затем по sort_order и имени.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderByDesc('is_folder')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Рекурсивная загрузка поддерева (eager) для рендера всего дерева.
     */
    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** Корневые узлы (parent_id IS NULL). */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeFolders(Builder $query): Builder
    {
        return $query->where('is_folder', true);
    }

    public function scopeTemplates(Builder $query): Builder
    {
        return $query->where('is_folder', false);
    }
}

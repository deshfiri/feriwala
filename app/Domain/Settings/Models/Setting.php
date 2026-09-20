<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\SettingType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One configurable value.
 *
 * @property string $key
 * @property string $group
 * @property SettingType $type
 * @property bool $is_encrypted
 * @property bool $is_public
 */
class Setting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'is_encrypted' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * The stored value as it reads, decrypting first when the setting holds a
     * credential — a string, before its type is applied.
     */
    public function plainValue(): ?string
    {
        $raw = $this->getRawOriginal('value');

        if ($raw !== null && $this->is_encrypted) {
            $raw = decrypt($raw);
        }

        return $raw === null ? null : (string) $raw;
    }

    /**
     * The typed value, decrypting first when the setting holds a credential.
     */
    public function typedValue(): mixed
    {
        return $this->type->cast($this->plainValue());
    }

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopeInGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }
}

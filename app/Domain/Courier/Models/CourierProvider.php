<?php

namespace App\Domain\Courier\Models;

use App\Domain\Courier\Enums\CourierProviderCode;
use Illuminate\Database\Eloquent\Model;

/**
 * A seeded courier provider row (Advanced Order Management batch, Commit 5;
 * D8).
 *
 * @property int $id
 * @property CourierProviderCode $code
 * @property string $name
 * @property bool $is_enabled
 * @property bool $supports_api
 * @property bool $supports_webhook
 * @property bool $supports_label
 * @property string|null $credentials
 */
class CourierProvider extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => CourierProviderCode::class,
            'is_enabled' => 'boolean',
            'supports_api' => 'boolean',
            'supports_webhook' => 'boolean',
            'supports_label' => 'boolean',
            'credentials' => 'encrypted',
        ];
    }
}

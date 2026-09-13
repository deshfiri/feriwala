<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * The shared attributes variations are built from (§11.1).
 *
 * One rule matters more than the rest: a value a variation carries is never
 * removed from under it. Renaming is allowed — correcting "Nvy" to "Navy" is a
 * fix, not a change of meaning — but removal is refused while anything uses the
 * value, and the foreign key refuses it too if this check is ever bypassed.
 */
class ManageAttributes
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function create(User $actor, string $name): ProductAttribute
    {
        return $this->database->transaction(function () use ($actor, $name) {
            $attribute = ProductAttribute::create([
                'name' => $name,
                'sort_order' => (int) ProductAttribute::query()->max('sort_order') + 1,
            ]);

            $this->record($actor, 'catalog.attribute_created', ProductAttribute::class, $attribute->id, after: ['name' => $name]);

            return $attribute;
        });
    }

    public function rename(User $actor, ProductAttribute $attribute, string $name): ProductAttribute
    {
        $before = ['name' => $attribute->name];

        $attribute->forceFill(['name' => $name])->save();

        $this->record($actor, 'catalog.attribute_updated', ProductAttribute::class, $attribute->id, $before, ['name' => $name]);

        return $attribute;
    }

    /**
     * Remove an attribute and its values — only when no variation uses any of
     * them. The values go with it explicitly, never by cascade.
     *
     * @throws CatalogRefused
     */
    public function delete(User $actor, ProductAttribute $attribute): void
    {
        $this->database->transaction(function () use ($actor, $attribute) {
            $used = $this->database->table('product_variant_values')
                ->where('product_attribute_id', $attribute->id)
                ->count();

            if ($used > 0) {
                throw CatalogRefused::attributeInUse($used);
            }

            $values = $attribute->values()->pluck('value')->all();

            ProductAttributeValue::query()->where('product_attribute_id', $attribute->id)->delete();
            $attribute->delete();

            $this->record($actor, 'catalog.attribute_deleted', ProductAttribute::class, $attribute->id, [
                'name' => $attribute->name,
                'values' => $values,
            ]);
        });
    }

    public function addValue(User $actor, ProductAttribute $attribute, string $value): ProductAttributeValue
    {
        return $this->database->transaction(function () use ($actor, $attribute, $value) {
            $created = ProductAttributeValue::create([
                'product_attribute_id' => $attribute->id,
                'value' => $value,
                'sort_order' => (int) $attribute->values()->max('sort_order') + 1,
            ]);

            $this->record($actor, 'catalog.attribute_value_created', ProductAttributeValue::class, $created->id, after: [
                'attribute' => $attribute->name,
                'value' => $value,
            ]);

            return $created;
        });
    }

    public function renameValue(User $actor, ProductAttributeValue $value, string $text): ProductAttributeValue
    {
        $before = ['value' => $value->value];

        $value->forceFill(['value' => $text])->save();

        $this->record($actor, 'catalog.attribute_value_updated', ProductAttributeValue::class, $value->id, $before, ['value' => $text]);

        return $value;
    }

    /**
     * @throws CatalogRefused
     */
    public function deleteValue(User $actor, ProductAttributeValue $value): void
    {
        $this->database->transaction(function () use ($actor, $value) {
            $used = $this->database->table('product_variant_values')
                ->where('product_attribute_value_id', $value->id)
                ->count();

            if ($used > 0) {
                throw CatalogRefused::attributeInUse($used);
            }

            $value->delete();

            $this->record($actor, 'catalog.attribute_value_deleted', ProductAttributeValue::class, $value->id, ['value' => $value->value]);
        });
    }

    /**
     * @param  class-string  $type
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(
        User $actor,
        string $action,
        string $type,
        int $id,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: $type,
            auditableId: $id,
            before: $before,
            after: $after,
            module: 'catalog',
        ));
    }
}

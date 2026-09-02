<?php

namespace App\Concerns;

use App\Domain\Access\DataScopeResolver;
use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Enums\PermissionModule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts a model's queries to what the viewer is entitled to see.
 *
 * Applied to every model holding user-owned operational or financial data. The
 * restriction is a query concern, not a UI one: hiding a row in a template still
 * ships it to the browser and still returns it from an export (§31.3).
 *
 * Usage:
 *
 *     Order::visibleTo($request->user())->paginate();
 *
 * A model using this trait must declare which module governs it and which column
 * holds the owner, so neither is guessed.
 */
trait ScopedToViewer
{
    /**
     * The module whose permissions govern visibility of this model.
     */
    abstract public function scopeModule(): PermissionModule;

    /**
     * The column holding the owning user's id.
     */
    public function ownerColumn(): string
    {
        return 'user_id';
    }

    /**
     * Limit a query to the rows this viewer may see.
     *
     * A viewer with no entitlement gets `whereRaw('1 = 0')` rather than an
     * exception, so a list renders as empty rather than leaking — through an
     * error message — that rows exist at all.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $viewer): Builder
    {
        $scope = app(DataScopeResolver::class)->for($viewer, $this->scopeModule());

        return match ($scope) {
            DataScope::All => $query,
            DataScope::Own => $query->where(
                $this->qualifyColumn($this->ownerColumn()),
                $viewer?->getAuthIdentifier(),
            ),
            DataScope::None => $query->whereRaw('1 = 0'),
        };
    }
}

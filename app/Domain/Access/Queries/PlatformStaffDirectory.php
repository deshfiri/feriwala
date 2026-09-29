<?php

namespace App\Domain\Access\Queries;

use App\Domain\Access\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform staff -- Users holding no business account membership at all
 * (D23) -- for the Roles & Permissions staff directory (commit-order
 * item 6).
 *
 * A business account's own staff (an `AccountRole`, scoped to one account)
 * never appears here: this directory is the other scope entirely, the one
 * {@see PlatformRole} is held against.
 */
class PlatformStaffDirectory
{
    /**
     * @return Builder<User>
     */
    public function builder(?string $search = null): Builder
    {
        $term = trim((string) $search);

        return User::query()
            ->whereDoesntHave('accountMembership')
            ->when(
                $term !== '',
                fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                    ->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                    ->orWhere('email', 'ilike', '%'.addcslashes($term, '%_\\').'%')),
            )
            ->orderBy('name');
    }
}

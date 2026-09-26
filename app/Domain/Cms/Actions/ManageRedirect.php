<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Exceptions\CmsRedirectRefused;
use App\Domain\Cms\Models\Redirect;
use App\Models\User;

/**
 * Creates a public-site redirect (§34.1), refusing anything that would
 * chain into another redirect (which is also how a loop is refused — a
 * loop is just a chain that leads back to where it started). A property of
 * the *set* of rows, so it is checked fresh against the current table here
 * rather than in a CHECK constraint (which only ever sees one row at a
 * time). `from_path`/`to_path` being site-relative and never identical are
 * already enforced by the migration's own constraints.
 */
class ManageRedirect
{
    public function handle(string $fromPath, string $toPath, int $statusCode = 301, ?User $actor = null): Redirect
    {
        if ($this->wouldChain($fromPath, $toPath)) {
            throw CmsRedirectRefused::wouldChain($fromPath, $toPath);
        }

        return Redirect::query()->create([
            'from_path' => $fromPath,
            'to_path' => $toPath,
            'status_code' => $statusCode,
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * Changes where an existing redirect points, refusing the same chains a
     * new one would be refused for — checked against every other row, this
     * one excepted, since a row cannot chain into itself.
     */
    public function update(Redirect $redirect, string $toPath, int $statusCode = 301): Redirect
    {
        if ($this->wouldChain($redirect->from_path, $toPath, exceptId: $redirect->id)) {
            throw CmsRedirectRefused::wouldChain($redirect->from_path, $toPath);
        }

        $redirect->update(['to_path' => $toPath, 'status_code' => $statusCode]);

        return $redirect;
    }

    /**
     * Switches a redirect on or off without deleting its row — an operator
     * who needs it back does not have to remember the exact original values.
     */
    public function setEnabled(Redirect $redirect, bool $enabled): Redirect
    {
        $redirect->update(['is_enabled' => $enabled]);

        return $redirect;
    }

    /**
     * Every redirect must point at a real destination, never at another
     * redirect — so a later edit to that other row can never turn this one
     * into a loop. Refused whenever an existing row would put this new one
     * in a chain either direction: the destination is itself already a
     * redirect's source (a forward chain, and a loop back to `fromPath` is
     * the degenerate case of that), or an existing redirect already lands on
     * this source (a chain through it backward).
     */
    protected function wouldChain(string $fromPath, string $toPath, ?int $exceptId = null): bool
    {
        return Redirect::query()
            ->where('is_enabled', true)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->where(fn ($query) => $query
                ->where('from_path', $toPath)
                ->orWhere('to_path', $fromPath))
            ->exists();
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\ConfigureAuditRetention;
use App\Domain\Audit\AuditRetention;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How many days the activity log is kept before it is cleared automatically.
 *
 * Seen by anyone who may read the log; changed only with `audit.manage_settings`.
 * Zero keeps entries without limit.
 */
class AuditRetentionSettingsController extends Controller
{
    public function __construct(
        protected AuditRetention $retention,
        protected ConfigureAuditRetention $configure,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless($this->can($actor, PermissionAction::ViewAuditLogs), 403);

        return Inertia::render('admin/audit-retention-settings', [
            'settings' => ['days' => $this->retention->days() ?? 0],
            'maximum' => AuditRetention::MAXIMUM_DAYS,
            'can' => ['manage' => $this->can($actor, PermissionAction::ManageSettings)],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->can($actor, PermissionAction::ManageSettings), 403);

        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:0', 'max:'.AuditRetention::MAXIMUM_DAYS],
        ]);

        $this->configure->handle($actor, (int) $validated['days']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('audit.retention.saved')]);

        return back();
    }

    protected function can(User $actor, PermissionAction $action): bool
    {
        return ! CatalogPolicy::isBusinessIdentity($actor)
            && $actor->can(PermissionCatalogue::name(PermissionModule::Audit, $action));
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}

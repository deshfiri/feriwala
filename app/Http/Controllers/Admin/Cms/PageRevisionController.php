<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Actions\RestoreRevision;
use App\Domain\Cms\Models\Page;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Rolling a page back to an earlier published revision (§34.1). Restoring
 * is a publish-like act — it makes a past version live again — so it
 * answers to the same `publish` ability as {@see PageController::publish()}.
 */
class PageRevisionController extends Controller
{
    public function restore(Request $request, Page $page, string $revision, RestoreRevision $restore): RedirectResponse
    {
        Gate::authorize('publish', $page);

        $record = $page->revisions()->wherePublicId($revision)->firstOrFail();

        /** @var User $actor */
        $actor = $request->user();

        $restore->handle($record, $actor);

        return back()->with('success', __('Page restored to version :version and published.', ['version' => $record->version]));
    }
}

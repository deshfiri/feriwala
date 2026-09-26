<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Actions\ManageRedirect;
use App\Domain\Cms\Exceptions\CmsRedirectRefused;
use App\Domain\Cms\Models\Redirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\SaveRedirectRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public-site redirects (§34.1). Loop and chain refusal is
 * {@see ManageRedirect}'s own job — this controller only translates that
 * refusal into a message an administrator can act on.
 */
class RedirectController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Redirect::class);

        $redirects = Redirect::query()->with('creator')->orderByDesc('id')->get();

        return Inertia::render('admin/cms/redirects/index', [
            'redirects' => $redirects->map(fn (Redirect $redirect) => [
                'id' => $redirect->public_id,
                'from_path' => $redirect->from_path,
                'to_path' => $redirect->to_path,
                'status_code' => $redirect->status_code,
                'is_enabled' => $redirect->is_enabled,
                'created_by' => $redirect->creator?->name,
            ]),

            'can' => [
                'create' => Gate::allows('create', Redirect::class),
            ],
        ]);
    }

    public function store(SaveRedirectRequest $request, ManageRedirect $manage): RedirectResponse
    {
        Gate::authorize('create', Redirect::class);

        $validated = $request->validated();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $manage->handle($validated['from_path'], $validated['to_path'], $validated['status_code'], $actor);
        } catch (CmsRedirectRefused $refused) {
            throw ValidationException::withMessages(['to_path' => $refused->getMessage()]);
        }

        return back()->with('success', __('Redirect created.'));
    }

    public function update(SaveRedirectRequest $request, string $redirect, ManageRedirect $manage): RedirectResponse
    {
        Gate::authorize('update', Redirect::class);

        $validated = $request->validated();

        try {
            $manage->update($this->redirectFor($redirect), $validated['to_path'], $validated['status_code']);
        } catch (CmsRedirectRefused $refused) {
            throw ValidationException::withMessages(['to_path' => $refused->getMessage()]);
        }

        return back()->with('success', __('Redirect saved.'));
    }

    public function setEnabled(Request $request, string $redirect, ManageRedirect $manage): RedirectResponse
    {
        Gate::authorize('update', Redirect::class);

        $validated = $request->validate(['is_enabled' => ['required', 'boolean']]);

        $manage->setEnabled($this->redirectFor($redirect), (bool) $validated['is_enabled']);

        return back()->with('success', __('Redirect saved.'));
    }

    public function destroy(string $redirect): RedirectResponse
    {
        Gate::authorize('delete', Redirect::class);

        $this->redirectFor($redirect)->delete();

        return back()->with('success', __('Redirect removed.'));
    }

    protected function redirectFor(string $redirect): Redirect
    {
        return Redirect::query()->wherePublicId($redirect)->firstOrFail();
    }
}

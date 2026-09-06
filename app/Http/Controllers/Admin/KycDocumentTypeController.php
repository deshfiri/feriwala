<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Kyc\Actions\ManageKycDocumentTypes;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycDocumentTypeScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\SaveKycDocumentTypeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The KYC requirement catalogue (§7.2).
 *
 * Platform configuration, not account data: there is no account in any of these
 * routes and no way to ask what one particular business is required to provide.
 * Scoping is expressed as rules about packages and countries, which is what
 * keeps one account's configuration from being visible to another.
 */
class KycDocumentTypeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', KycDocumentType::class);

        $types = KycDocumentType::query()
            ->with('scopes')
            ->withCount('submissionRequirements')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('admin/kyc/document-types', [
            'types' => $types->map(fn (KycDocumentType $type) => [
                'id' => $type->public_id,
                'key' => $type->key,
                'name' => $type->name,
                'instructions' => $type->instructions,
                'is_required' => $type->is_required,
                'is_active' => $type->is_active,
                'is_archived' => $type->isArchived(),
                'requires_file' => $type->requires_file,
                'requires_value' => $type->requires_value,
                'value_label' => $type->value_label,
                'accepted_mime_types' => $type->accepted_mime_types,
                'max_size_kb' => $type->max_size_kb,
                'sort_order' => $type->sort_order,

                // How many rounds have been opened against it — the figure that
                // explains why deletion is unavailable.
                'used_by_rounds' => $type->submission_requirements_count,

                'scopes' => $type->scopes->map(fn (KycDocumentTypeScope $scope) => [
                    'package' => $scope->package_slug,
                    'country' => $scope->country_code,
                    'is_required' => $scope->is_required,
                ])->values(),

                'can' => [
                    'update' => $actor->can('update', $type),
                    'delete' => $actor->can('delete', $type),
                ],
            ]),
            'can' => [
                'create' => $actor->can('create', KycDocumentType::class),
            ],
        ]);
    }

    public function store(
        SaveKycDocumentTypeRequest $request,
        ManageKycDocumentTypes $manage,
    ): RedirectResponse {
        Gate::authorize('create', KycDocumentType::class);

        $type = $manage->create($request->typeAttributes(), $this->actor($request));

        $this->syncScopes($type, $request->scopeRules());

        return back()->with('success', __('Document type created.'));
    }

    public function update(
        SaveKycDocumentTypeRequest $request,
        KycDocumentType $documentType,
        ManageKycDocumentTypes $manage,
    ): RedirectResponse {
        Gate::authorize('update', $documentType);

        try {
            $manage->update($documentType, $request->typeAttributes(), $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        $this->syncScopes($documentType, $request->scopeRules());

        return back()->with('success', __('Document type updated.'));
    }

    /**
     * Pause or resume a requirement without retiring it.
     */
    public function setActive(
        Request $request,
        KycDocumentType $documentType,
        ManageKycDocumentTypes $manage,
    ): RedirectResponse {
        Gate::authorize('update', $documentType);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        try {
            $manage->setActive($documentType, (bool) $validated['is_active'], $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['is_active' => $exception->getMessage()]);
        }

        return back()->with('success', __('Document type updated.'));
    }

    public function archive(
        Request $request,
        KycDocumentType $documentType,
        ManageKycDocumentTypes $manage,
    ): RedirectResponse {
        Gate::authorize('archive', $documentType);

        $manage->archive($documentType, $this->actor($request));

        return back()->with('success', __('Document type archived.'));
    }

    public function destroy(
        Request $request,
        KycDocumentType $documentType,
        ManageKycDocumentTypes $manage,
    ): RedirectResponse {
        Gate::authorize('delete', $documentType);

        try {
            $manage->delete($documentType, $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['type' => $exception->getMessage()]);
        }

        return back()->with('success', __('Document type deleted.'));
    }

    public function reorder(Request $request, ManageKycDocumentTypes $manage): RedirectResponse
    {
        Gate::authorize('create', KycDocumentType::class);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'string'],
        ]);

        $manage->reorder($validated['order'], $this->actor($request));

        return back()->with('success', __('Order saved.'));
    }

    /**
     * Replace a type's scope rules wholesale (§7.2).
     *
     * Replaced rather than merged: an administrator editing scopes is stating
     * the complete set. Merging would make removing a rule impossible through
     * the same form that added it.
     *
     * @param  array<int, array<string, mixed>>  $scopes
     */
    protected function syncScopes(KycDocumentType $type, array $scopes): void
    {
        $type->scopes()->delete();

        foreach ($scopes as $scope) {
            $type->scopes()->create([
                'package_slug' => $scope['package'] ?? null,
                'country_code' => isset($scope['country'])
                    ? mb_strtoupper((string) $scope['country'])
                    : null,
                'is_required' => $scope['is_required'] ?? null,
            ]);
        }
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Actions\AdoptLegacyCriteria;
use App\Domain\Kyc\Actions\BuildBatchDefinition;
use App\Domain\Kyc\Actions\CloneKycBatch;
use App\Domain\Kyc\Actions\GenerateKycKey;
use App\Domain\Kyc\Actions\ManageKycBatches;
use App\Domain\Kyc\Actions\PublishKycBatchVersion;
use App\Domain\Kyc\Actions\SaveKycBatchStructure;
use App\Domain\Kyc\Batch\KycPreviewRound;
use App\Domain\Kyc\Batch\KycRoundDefinition;
use App\Domain\Kyc\Batch\KycRoundPresenter;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Enums\KycCompletionRule;
use App\Domain\Kyc\Enums\KycEvidenceType;
use App\Domain\Kyc\Enums\KycSelfieMode;
use App\Domain\Kyc\Exceptions\InvalidKycBatch;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycBatchGroupRequirement;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycRequirementSlot;
use App\Domain\Package\Models\Package;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\SaveKycBatchStructureRequest;
use App\Http\Requests\Kyc\SaveKycBatchTemplateRequest;
use App\Models\User;
use App\Support\Localization\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * KYC batch templates (D30): the list, the builder, the preview, and the acts that
 * take a template live — clone, publish, activate, make default.
 *
 * Every action here uses the permission the criteria screen already uses
 * (`kyc.manage_settings`): who may change what an applicant is asked for is one
 * decision, whichever screen it is made on. A template is configuration, not
 * applicant data, so nothing here reads a submission, a document or an applicant.
 *
 * What is edited is the live structure; applicants and open rounds see only a
 * published version. The builder says plainly when the two differ.
 */
class KycBatchTemplateController extends Controller
{
    public function __construct(
        protected PublishKycBatchVersion $publisher,
        protected BuildBatchDefinition $definitions,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', KycBatch::class);

        $batches = KycBatch::query()
            ->whereNull('archived_at')
            ->withCount('groups')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('admin/kyc/batches/index', [
            'batches' => $batches->map(fn (KycBatch $batch) => [
                'id' => $batch->public_id,
                'name' => $batch->name,
                'name_bn' => $batch->name_bn,
                'audience' => $batch->audience->value,
                'is_active' => $batch->is_active,
                'is_default' => $batch->is_default,
                'selfie_mode' => $batch->selfie_mode->value,
                'groups_count' => $batch->groups_count,
                'published_version' => $batch->published_version,
                'has_unpublished_changes' => $this->publisher->hasUnpublishedChanges($batch),
                // The tree the batch is: groups, options, evidence slots.
                'outline' => $this->outline($batch),
            ])->all(),
            'audiences' => $this->audiences(),
            // Who is still shown the older individual-document form because no
            // batch is switched on as their default.
            'uncovered' => $this->uncoveredAudiences(),
            // What the viewer may do, so a control they would be refused is not drawn.
            'can' => [
                'create' => Gate::allows('create', KycBatch::class),
                'activate' => $this->holds(PermissionAction::Activate),
            ],
        ]);
    }

    public function store(SaveKycBatchTemplateRequest $request, ManageKycBatches $manage, GenerateKycKey $keys): RedirectResponse
    {
        Gate::authorize('create', KycBatch::class);

        $attributes = $request->batchAttributes();

        // Switched off until it has been built, previewed and activated: an
        // active batch is one applicants may be asked for.
        $batch = $manage->create([
            ...$attributes,
            'key' => $keys->forBatch((string) $attributes['name']),
            'is_active' => false,
        ], $request->ruleRows(), $this->actor($request));

        return to_route('admin.kyc.batch-builder.edit', $batch)->with('success', __('kyc.batch_builder.created'));
    }

    public function edit(KycBatch $batch, AdoptLegacyCriteria $adopt): Response
    {
        Gate::authorize('view', $batch);

        // A batch made on the older criteria screen has criteria but no groups.
        // Give them their place (additive, idempotent) so what is shown is the
        // whole hierarchy — and only for someone who may edit it anyway.
        if (Gate::allows('update', $batch)) {
            $adopt->handle($batch);
        }

        $batch->load(['scopes', 'groups.items.requirement.slots']);
        $definition = $this->definitions->handle($batch);

        return Inertia::render('admin/kyc/batches/edit', [
            'batch' => [
                'id' => $batch->public_id,
                'key' => $batch->key,
                'name' => $batch->name,
                'name_bn' => $batch->name_bn,
                'description' => $batch->description,
                'description_bn' => $batch->description_bn,
                'audience' => $batch->audience->value,
                'selfie_mode' => $batch->selfie_mode->value,
                'selfie_allows_upload' => $batch->selfie_allows_upload,
                'is_active' => $batch->is_active,
                'is_default' => $batch->is_default,
                'is_archived' => $batch->isArchived(),
                'published_version' => $batch->published_version,
                'has_unpublished_changes' => $this->publisher->hasUnpublishedChanges($batch),
                'rules' => $batch->scopes->map(fn ($scope) => [
                    'package' => $scope->package_public_id,
                    'country' => $scope->country_code,
                ])->values()->all(),
            ],
            'groups' => $batch->groups->map(fn (KycBatchGroup $group) => [
                'id' => $group->public_id,
                'name' => $group->name,
                'name_bn' => $group->name_bn,
                'description' => $group->description,
                'description_bn' => $group->description_bn,
                'selector_label' => $group->selector_label,
                'selector_label_bn' => $group->selector_label_bn,
                'rule' => $group->completion_rule->value,
                'minimum' => $group->minimum_required,
                'required' => $group->is_required,
                'requirements' => $group->items
                    ->filter(fn (KycBatchGroupRequirement $item) => $item->requirement !== null && ! $item->requirement->isArchived())
                    ->map(fn (KycBatchGroupRequirement $item) => $this->requirementPayload($item))
                    ->values()
                    ->all(),
            ])->all(),
            // Everything that would stop it being published, in one list.
            'problems' => $this->definitions->problems($definition),
            'outline' => $this->outline($batch),
            'can' => [
                'create' => Gate::allows('create', KycBatch::class),
                'update' => Gate::allows('update', $batch),
                'activate' => Gate::allows('activate', $batch),
            ],
            'options' => [
                'audiences' => $this->audiences(),
                'selfie_modes' => array_map(
                    fn (KycSelfieMode $mode) => ['value' => $mode->value, 'label' => __("kyc.batch_builder.selfie_modes.{$mode->value}")],
                    KycSelfieMode::cases(),
                ),
                'rules' => array_map(
                    fn (KycCompletionRule $rule) => ['value' => $rule->value, 'label' => __("kyc.batch_builder.rules.{$rule->value}")],
                    KycCompletionRule::cases(),
                ),
                // In the order a person thinks of them.
                'evidence_types' => array_map(
                    fn (KycEvidenceType $type) => ['value' => $type->value, 'label' => __("kyc.batch_builder.evidence_types.{$type->value}")],
                    [KycEvidenceType::Image, KycEvidenceType::File, KycEvidenceType::Pdf, KycEvidenceType::Value, KycEvidenceType::Declaration],
                ),
                'packages' => Package::query()->orderBy('name')->get(['public_id', 'name'])
                    ->map(fn (Package $package) => ['value' => $package->public_id, 'label' => $package->name])->all(),
                'countries' => app(Countries::class)->options(),
            ],
        ]);
    }

    public function update(SaveKycBatchTemplateRequest $request, KycBatch $batch, ManageKycBatches $manage): RedirectResponse
    {
        Gate::authorize('update', $batch);

        try {
            $manage->update($batch, $request->batchAttributes(), $request->ruleRows(), $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return back()->with('success', __('kyc.batch_builder.details_saved'));
    }

    public function structure(SaveKycBatchStructureRequest $request, KycBatch $batch, SaveKycBatchStructure $save): RedirectResponse
    {
        Gate::authorize('update', $batch);

        try {
            $save->handle($batch, $request->groups(), $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['groups' => $exception->getMessage()]);
        }

        return back()->with('success', __('kyc.batch_builder.structure_saved'));
    }

    /**
     * The batch as an applicant would meet it, drawn by the applicant's own form
     * from the live structure. Nothing is stored and no one is asked anything.
     */
    public function preview(Request $request, KycBatch $batch, KycRoundPresenter $presenter): Response
    {
        Gate::authorize('view', $batch);

        $locale = $request->query('as') === 'bn' ? 'bn' : 'en';
        $previous = app()->getLocale();

        app()->setLocale($locale);

        try {
            $definition = new KycRoundDefinition($this->definitions->handle($batch), $batch->id, $batch->published_version + 1);
            $form = $presenter->present(new KycPreviewRound($definition));

            // Drawn as an applicant would see it, controls and all; the page
            // switches every control off, and the preview round refuses to store.
            if ($form !== null) {
                $form['editable'] = true;
            }
        } finally {
            app()->setLocale($previous);
        }

        return Inertia::render('admin/kyc/batches/preview', [
            'batch' => [
                'id' => $batch->public_id,
                'name' => $batch->name,
                'audience' => $batch->audience->value,
            ],
            'form' => $form,
            'as' => $locale,
            'problems' => $this->definitions->problems($definition->data),
        ]);
    }

    public function clone(Request $request, KycBatch $batch, CloneKycBatch $clone): RedirectResponse
    {
        Gate::authorize('create', KycBatch::class);
        Gate::authorize('view', $batch);

        $copy = $clone->handle($batch, $this->actor($request));

        return to_route('admin.kyc.batch-builder.edit', $copy)->with('success', __('kyc.batch_builder.cloned'));
    }

    /**
     * Freeze the current structure as a new version. Applicants and rounds opened
     * from now on get it; rounds already open keep the version they have.
     */
    public function publish(Request $request, KycBatch $batch): RedirectResponse
    {
        Gate::authorize('activate', $batch);

        try {
            $version = $this->publisher->handle($batch, $this->actor($request));
        } catch (InvalidKycBatch $exception) {
            throw ValidationException::withMessages(['publish' => $exception->problems]);
        }

        return back()->with('success', __('kyc.batch_builder.published', ['version' => $version->version]));
    }

    /**
     * Switch on (publishing first, so what goes live is what was previewed) or off.
     */
    public function active(Request $request, KycBatch $batch, ManageKycBatches $manage): RedirectResponse
    {
        Gate::authorize('activate', $batch);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        try {
            $validated['is_active']
                ? $manage->activate($batch, $this->actor($request))
                : $manage->setActive($batch, false, $this->actor($request));
        } catch (InvalidKycBatch $exception) {
            throw ValidationException::withMessages(['publish' => $exception->problems]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['is_active' => $exception->getMessage()]);
        }

        return back()->with('success', __('kyc.batch_builder.status_saved'));
    }

    public function default(Request $request, KycBatch $batch, ManageKycBatches $manage): RedirectResponse
    {
        Gate::authorize('activate', $batch);

        $validated = $request->validate(['is_default' => ['required', 'boolean']]);

        try {
            $manage->setDefault($batch, (bool) $validated['is_default'], $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['is_default' => $exception->getMessage()]);
        }

        return back()->with('success', __('kyc.batch_builder.default_saved'));
    }

    /**
     * The audiences a new round would still open on the older flow: Partners and
     * Suppliers each need an available default of their own, or one for both.
     *
     * @return array<int, string> 'account' and/or 'supplier'
     */
    protected function uncoveredAudiences(): array
    {
        $defaults = KycBatch::query()->available()->where('is_default', true)->pluck('audience')
            ->map(fn ($audience) => $audience instanceof KycAudience ? $audience->value : (string) $audience)
            ->all();

        $both = in_array(KycAudience::Both->value, $defaults, true);

        return array_values(array_filter([
            $both || in_array(KycAudience::Account->value, $defaults, true) ? null : KycAudience::Account->value,
            $both || in_array(KycAudience::Supplier->value, $defaults, true) ? null : KycAudience::Supplier->value,
        ]));
    }

    /**
     * The batch as a tree — its groups, the options in each, the evidence slots in
     * each option — in the reader's language. Built from the live structure, so it
     * is what publishing would freeze.
     *
     * @return array{groups: array<int, array<string, mixed>>}
     */
    protected function outline(KycBatch $batch): array
    {
        $definition = $this->definitions->handle($batch);

        return ['groups' => array_map(fn (array $group) => [
            'name' => KycRoundDefinition::localized($group, 'name'),
            'rule' => $group['rule'],
            'minimum' => $group['minimum'],
            'required' => $group['required'],
            'options' => array_map(fn (array $option) => [
                'name' => KycRoundDefinition::localized($option, 'name'),
                'required' => $option['required'],
                'slots' => array_map(fn (array $slot) => [
                    'label' => KycRoundDefinition::localized($slot, 'label'),
                    'evidence' => $slot['evidence'],
                    'required' => $slot['required'],
                    'sensitive' => $slot['sensitive'],
                ], $option['slots']),
            ], $group['requirements']),
        ], $definition['groups'])];
    }

    /**
     * @return array<string, mixed>
     */
    protected function requirementPayload(KycBatchGroupRequirement $item): array
    {
        /** @var KycDocumentType $type */
        $type = $item->requirement;
        $slots = $type->slots()->active()->get();

        return [
            'id' => $type->public_id,
            'name' => $type->name,
            'name_bn' => $type->name_bn,
            'instructions' => $type->instructions,
            'instructions_bn' => $type->instructions_bn,
            'required' => $item->is_required,
            // A requirement from before slots existed shows the slots its own
            // columns imply, so the builder never opens it empty.
            'slots' => $slots->isEmpty()
                ? array_map(fn (array $slot) => $this->derivedSlotPayload($slot), KycRequirementSlot::derivedFor($type))
                : $slots->map(fn (KycRequirementSlot $slot) => [
                    'id' => $slot->public_id,
                    'label' => $slot->label,
                    'label_bn' => $slot->label_bn,
                    'instructions' => $slot->instructions,
                    'instructions_bn' => $slot->instructions_bn,
                    'required' => $slot->is_required,
                    'evidence' => $slot->evidence_type->value,
                    'mimes' => $slot->accepted_mime_types ?? [],
                    'max_kb' => $slot->max_size_kb,
                    'value_label' => $slot->value_label,
                    'value_label_bn' => $slot->value_label_bn,
                    'sensitive' => $slot->is_sensitive,
                ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    protected function derivedSlotPayload(array $slot): array
    {
        return [
            'id' => null,
            'label' => $slot['label'],
            'label_bn' => $slot['label_bn'],
            'instructions' => $slot['instructions'],
            'instructions_bn' => $slot['instructions_bn'],
            'required' => $slot['required'],
            'evidence' => $slot['evidence'],
            'mimes' => $slot['mimes'],
            'max_kb' => $slot['max_kb'],
            'value_label' => $slot['value_label'],
            'value_label_bn' => $slot['value_label_bn'],
            'sensitive' => $slot['sensitive'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function audiences(): array
    {
        return array_map(
            fn (KycAudience $audience) => ['value' => $audience->value, 'label' => $audience->label()],
            KycAudience::cases(),
        );
    }

    protected function holds(PermissionAction $action): bool
    {
        return (bool) auth()->user()?->can(PermissionCatalogue::name(PermissionModule::KycBatch, $action));
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}

<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Kyc\Enums\KycCompletionRule;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

/**
 * What an applicant may do to a round's evidence, and what refuses them (D30).
 *
 * Every rule lives here, once, for both audiences: the controllers only translate
 * a refusal into a form error. Judged against the round's batch version — never
 * the live batch — so a loosened size limit or a removed option cannot reach a
 * round already open.
 *
 * Choosing between options is part of storing evidence. Putting a file into an
 * option of an "any one" group *is* choosing it: the other options' draft files
 * are cleared, because a submission that says "passport" must not carry a NID
 * photograph a reviewer would have to wonder about.
 */
final class RecordKycEvidence
{
    /**
     * @param  array<string, mixed>|null  $captureMeta  safe technical facts about a live capture
     */
    public function file(
        KycRound $round,
        string $requirement,
        string $slot,
        UploadedFile $file,
        bool $capturedLive = false,
        ?array $captureMeta = null,
    ): void {
        [$definition, $slotDefinition] = $this->prepare($round, $requirement, $slot);

        if (! in_array($slotDefinition['evidence'], ['file', 'image', 'pdf'], true)) {
            throw KycEvidenceRefused::wrongKind();
        }

        if ($requirement === KycRoundDefinition::SELFIE_REQUIREMENT && ! $capturedLive && ! $definition->selfieAllowsUpload()) {
            // The camera, not a picked file. The browser says it came from the
            // camera, and that is all the server can know — so this stops a
            // casual upload, and the reviewer still looks at the face.
            throw KycEvidenceRefused::liveOnly();
        }

        $this->guardFile($slotDefinition, $file);

        $this->select($round, $definition, $requirement);

        $round->attachFile(
            $requirement,
            $slot,
            $file,
            $requirement === KycRoundDefinition::SELFIE_REQUIREMENT && $capturedLive,
            $requirement === KycRoundDefinition::SELFIE_REQUIREMENT && $capturedLive ? $captureMeta : null,
        );
    }

    public function value(KycRound $round, string $requirement, string $slot, string $value): void
    {
        [$definition, $slotDefinition] = $this->prepare($round, $requirement, $slot);

        if (! in_array($slotDefinition['evidence'], ['value', 'declaration'], true)) {
            throw KycEvidenceRefused::wrongKind();
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 255) {
            throw KycEvidenceRefused::emptyValue();
        }

        $this->select($round, $definition, $requirement);

        // A declaration is a tick: its stored value is the fact that it was made.
        $round->saveValue($requirement, $slot, $slotDefinition['evidence'] === 'declaration' ? '1' : $value);
    }

    /**
     * Take a slot's evidence off the round, as long as a reviewer has not
     * accepted it.
     */
    public function remove(KycRound $round, string $requirement, string $slot): void
    {
        $this->prepare($round, $requirement, $slot);

        $round->discard($requirement, $slot);
    }

    /**
     * Choose an option in an "any one" or "minimum" group, before uploading
     * anything — so the form can show what that option needs.
     */
    public function choose(KycRound $round, string $group, string $requirement): void
    {
        $definition = $this->editable($round);
        $groupDefinition = $definition->group($group);

        if ($groupDefinition === null || ($definition->groupOf($requirement)['key'] ?? null) !== $group) {
            throw KycEvidenceRefused::unknownOption();
        }

        if (! KycCompletionRule::from($groupDefinition['rule'])->choosesBetweenOptions()) {
            throw KycEvidenceRefused::notChoosable();
        }

        $this->select($round, $definition, $requirement);
    }

    /**
     * Withdraw an option from a "minimum" group, taking its draft evidence with
     * it. An "any one" group changes by choosing another option instead.
     */
    public function deselect(KycRound $round, string $group, string $requirement): void
    {
        $definition = $this->editable($round);
        $groupDefinition = $definition->group($group);

        if ($groupDefinition === null || ($definition->groupOf($requirement)['key'] ?? null) !== $group) {
            throw KycEvidenceRefused::unknownOption();
        }

        if ($groupDefinition['rule'] !== KycCompletionRule::Minimum->value) {
            throw KycEvidenceRefused::notChoosable();
        }

        $option = $definition->requirement($requirement);

        if ($option === null) {
            throw KycEvidenceRefused::unknownOption();
        }

        if ($this->optionLocked($round, $option)) {
            throw KycEvidenceRefused::optionLocked();
        }

        $this->clearOption($round, $option);

        $selections = $round->selections();
        $selections[$group] = array_values(array_diff($selections[$group] ?? [], [$requirement]));

        $round->saveSelections(array_filter($selections));
    }

    /**
     * @return array{0: KycRoundDefinition, 1: array<string, mixed>}
     */
    private function prepare(KycRound $round, string $requirement, string $slot): array
    {
        $definition = $this->editable($round);
        $slotDefinition = $definition->slot($requirement, $slot);

        if ($slotDefinition === null) {
            throw KycEvidenceRefused::unknownSlot();
        }

        $current = $round->evidence()[KycEvidenceItem::keyFor($requirement, $slot)] ?? null;

        // Accepted evidence stays accepted. Only staff invalidating it — which
        // is a rejection — lets it be replaced.
        if ($current?->isAccepted()) {
            throw KycEvidenceRefused::alreadyAccepted();
        }

        return [$definition, $slotDefinition];
    }

    private function editable(KycRound $round): KycRoundDefinition
    {
        $definition = $round->definition();

        if ($definition === null) {
            throw KycEvidenceRefused::legacyRound();
        }

        if (! $round->isEditable()) {
            throw KycEvidenceRefused::locked();
        }

        return $definition;
    }

    /**
     * Record that the applicant is using this option, clearing the alternatives
     * an "any one" group cannot also hold.
     */
    private function select(KycRound $round, KycRoundDefinition $definition, string $requirement): void
    {
        if ($requirement === KycRoundDefinition::SELFIE_REQUIREMENT) {
            return;
        }

        $group = $definition->groupOf($requirement);

        if ($group === null) {
            throw KycEvidenceRefused::unknownOption();
        }

        $rule = KycCompletionRule::from($group['rule']);

        if (! $rule->choosesBetweenOptions()) {
            return;
        }

        $selections = $round->selections();
        $chosen = $selections[$group['key']] ?? [];

        if (in_array($requirement, $chosen, true) && ($rule === KycCompletionRule::Minimum || count($chosen) === 1)) {
            return;
        }

        if ($rule === KycCompletionRule::Any) {
            foreach ($group['requirements'] as $other) {
                if ($other['key'] === $requirement) {
                    continue;
                }

                if ($this->optionLocked($round, $other)) {
                    // Submitted, and not rejected: staff has to ask for it again.
                    throw KycEvidenceRefused::optionLocked();
                }
            }

            foreach ($group['requirements'] as $other) {
                if ($other['key'] !== $requirement) {
                    $this->clearOption($round, $other);
                }
            }

            $selections[$group['key']] = [$requirement];
        } else {
            $selections[$group['key']] = array_values(array_unique([...$chosen, $requirement]));
        }

        $round->saveSelections($selections);
    }

    /**
     * Whether an option can no longer be walked away from: its round has been
     * submitted and it holds evidence no reviewer has rejected.
     *
     * @param  array<string, mixed>  $option
     */
    private function optionLocked(KycRound $round, array $option): bool
    {
        if (! $round->hasBeenSubmitted()) {
            return false;
        }

        foreach ($round->evidence() as $item) {
            if ($item->requirement === $option['key'] && ! $item->isRejected()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $option
     */
    private function clearOption(KycRound $round, array $option): void
    {
        foreach ($option['slots'] as $slot) {
            $round->discard($option['key'], $slot['key']);
        }
    }

    /**
     * Format, extension and size against the slot as the round was opened with
     * it. The extension has to belong to an accepted format as well as the MIME
     * type, so a script cannot ride in under a document's type.
     *
     * @param  array<string, mixed>  $slot
     */
    private function guardFile(array $slot, UploadedFile $file): void
    {
        $accepted = $slot['mimes'] !== [] ? $slot['mimes'] : ['image/jpeg', 'image/png', 'application/pdf'];
        $mime = (string) $file->getMimeType();
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());

        $extensions = [];

        foreach ($accepted as $type) {
            $extensions = [...$extensions, ...(new MimeTypes)->getExtensions($type)];
        }

        if (! in_array($mime, $accepted, true) || ! in_array($extension, $extensions, true)) {
            throw KycEvidenceRefused::format($mime, $accepted);
        }

        $limit = (int) ($slot['max_kb'] ?? 5120);
        $sizeKb = (int) ceil(((int) $file->getSize()) / 1024);

        if ($sizeKb > $limit) {
            throw KycEvidenceRefused::tooLarge($sizeKb, $limit);
        }
    }
}

<?php

namespace App\Domain\Cases\Queries;

use App\Domain\Cases\Models\CaseEvidence;
use Illuminate\Support\Facades\URL;

/**
 * Case evidence as a screen reads it (D35).
 *
 * **Never a storage path, a disk or a credential.** A file is reached only
 * through a signed link to the ERP's own route, which checks the signed-in
 * person's right to the case on every use and expires in minutes; the signature
 * says the link was issued by the ERP, not that whoever holds it may open it.
 */
class EvidencePayload
{
    /**
     * @return array<string, mixed>
     */
    public function for(CaseEvidence $evidence): array
    {
        $evidence->loadMissing(['file', 'uploader:id,name']);

        $minutes = max(1, (int) config('case_evidence.link_minutes'));
        $expires = now()->addMinutes($minutes);
        $file = $evidence->file;

        // An erased file keeps its place in the history and offers nothing to open.
        $mime = $file === null ? 'application/octet-stream' : $file->mime_type;

        return [
            'id' => $evidence->public_id,
            'name' => $evidence->display_name,
            'purpose' => $evidence->purpose->value,
            'purpose_label' => $evidence->purpose->label(),
            'uploaded_by' => $evidence->uploader_type,
            'note' => $evidence->note,
            'mime_type' => $mime,
            'is_image' => str_starts_with($mime, 'image/'),
            'size_bytes' => $file === null ? 0 : $file->size_bytes,
            'draft' => $evidence->isDraft(),
            'erased' => $file === null,
            'uploaded_at' => $evidence->uploaded_at->toIso8601String(),
            'preview_url' => $file === null ? null : URL::temporarySignedRoute('cases.evidence.show', $expires, ['evidence' => $evidence->public_id]),
            'download_url' => $file === null ? null : URL::temporarySignedRoute('cases.evidence.show', $expires, ['evidence' => $evidence->public_id, 'download' => 1]),
        ];
    }

    /**
     * The evidence the earlier, reference-only way recorded, shown as it was
     * written: a reference and nothing to open.
     *
     * @param  array<int, mixed>|null  $references
     * @return list<array{reference: string, type: string|null, note: string|null}>
     */
    public function legacy(?array $references): array
    {
        $shown = [];

        foreach ($references ?? [] as $reference) {
            if (is_array($reference) && isset($reference['reference'])) {
                $shown[] = [
                    'reference' => (string) $reference['reference'],
                    'type' => isset($reference['type']) ? (string) $reference['type'] : null,
                    'note' => isset($reference['note']) ? (string) $reference['note'] : null,
                ];
            } elseif (is_string($reference) && $reference !== '') {
                $shown[] = ['reference' => $reference, 'type' => null, 'note' => null];
            }
        }

        return $shown;
    }
}

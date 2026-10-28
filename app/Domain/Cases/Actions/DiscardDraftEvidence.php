<?php

namespace App\Domain\Cases\Actions;

use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Storage\Actions\DeleteManagedFile;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Take back an unsent attachment (D35).
 *
 * Only the uploader's own draft, and only while it is a draft: evidence that was
 * sent with a request is part of that case's record and is never removed — by
 * this, by the database, or by anyone. The stored file goes with the draft.
 */
class DiscardDraftEvidence
{
    public function __construct(
        protected DeleteManagedFile $files,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws CaseEvidenceRefused
     */
    public function handle(User $actor, CaseEvidence $evidence): void
    {
        if ($evidence->uploader_id !== $actor->id) {
            throw CaseEvidenceRefused::notYourDraft();
        }

        if (! $evidence->isDraft()) {
            throw CaseEvidenceRefused::alreadySubmitted();
        }

        $this->database->transaction(function () use ($evidence) {
            $file = $evidence->file;

            $evidence->delete();

            // A draft always has its file (only an erased, sent record has none).
            if ($file !== null) {
                $this->files->handle($file);
            }
        });
    }
}

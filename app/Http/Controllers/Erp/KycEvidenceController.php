<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\RecordsKycEvidence;
use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Batch\KycRound;
use App\Domain\Kyc\Batch\PartnerKycRound;
use App\Domain\Kyc\KycDocumentStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The Client/Partner's evidence endpoints for a round on a KYC batch (D30).
 *
 * The round is the signed-in person's own account's open draft — resolved from
 * their membership, never from the URL.
 */
class KycEvidenceController extends Controller
{
    use RecordsKycEvidence;
    use ResolvesBusinessAccount;

    public function __construct(
        protected OpenKycDraft $openDraft,
        protected KycDocumentStore $store,
    ) {}

    protected function evidenceRound(Request $request): KycRound
    {
        return new PartnerKycRound(
            $this->openDraft->handle($this->businessAccountFor($request)),
            $this->store,
        );
    }
}

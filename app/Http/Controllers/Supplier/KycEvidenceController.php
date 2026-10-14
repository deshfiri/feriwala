<?php

namespace App\Http\Controllers\Supplier;

use App\Concerns\RecordsKycEvidence;
use App\Domain\Kyc\Batch\KycRound;
use App\Domain\Kyc\Batch\SupplierKycRound;
use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * A Supplier's evidence endpoints for a round on a KYC batch (D30).
 *
 * Scoped through the authenticated Supplier's own round — there is no round or
 * document identifier in these URLs to substitute for another Supplier's.
 */
class KycEvidenceController extends Controller
{
    use RecordsKycEvidence;

    public function __construct(
        protected OpenSupplierKycRound $openRound,
        protected SupplierKycDocumentStore $store,
    ) {}

    protected function evidenceRound(Request $request): KycRound
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return new SupplierKycRound($this->openRound->handle($supplier), $this->store);
    }
}

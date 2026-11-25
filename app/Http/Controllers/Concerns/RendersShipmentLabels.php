<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Courier\Enums\ShipmentLabelSize;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Courier\Queries\ShipmentLabelDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The print page every label route returns: the labels and nothing else.
 *
 * The page carries a delivery address and phone, so it is never cached and
 * never indexed, and its policy lets it load nothing from anywhere but this
 * origin's own fonts: the logo and barcode are inline.
 */
trait RendersShipmentLabels
{
    protected function labelSize(Request $request): ShipmentLabelSize
    {
        return ShipmentLabelSize::tryFrom((string) $request->query('size')) ?? ShipmentLabelSize::ThreeByFour;
    }

    /**
     * @param  iterable<ShipmentLabel>  $labels
     * @param  array<int, string>  $missing  shipment references left out of a batch
     */
    protected function renderLabels(Request $request, iterable $labels, string $title, array $missing = []): Response
    {
        $size = $this->labelSize($request);
        $document = app(ShipmentLabelDocument::class);

        $pages = [];

        foreach ($labels as $label) {
            $pages[] = $document->for($label, $size);
        }

        return response()
            ->view('labels.shipment', [
                'size' => $size,
                'labels' => $pages,
                'missing' => $missing,
                'title' => $title.'-'.$size->value,
                'autoprint' => $request->boolean('autoprint'),
            ])
            // Never indexed: PreventSearchIndexing sets X-Robots-Tag on every response.
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src 'self'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    }
}

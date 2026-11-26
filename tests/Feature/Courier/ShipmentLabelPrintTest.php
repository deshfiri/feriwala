<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Actions\IssueShipmentLabel;
use App\Domain\Courier\Actions\ReissueShipmentLabel;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Website\Actions\ManageWebsiteBranding;
use App\Support\Barcode\Code128;
use App\Support\Barcode\QrCode;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * The printed label: what it shows, at what size, and to whom. Rendered from
 * the frozen snapshot through the real routes.
 */

require_once __DIR__.'/ShipmentLabelFixtures.php';

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::CourierManager);
    shipmentLabelEnableSteadfast();

    $this->warehouse = shipmentLabelWarehouse();
    $this->site = shipmentLabelStorefront();
    $this->order = shipmentLabelOrder($this->site);
    shipmentLabelLine($this->order, $this->warehouse);
});

function shipmentLabelPrint(Shipment $shipment, string $size = '3x4', ?object $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->staff)->get(route('admin.shipments.label.show', [$shipment->public_id, 'size' => $size]));
}

/**
 * A tiny real PNG, as a Storefront would upload it.
 */
function shipmentLabelLogoUpload(string $colour = 'red'): UploadedFile
{
    $image = imagecreatetruecolor(40, 20);
    imagefill($image, 0, 0, $colour === 'red' ? imagecolorallocate($image, 200, 0, 0) : imagecolorallocate($image, 0, 0, 200));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent("logo-{$colour}.png", (string) ob_get_clean());
}

describe('layout', function () {
    it('sets an exact 3in × 3in page with no margin for the 3×3 label', function () {
        $shipment = shipmentLabelBook($this->order);

        shipmentLabelPrint($shipment, '3x3')->assertOk()
            ->assertSee('size: 3in 3in;', false)
            ->assertSee('margin: 0;', false)
            ->assertSee('width: 3in;', false)
            ->assertSee('height: 3in;', false)
            ->assertSee('data-size="3x3"', false);
    });

    it('sets an exact 3in × 4in page with no margin for the 3×4 label', function () {
        $shipment = shipmentLabelBook($this->order);

        shipmentLabelPrint($shipment, '3x4')->assertOk()
            ->assertSee('size: 3in 4in;', false)
            ->assertSee('height: 4in;', false)
            ->assertSee('data-size="3x4"', false);
    });

    it('puts "Powered by Banij", the courier and the print time in the footer of both sizes', function (string $size) {
        $this->travelTo(now()->setTimezone(config('app.timezone'))->setDate(2026, 11, 4)->setTime(10, 45));
        $shipment = shipmentLabelBook($this->order);

        $html = shipmentLabelPrint($shipment, $size)->assertOk()->getContent();
        preg_match('/<footer class="footer" data-field="footer">(.*?)<\/footer>/s', $html, $footer);

        expect($footer[1])->toContain('data-field="powered-by">Powered by Banij</span>')
            ->and($footer[1])->toContain('Steadfast')
            ->and($footer[1])->toContain('Printed 04/11/26 10:45am')
            ->and(strrpos($html, 'Powered by Banij'))->toBeGreaterThan(strrpos($html, 'data-field="return"'));
    })->with(['3x3', '3x4']);

    it('is black on white only: no accent colour, tint or shadow from the Storefront', function () {
        $shipment = shipmentLabelBook($this->order);

        shipmentLabelPrint($shipment)->assertOk()
            ->assertDontSee('#c2410c')
            ->assertDontSee('--accent', false)
            ->assertDontSee('box-shadow', false);
    });

    it('contains only the label: no application shell, script bundle or navigation', function () {
        $shipment = shipmentLabelBook($this->order);

        shipmentLabelPrint($shipment)->assertOk()
            ->assertDontSee('data-page=', false)
            ->assertDontSee('/build/', false)
            ->assertDontSee('<nav', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $headers = shipmentLabelPrint($shipment)->headers;
        expect($headers->get('Cache-Control'))->toContain('no-store')
            ->and($headers->get('X-Robots-Tag'))->toContain('noindex');
    });

    it('starts a long address smaller, and never clamps or ellipsises it', function () {
        $address = str_repeat('Flat 7B, Shanti Niketan Tower, Plot 1023, Road 44, ', 4);
        $order = shipmentLabelOrder($this->site, ['shipping_address' => [
            'line1' => $address, 'area' => 'Gulshan 2', 'city' => 'Dhaka', 'district' => 'Dhaka', 'postcode' => '1212',
        ]]);
        shipmentLabelLine($order, $this->warehouse);
        $shipment = shipmentLabelManual($order);

        $html = shipmentLabelPrint($shipment, '3x3')->assertOk()->getContent();
        preg_match('/\.recipient__address \{[^}]*\}/', $html, $rule);

        expect($html)->toContain('fit-1 fit-2"')
            ->and($html)->toContain('data-fit="2"')
            // The whole address is in the page, and its rule cuts nothing off.
            ->and($html)->toContain(trim($address).', Gulshan 2')
            ->and($rule[0])->not->toContain('line-clamp')
            ->and($rule[0])->not->toContain('ellipsis');
    });

    it('fits each label in the browser once its fonts have loaded, before printing', function () {
        $shipment = shipmentLabelBook($this->order);

        $html = $this->actingAs($this->staff)
            ->get(route('admin.shipments.label.show', [$shipment->public_id, 'size' => '3x3', 'autoprint' => 1]))
            ->assertOk()
            ->getContent();

        expect($html)->toContain("label.classList.add('fit-' + level)")
            ->and(strpos($html, 'window.print()'))->toBeGreaterThan(strpos($html, 'document.fonts.ready'))
            ->and(shipmentLabelPrint($shipment, '3x3')->getContent())->not->toContain('window.print()');
    });
});

describe('content', function () {
    it('shows the recipient, COD, references, parcel summary, items and the warehouse return block', function () {
        $shipment = shipmentLabelBook($this->order);
        $line = $this->order->items()->first();

        shipmentLabelPrint($shipment)->assertOk()
            ->assertSee('Rupkotha Fashion')
            ->assertSee('Parcel 1 of 1')
            ->assertSee('Rahim Uddin')
            ->assertSee('01712345678')
            ->assertSee('House 12, Road 5, Block C, Mirpur 10')
            ->assertSee('data-field="area">Dhaka 1216</dd>', false)
            ->assertSeeInOrder(['CASH ON DELIVERY', '৳1,250.00'])
            ->assertSee('data-field="invoice">'.$this->order->reference.'</dd>', false)
            ->assertSee($this->order->storefront_order_reference)
            ->assertSee('data-field="consignment">1424107</dd>', false)
            ->assertSee('data-field="tracking">15BAEB8A</dd>', false)
            ->assertSee('data-field="delivery">Home Delivery</dd>', false)
            ->assertSee($line->product_name.' × 2')
            ->assertSee('2 units')
            ->assertSee('Call before delivery')
            ->assertSee('Fulfilled by Banij')
            ->assertSee('Banij Tejgaon Hub')
            ->assertSee('12 Tejgaon Industrial Area')
            ->assertSee('01800000001');
    });

    it('names the upazila, district and division under Area, each once', function () {
        $order = shipmentLabelOrder($this->site, ['shipping_address' => [
            'line1' => 'Dewan Bari Road', 'area' => 'Lichubagan', 'upazila' => 'Ulipur', 'district' => 'Kurigram', 'division' => 'Rangpur',
        ]]);
        shipmentLabelLine($order, $this->warehouse);
        $sameAsDistrict = shipmentLabelOrder($this->site, ['shipping_address' => [
            'line1' => 'Station Road', 'upazila' => 'Rangpur Sadar', 'city' => 'Rangpur', 'district' => 'Rangpur', 'division' => 'Rangpur',
        ]]);
        shipmentLabelLine($sameAsDistrict, $this->warehouse);

        shipmentLabelPrint(shipmentLabelManual($order))->assertOk()
            ->assertSee('data-field="address">Dewan Bari Road, Lichubagan</dd>', false)
            ->assertSee('data-field="area">Ulipur, Kurigram, Rangpur</dd>', false);
        shipmentLabelPrint(shipmentLabelManual($sameAsDistrict, tracking: 'MAN-0002'))->assertOk()
            ->assertSee('data-field="area">Rangpur Sadar, Rangpur</dd>', false);
    });

    it('shows the recorded weight, and no weight row at all when none was recorded', function () {
        shipmentLabelPrint(shipmentLabelManual($this->order))->assertOk()
            ->assertSee('data-field="weight">1 KG</dd>', false);

        $unweighed = shipmentLabelOrder($this->site);
        shipmentLabelLine($unweighed, $this->warehouse);
        $shipment = app(CreateShipmentFromAllocations::class)->handle(
            $unweighed, CourierProviderCode::Manual, Money::fromDecimal('80.00', Currency::BDT), [['weight' => null]],
            $this->staff, 'MAN-0002', Money::fromDecimal('1250.00', Currency::BDT),
        );

        shipmentLabelPrint($shipment)->assertOk()
            ->assertDontSee('data-field="weight"', false)
            ->assertDontSee('KG');
    });

    it('lists up to three items on 3 × 4 and condenses the rest; 3 × 3 shows the first and a count', function () {
        foreach (range(1, 3) as $extra) {
            shipmentLabelLine($this->order, $this->warehouse, quantity: 1);
        }
        $names = $this->order->items()->orderBy('line_number')->pluck('product_name');
        $shipment = shipmentLabelManual($this->order);

        $detailed = shipmentLabelPrint($shipment, '3x4')->assertOk()->getContent();
        preg_match('/<span class="items__full">(.*?)<\/span>\s*<span class="items__summary">/s', $detailed, $full);

        expect($full[1])->toContain($names[0].' × 2')
            ->and($full[1])->toContain($names[1].' × 1')
            ->and($full[1])->toContain($names[2].' × 1')
            ->and($full[1])->not->toContain($names[3])
            ->and($full[1])->toContain('+ 1 more item')
            ->and($detailed)->toContain('5 units');

        $compact = shipmentLabelPrint($shipment, '3x3')->assertOk()->getContent();
        preg_match('/<span class="items__full">(.*?)<\/span>\s*<span class="items__summary">/s', $compact, $condensed);

        expect($condensed[1])->toContain($names[0].' × 2')
            ->and($condensed[1])->not->toContain($names[1])
            ->and($condensed[1])->toContain('+ 3 more items');
    });

    it('draws a QR code of the courier\'s identifier in whole 203 DPI dots', function (string $size) {
        $shipment = shipmentLabelBook($this->order);
        $html = shipmentLabelPrint($shipment, $size)->assertOk()->getContent();

        preg_match('/data-field="qr" data-value="([^"]+)"[^>]*><svg[^>]* width="([\d.]+)in" height="([\d.]+)in" viewBox="0 0 (\d+) (\d+)"[^>]*>.*?<path d="([^"]+)"/s', $html, $qr);
        [, $value, $width, $height, $modules] = $qr;
        $dots = (float) $width * Code128::PRINTER_DPI / (int) $modules;
        $dark = collect(QrCode::matrix('15BAEB8A'))->flatten()->filter()->count();

        expect($value)->toBe('15BAEB8A')
            ->and($width)->toBe($height)
            ->and((int) $modules)->toBe(count(QrCode::matrix('15BAEB8A')) + 2 * QrCode::QUIET_ZONE)
            ->and(abs($dots - round($dots)))->toBeLessThan(0.01)
            ->and((int) round($dots))->toBeGreaterThanOrEqual(QrCode::MIN_MODULE_DOTS)
            // One square per dark module of the symbol the encoder produced.
            ->and(substr_count($qr[6], 'h1v1h-1z'))->toBe($dark);
    })->with(['3x3', '3x4']);

    it('never shows a unit price, cost or platform figure next to the items', function () {
        $shipment = shipmentLabelBook($this->order);

        shipmentLabelPrint($shipment)->assertOk()
            ->assertDontSee('625.00')
            ->assertDontSee('400.00')
            ->assertDontSee('৳500.00');
    });

    it('never shows the Storefront contact address, the Partner, the email or a figure of the platform\'s', function () {
        $shipment = shipmentLabelBook($this->order);
        $partner = $this->site->businessAccount;

        shipmentLabelPrint($shipment)->assertOk()
            ->assertDontSee('STOREFRONT-CONTACT-ADDRESS')
            ->assertDontSee('Internal stock note')
            ->assertDontSee('rahim.private@example.test')
            ->assertDontSee('store@example.test')
            ->assertDontSee($partner->name)
            ->assertDontSee('400.00')
            ->assertDontSee('500.00')
            ->assertDontSee('80.00');
    });

    it('says PAID / PREPAID with nothing due on a prepaid parcel, and the amount on a COD one', function () {
        $paid = shipmentLabelOrder($this->site, ['paid_at' => now()]);
        shipmentLabelLine($paid, $this->warehouse);

        shipmentLabelPrint(shipmentLabelManual($paid, cod: null))->assertOk()
            ->assertSeeInOrder(['PAID / PREPAID', '৳0.00 DUE'])
            ->assertDontSee('CASH ON DELIVERY');

        shipmentLabelPrint(shipmentLabelManual($this->order, cod: '1250.00', tracking: 'MAN-0002'))->assertOk()
            ->assertSee('<span class="payment__caption">CASH ON DELIVERY</span>', false)
            ->assertSee('<span class="payment__amount">৳1,250.00</span>', false)
            ->assertDontSee('PAID / PREPAID');
    });

    it('encodes the courier\'s tracking code, and says it in text beneath', function () {
        $shipment = shipmentLabelBook($this->order);
        $html = shipmentLabelPrint($shipment)->assertOk()->getContent();

        expect($html)->toContain('data-value="15BAEB8A"')
            ->and($html)->toContain('<div class="barcode__value">15BAEB8A</div>');

        // The bars drawn are exactly Code 128's for that value: every bar's
        // x-offset and width, in order, from the start symbol to the stop.
        preg_match('/<path d="([^"]+)"/', $html, $path);
        preg_match_all('/M(\d+) 0h(\d+)/', $path[1], $bars, PREG_SET_ORDER);

        $expected = [];
        $x = Code128::QUIET_ZONE;
        foreach (Code128::widths('15BAEB8A') as $index => $width) {
            if ($index % 2 === 0) {
                $expected[] = [$x, $width];
            }
            $x += $width;
        }

        expect(array_map(fn ($bar) => [(int) $bar[1], (int) $bar[2]], $bars))->toBe($expected)
            // Start B, the eight characters (ASCII - 32), and the weighted
            // modulo-103 check worked by hand: 1218 mod 103 = 85.
            ->and(Code128::symbols('15BAEB8A'))->toBe([104, 17, 21, 34, 33, 37, 34, 24, 33, 85]);
    });

    it('falls back to the consignment id when there is no tracking code', function () {
        $shipment = shipmentLabelBook($this->order, '1424107', '');

        expect(ShipmentLabel::query()->sole()->barcode_value)->toBe('1424107');
        shipmentLabelPrint($shipment)->assertOk()->assertSee('data-value="1424107"', false);
    });

    it('renders a Bangla name and address intact, with the Bangla face available', function () {
        $order = shipmentLabelOrder($this->site, [
            'customer' => ['name' => 'মোঃ রহিম উদ্দিন', 'mobile' => '+8801712345678'],
            'shipping_address' => ['line1' => 'বাড়ি ১২, রোড ৫, ব্লক সি', 'area' => 'মিরপুর ১০', 'district' => 'ঢাকা', 'postcode' => '১২১৬'],
        ]);
        shipmentLabelLine($order, $this->warehouse);

        shipmentLabelPrint(shipmentLabelManual($order))->assertOk()
            ->assertSee('মোঃ রহিম উদ্দিন')
            ->assertSee('বাড়ি ১২, রোড ৫, ব্লক সি')
            ->assertSee('মিরপুর ১০')
            ->assertSee("font-family: 'Li Ador Noirrit'", false);
    });

    it('prints every value escaped', function () {
        $order = shipmentLabelOrder($this->site, ['customer' => ['name' => '<script>alert(1)</script>', 'mobile' => '+8801712345678']]);
        shipmentLabelLine($order, $this->warehouse);

        shipmentLabelPrint(shipmentLabelManual($order))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    });
});

describe('branding', function () {
    it('uses the order\'s own Storefront logo and name', function () {
        app(ManageWebsiteBranding::class)->putImage($this->site, $this->site->businessAccount->owner, 'logo', shipmentLabelLogoUpload('red'));
        $shipment = shipmentLabelManual($this->order);

        $html = shipmentLabelPrint($shipment)->assertOk()->getContent();

        expect($html)->toContain('class="head__logo" src="data:image/png;base64,')
            ->and($html)->toContain('alt="Rupkotha Fashion"')
            ->and($html)->toContain('data-field="store-name">Rupkotha Fashion</div>');
    });

    it('shows the Store name clearly, and no image at all, when there is no logo', function () {
        $shipment = shipmentLabelManual($this->order);

        shipmentLabelPrint($shipment)->assertOk()
            ->assertDontSee('<img', false)
            ->assertSee('data-field="store-name">Rupkotha Fashion</div>', false);
    });

    it('reprints the frozen branding after the Storefront changes its logo and name', function () {
        $owner = $this->site->businessAccount->owner;
        app(ManageWebsiteBranding::class)->putImage($this->site, $owner, 'logo', shipmentLabelLogoUpload('red'));
        $shipment = shipmentLabelManual($this->order);
        $before = shipmentLabelPrint($shipment)->getContent();
        preg_match('/src="(data:image\/png;base64,[^"]+)"/', $before, $logo);

        // Replacing the logo deletes the old file; renaming changes the live record.
        app(ManageWebsiteBranding::class)->putImage($this->site->refresh(), $owner, 'logo', shipmentLabelLogoUpload('blue'));
        app(ManageWebsiteBranding::class)->update($this->site->refresh(), $owner, ['name' => 'Renamed Boutique']);

        shipmentLabelPrint($shipment)->assertOk()
            ->assertSee('Rupkotha Fashion')
            ->assertDontSee('Renamed Boutique')
            ->assertSee($logo[1], false);
    });

    it('keeps each Storefront\'s branding to its own labels in a batch', function () {
        $first = shipmentLabelManual($this->order);
        $otherSite = shipmentLabelStorefront(overrides: ['name' => 'Nokshi Crafts', 'contact_phone' => '01922222222']);
        $otherOrder = shipmentLabelOrder($otherSite);
        shipmentLabelLine($otherOrder, $this->warehouse);
        $second = shipmentLabelManual($otherOrder, tracking: 'MAN-0002');

        $html = $this->actingAs($this->staff)
            ->get(route('admin.shipment-labels.batch', ['shipments' => [$second->public_id, $first->public_id], 'size' => '3x3']))
            ->assertOk()
            ->getContent();

        preg_match_all('/<section\s+class="label[^"]*".*?<\/section>/s', $html, $pages);

        expect($pages[0])->toHaveCount(2);

        $byStore = collect($pages[0])->mapWithKeys(fn (string $page) => [
            str_contains($page, 'Rupkotha Fashion') ? 'rupkotha' : 'nokshi' => $page,
        ]);

        expect($byStore['rupkotha'])->toContain('MAN-0001')->not->toContain('Nokshi Crafts')->not->toContain('01922222222')
            ->and($byStore['nokshi'])->toContain('MAN-0002')->not->toContain('Rupkotha Fashion')->not->toContain('01911111111');
    });
});

describe('versions', function () {
    it('marks a corrected label in its footer and still reprints the version it replaced as issued', function () {
        $shipment = shipmentLabelManual($this->order);
        $this->warehouse->forceFill(['name' => 'Banij Tejgaon Hub (North gate)'])->save();
        app(ReissueShipmentLabel::class)->handle($shipment, $this->staff, 'Gate moved');

        shipmentLabelPrint($shipment)->assertOk()
            ->assertSee('Corrected v2')
            ->assertSee('Banij Tejgaon Hub (North gate)');

        $this->actingAs($this->staff)
            ->get(route('admin.shipments.label.show', [$shipment->public_id, 'size' => '3x4', 'version' => 1]))
            ->assertOk()
            ->assertSee('Banij Tejgaon Hub,', false)
            ->assertDontSee('North gate')
            ->assertDontSee('Corrected v');
    });
});

describe('several parcels', function () {
    it('prints "Parcel 2 of 2" with that parcel\'s own warehouse', function () {
        shipmentLabelManual($this->order);
        $chattogram = shipmentLabelWarehouse(['name' => 'Banij Chattogram Hub', 'return_address_line' => '7 Agrabad C/A', 'return_district' => 'Chattogram']);
        $second = shipmentLabelExtraParcel($this->order, $chattogram, 'MAN-0002');
        app(IssueShipmentLabel::class)->handle($second, $this->staff);

        shipmentLabelPrint($second)->assertOk()
            ->assertSee('Parcel 2 of 2')
            ->assertSee('Banij Chattogram Hub')
            ->assertSee('7 Agrabad C/A')
            ->assertDontSee('Banij Tejgaon Hub');
    });
});

describe('access', function () {
    it('lets the owning Partner print their own parcel', function () {
        $shipment = shipmentLabelBook($this->order);

        $this->actingAs($this->site->businessAccount->owner)
            ->get(route('store-orders.shipments.label', [$this->order->public_id, $shipment->public_id, 'size' => '3x3']))
            ->assertOk()
            ->assertSee('Rupkotha Fashion');
    });

    it('refuses another Partner with 403', function () {
        $shipment = shipmentLabelBook($this->order);
        $stranger = shipmentLabelStorefront(overrides: ['name' => 'Stranger Store'])->businessAccount->owner;

        $this->actingAs($stranger)
            ->get(route('store-orders.shipments.label', [$this->order->public_id, $shipment->public_id]))
            ->assertForbidden();
    });

    it('refuses a Partner pairing their own order with another order\'s shipment', function () {
        $shipment = shipmentLabelBook($this->order);
        $ownOrder = shipmentLabelOrder(shipmentLabelStorefront(overrides: ['name' => 'Own Store']));

        $this->actingAs($ownOrder->businessAccount->owner)
            ->get(route('store-orders.shipments.label', [$ownOrder->public_id, $shipment->public_id]))
            ->assertForbidden();
    });

    it('refuses a Partner the staff print route', function () {
        $shipment = shipmentLabelBook($this->order);

        shipmentLabelPrint($shipment, as: $this->site->businessAccount->owner)->assertForbidden();
    });

    it('lets staff who may see shipments print', function () {
        $shipment = shipmentLabelBook($this->order);
        $viewer = testPlatformStaff(PlatformRole::ContentManager);
        $viewer->givePermissionTo(PermissionCatalogue::name(PermissionModule::Courier, PermissionAction::View));

        shipmentLabelPrint($shipment, as: $viewer)->assertOk();
    });

    it('refuses staff who may not see shipments', function () {
        $shipment = shipmentLabelBook($this->order);

        shipmentLabelPrint($shipment, as: testPlatformStaff(PlatformRole::ContentManager))->assertForbidden();
        $this->actingAs(testPlatformStaff(PlatformRole::ContentManager))
            ->get(route('admin.shipment-labels.batch', ['shipments' => [$shipment->public_id]]))
            ->assertForbidden();
    });

    it('lets only shipment managers issue or correct, and demands a reason to correct', function () {
        $shipment = shipmentLabelBook($this->order);
        $viewer = testPlatformStaff(PlatformRole::ContentManager);
        $viewer->givePermissionTo(PermissionCatalogue::name(PermissionModule::Courier, PermissionAction::View));

        $this->actingAs($viewer)->post(route('admin.shipments.label.store', $shipment->public_id))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.shipments.label.reissue', $shipment->public_id), ['reason' => 'x'])->assertForbidden();

        $this->actingAs($this->staff)->post(route('admin.shipments.label.reissue', $shipment->public_id), [])
            ->assertInvalid(['reason']);
    });

    it('shows the Partner only their ready parcels, never a blocker\'s detail', function () {
        $shipment = shipmentLabelBook($this->order);

        $this->actingAs($this->site->businessAccount->owner)
            ->get(route('store-orders.show', $this->order->public_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shipping_labels.0.shipment', $shipment->public_id)
                ->where('shipping_labels.0.ready', true)
                ->missing('shipping_labels.0.message')
                ->missing('shipping_labels.0.warehouse'));
    });
});

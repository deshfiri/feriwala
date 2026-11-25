<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Courier\Actions\IssueShipmentLabel;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Website\Actions\ManageWebsiteBranding;
use App\Support\Barcode\Code128;
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

    it('puts "Powered by Banij" at the bottom of both sizes', function (string $size) {
        $shipment = shipmentLabelBook($this->order);

        $html = shipmentLabelPrint($shipment, $size)->assertOk()->getContent();

        expect($html)->toContain('data-field="powered-by">Powered by Banij</footer>')
            ->and(strrpos($html, 'Powered by Banij'))->toBeGreaterThan(strrpos($html, 'data-field="return"'));
    })->with(['3x3', '3x4']);

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

    it('steps long addresses down in size so they stay inside the label', function () {
        $order = shipmentLabelOrder($this->site, ['shipping_address' => [
            'line1' => str_repeat('Flat 7B, Shanti Niketan Tower, Plot 1023, Road 44, ', 4),
            'area' => 'Gulshan 2', 'city' => 'Dhaka', 'district' => 'Dhaka', 'postcode' => '1212',
        ]]);
        shipmentLabelLine($order, $this->warehouse);
        $shipment = shipmentLabelManual($order);

        shipmentLabelPrint($shipment, '3x3')->assertOk()
            ->assertSee('recipient__address--tighter', false);
    });
});

describe('content', function () {
    it('shows the recipient, COD, references, courier and the warehouse return block', function () {
        $shipment = shipmentLabelBook($this->order);

        shipmentLabelPrint($shipment)->assertOk()
            ->assertSee('Rupkotha Fashion')
            ->assertSee('01911111111')
            ->assertSee('Rahim Uddin')
            ->assertSee('01712345678')
            ->assertSee('House 12, Road 5, Block C')
            ->assertSee('COD ৳1,250.00')
            ->assertSee($this->order->reference)
            ->assertSee($this->order->storefront_order_reference)
            ->assertSee('Steadfast')
            ->assertSee('1424107')
            ->assertSee('Call before delivery')
            ->assertSee('Fulfilled by Banij')
            ->assertSee('Banij Tejgaon Hub')
            ->assertSee('12 Tejgaon Industrial Area')
            ->assertSee('01800000001');
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

    it('says PAID on a prepaid parcel and the amount on a COD one', function () {
        $paid = shipmentLabelOrder($this->site, ['paid_at' => now()]);
        shipmentLabelLine($paid, $this->warehouse);

        shipmentLabelPrint(shipmentLabelManual($paid, cod: null))->assertOk()
            ->assertSee('PAID')
            ->assertDontSee('COD ৳');

        shipmentLabelPrint(shipmentLabelManual($this->order, cod: '1250.00'))->assertOk()
            ->assertSee('COD ৳1,250.00')
            ->assertSee('payment--cod', false);
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

        expect($html)->toContain('class="brand__logo" src="data:image/png;base64,')
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

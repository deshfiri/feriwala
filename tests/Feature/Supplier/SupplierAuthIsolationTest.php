<?php

use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Middleware\EnsureSupplierIsOperational;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * D25's core promise: a Supplier session must never gain access to Client/
 * Partner, Partner Website or staff resources, and a Client/Partner session
 * must never gain access to Supplier resources. These are the tests that
 * prove it, before anything else in the Supplier domain is built on top.
 */

test('registering a supplier creates a verification-pending row and logs it in on the supplier guard', function () {
    $response = $this->post(route('supplier.register'), [
        'business_name' => 'Acme Wholesale',
        'contact_person_name' => 'Jamal Uddin',
        'business_address' => '12 Motijheel, Dhaka',
        'email' => 'supplier@example.com',
        'mobile' => '+8801700000001',
        'password' => testStrongPassword(),
        'password_confirmation' => testStrongPassword(),
    ]);

    $response->assertRedirect(route('supplier.verification.notice'));

    $supplier = Supplier::query()->where('email', 'supplier@example.com')->firstOrFail();

    expect($supplier->status)->toBe(SupplierStatus::VerificationPending);
    expect($supplier->statusHistory()->count())->toBe(2);

    $this->assertAuthenticatedAs($supplier, 'supplier');
    $this->assertGuest('web');
});

test('registering a supplier normalises a loosely formatted mobile number to E.164', function () {
    $response = $this->post(route('supplier.register'), [
        'business_name' => 'Acme Wholesale',
        'contact_person_name' => 'Jamal Uddin',
        'business_address' => '12 Motijheel, Dhaka',
        'email' => 'loose-mobile@example.com',
        'mobile' => '+1 (537) 436-9372',
        'password' => testStrongPassword(),
        'password_confirmation' => testStrongPassword(),
    ]);

    $response->assertRedirect(route('supplier.verification.notice'));

    $supplier = Supplier::query()->where('email', 'loose-mobile@example.com')->firstOrFail();

    expect($supplier->mobile)->toBe('+15374369372');
});

test('registering a supplier with an unresolvable mobile number fails validation instead of crashing', function () {
    $response = $this->post(route('supplier.register'), [
        'business_name' => 'Acme Wholesale',
        'contact_person_name' => 'Jamal Uddin',
        'business_address' => '12 Motijheel, Dhaka',
        'email' => 'bad-mobile@example.com',
        'mobile' => 'not-a-number',
        'password' => testStrongPassword(),
        'password_confirmation' => testStrongPassword(),
    ]);

    $response->assertSessionHasErrors('mobile');
    $this->assertGuest('supplier');
    expect(Supplier::query()->where('email', 'bad-mobile@example.com')->exists())->toBeFalse();
});

test('a supplier logs in only on the supplier guard', function () {
    $supplier = Supplier::factory()->create(['email' => 'login@example.com']);

    $response = $this->post(route('supplier.login'), [
        'email' => 'login@example.com',
        'password' => 'password',
    ]);

    $response->assertRedirect(route('supplier.dashboard'));
    $this->assertAuthenticatedAs($supplier, 'supplier');
    $this->assertGuest('web');
});

test('a client/partner login cannot authenticate through the supplier guard', function () {
    $user = User::factory()->create(['email' => 'client@example.com', 'password' => bcrypt('password')]);

    $response = $this->post(route('supplier.login'), [
        'email' => 'client@example.com',
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest('supplier');
    $this->assertGuest('web');

    // The User row exists; it simply is not a Supplier.
    expect($user->exists)->toBeTrue();
});

test('a supplier login cannot authenticate through the client/partner guard', function () {
    Supplier::factory()->create(['email' => 'supplieronly@example.com']);

    $response = $this->post(route('login'), [
        'email' => 'supplieronly@example.com',
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest('web');
    $this->assertGuest('supplier');
});

test('a supplier session is refused on a client/partner route', function () {
    $supplier = Supplier::factory()->create();

    // Deliberately not actingAs(): it calls Auth::shouldUse(), which would
    // make 'supplier' the *default* guard for the rest of the test and
    // defeat the very isolation being proven. A real browser never does
    // this — the default guard is always 'web' — so login is set directly
    // on the named guard instead, exactly as the real session would hold it.
    Auth::guard('supplier')->login($supplier);

    $response = $this->get(route('dashboard'));

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
    $this->assertGuest('web');
});

test('a client/partner session is refused on a supplier route', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')->get(route('supplier.dashboard'));

    $response->assertStatus(302);
    $response->assertRedirect(route('supplier.login'));
    $this->assertGuest('supplier');
});

test('logging a supplier out clears only the supplier guard', function () {
    $supplier = Supplier::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($supplier, 'supplier');
    Auth::guard('web')->login($user);

    $response = $this->post(route('supplier.logout'));

    $response->assertRedirect(route('supplier.login'));
    $this->assertGuest('supplier');
    $this->assertAuthenticatedAs($user, 'web');
});

test('the operational gate refuses every non-approved supplier status', function (SupplierStatus $status) {
    $supplier = match ($status) {
        SupplierStatus::Draft => Supplier::factory()->draft()->create(),
        SupplierStatus::VerificationPending => Supplier::factory()->verificationPending()->create(),
        SupplierStatus::KycPending => Supplier::factory()->kycPending()->create(),
        SupplierStatus::UnderReview => Supplier::factory()->underReview()->create(),
        SupplierStatus::CorrectionRequired => Supplier::factory()->correctionRequired()->create(),
        SupplierStatus::Rejected => Supplier::factory()->rejected()->create(),
        SupplierStatus::Suspended => Supplier::factory()->suspended()->create(),
        SupplierStatus::Closed => Supplier::factory()->closed()->create(),
        SupplierStatus::Approved => Supplier::factory()->create(),
    };

    $request = Request::create('/supplier/test');
    Auth::guard('supplier')->setUser($supplier);
    $request->setUserResolver(fn ($guard = null) => Auth::guard($guard ?? 'supplier')->user());

    $middleware = new EnsureSupplierIsOperational;

    if ($status === SupplierStatus::Approved) {
        $response = $middleware->handle($request, fn () => new Response('ok'));
        expect($response->getContent())->toBe('ok');

        return;
    }

    expect(fn () => $middleware->handle($request, fn () => new Response('ok')))
        ->toThrow(HttpException::class);
})->with(SupplierStatus::cases());

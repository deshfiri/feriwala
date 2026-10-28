<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Platform staff stand a website up for a partner, and delete one (§16.3, §16.4).
 *
 * "Delete" is closing: a website's charges, orders and history are records the
 * database will not let go, so the end of its life is the terminal status, which
 * also frees the package slot for the next website.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->account = websiteTestAccount();

    websiteTestFee(FeeType::WebsiteSetup, 500000);
});

describe('creating a website for a partner', function () {
    it('creates it under the partner and raises the charges against them', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.websites.store'), [
                'account' => $this->account->public_id,
                'name' => 'Nasrin Fashion',
                'subdomain' => 'nasrin-fashion',
            ])
            ->assertSessionHasNoErrors();

        $website = Website::query()->where('subdomain', 'nasrin-fashion')->firstOrFail();

        expect($website->business_account_id)->toBe($this->account->id)
            ->and($website->status)->toBe(WebsiteStatus::SetupPending)
            ->and($website->created_by)->toBe($this->staff->id)
            ->and($website->charges()->where('status', WebsiteChargeStatus::Due->value)->count())->toBe(1);
    });

    it('keeps to the partner\'s package limit rather than waiving it', function () {
        Website::factory()->forAccount($this->account)->active()->create();

        $this->actingAs($this->staff)
            ->from(route('admin.websites.create'))
            ->post(route('admin.websites.store'), [
                'account' => $this->account->public_id,
                'name' => 'One too many',
                'subdomain' => 'one-too-many',
            ])
            ->assertSessionHasErrors('package');

        expect(Website::query()->where('subdomain', 'one-too-many')->exists())->toBeFalse();
    });

    it('refuses an address somebody already holds', function () {
        Website::factory()->create(['subdomain' => 'taken-name']);

        $this->actingAs($this->staff)
            ->from(route('admin.websites.create'))
            ->post(route('admin.websites.store'), [
                'account' => $this->account->public_id,
                'name' => 'Taken',
                'subdomain' => 'taken-name',
            ])
            ->assertSessionHasErrors('subdomain');
    });

    it('offers the form to staff and refuses it to a partner', function () {
        $this->actingAs($this->staff)
            ->get(route('admin.websites.create'))
            ->assertOk();

        $this->actingAs($this->account->owner)
            ->get(route('admin.websites.create'))
            ->assertForbidden();

        $this->actingAs($this->account->owner)
            ->post(route('admin.websites.store'), [
                'account' => $this->account->public_id,
                'name' => 'Sneaky',
                'subdomain' => 'sneaky',
            ])
            ->assertForbidden();

        expect(Website::query()->where('subdomain', 'sneaky')->exists())->toBeFalse();
    });
});

describe('deleting a website', function () {
    it('closes it, keeps its records, and frees the slot for a new website', function () {
        $website = Website::factory()->forAccount($this->account)->active()->create();

        $this->actingAs($this->staff)
            ->delete(route('admin.websites.destroy', $website->public_id), [
                'reason' => 'Replaced by a new build for this partner',
            ])
            ->assertRedirect(route('admin.websites.index'));

        $website->refresh();

        expect($website->status)->toBe(WebsiteStatus::Closed)
            ->and($website->closed_at)->not->toBeNull()
            ->and($website->statusHistory->last()->reason)->toBe('Replaced by a new build for this partner');

        // The partner is at a limit of one, and the closed website no longer counts.
        $this->actingAs($this->staff)
            ->post(route('admin.websites.store'), [
                'account' => $this->account->public_id,
                'name' => 'Replacement',
                'subdomain' => 'replacement',
            ])
            ->assertSessionHasNoErrors();

        expect(Website::query()->where('subdomain', 'replacement')->exists())->toBeTrue();
    });

    it('drops a closed website from the list unless it is asked for', function () {
        $website = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Gone Shop']);

        $this->actingAs($this->staff)
            ->delete(route('admin.websites.destroy', $website->public_id), [
                'reason' => 'No longer wanted by the partner',
            ]);

        $names = fn (array $query = []) => collect(
            $this->actingAs($this->staff)
                ->get(route('admin.websites.index', $query))
                ->viewData('page')['props']['websites']['data'],
        )->pluck('name')->all();

        expect($names())->not->toContain('Gone Shop')
            ->and($names(['status' => WebsiteStatus::Closed->value]))->toContain('Gone Shop');
    });

    it('needs a reason', function () {
        $website = Website::factory()->forAccount($this->account)->active()->create();

        $this->actingAs($this->staff)
            ->from(route('admin.websites.show', $website->public_id))
            ->delete(route('admin.websites.destroy', $website->public_id), ['reason' => 'short'])
            ->assertSessionHasErrors('reason');

        expect($website->refresh()->status)->toBe(WebsiteStatus::Active);
    });

    it('is not something a partner can do to their own website', function () {
        $website = Website::factory()->forAccount($this->account)->active()->create();

        $this->actingAs($this->account->owner)
            ->delete(route('admin.websites.destroy', $website->public_id), [
                'reason' => 'I would like it gone please',
            ])
            ->assertForbidden();

        expect($website->refresh()->status)->toBe(WebsiteStatus::Active);
    });
});

<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Media;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;

/*
 * The CMS authority boundaries Stage 8 requires proof of (§34): editing is
 * not publishing, SEO is not CMS content, and neither reaches a Client/
 * Partner or a Supplier at all. Every route-level check itself is already
 * exercised by PageAdminTest/MediaAdminTest/etc. against a single outsider
 * fixture (a role with no CMS permission at all) and against ContentManager
 * (which holds every CMS permission there is); what those files do not cover
 * is the boundary *inside* the permission set — someone who can edit but not
 * publish, and the two account kinds that hold no platform role at all.
 */

/**
 * A raw permission set, not a PlatformRole: everything ContentManager holds
 * except `cms.publish`/`cms.unpublish`/`cms.archive`. No such role exists in
 * PlatformRole today (ContentManager holds publish too), so this is built
 * directly rather than through `testPlatformStaff()`.
 */
function cmsAuthTestEditorWithoutPublish(): User
{
    $user = User::factory()->staff()->create();
    $user->givePermissionTo([
        'cms.view', 'cms.create', 'cms.edit', 'cms.delete',
        'cms.media.view', 'cms.media.manage',
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets an editor without publish permission edit a section but refuses every publish-like action', function () {
    $editor = cmsAuthTestEditorWithoutPublish();
    $page = cmsTestPage();
    $section = app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    $this->actingAs($editor)
        ->patch(route('admin.cms.pages.sections.update', [$page->public_id, $section->public_id]), [
            'kind' => 'hero',
            'content' => cmsTestHeroContent('Edited by a non-publisher'),
        ])
        ->assertRedirect();

    expect($section->fresh()->content->getArrayCopy()['heading']['en'])->toBe('Edited by a non-publisher');

    $this->actingAs($editor)
        ->post(route('admin.cms.pages.publish', $page->public_id))
        ->assertForbidden();

    $this->actingAs($editor)
        ->post(route('admin.cms.pages.cancel-schedule', $page->public_id))
        ->assertForbidden();

    app(PublishPage::class)->handle($page);

    $this->actingAs($editor)
        ->post(route('admin.cms.pages.unpublish', $page->public_id))
        ->assertForbidden();

    $revision = $page->fresh()->currentPublishedRevision;

    $this->actingAs($editor)
        ->post(route('admin.cms.pages.revisions.restore', [$page->public_id, $revision->public_id]))
        ->assertForbidden();
});

it('lets an SEO manager edit CMS section content but refuses publishing, media upload and creating new redirects/menu items', function () {
    $seoManager = testPlatformStaff(PlatformRole::SeoManager);
    $page = cmsTestPage();
    $section = app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());

    // Intentional and already relied upon (SeoSettingController doc comment):
    // an SEO manager holds cms.edit so they can browse into a page to pick
    // its OG image override. Locked here as a regression guard, not
    // discovered as a defect.
    $this->actingAs($seoManager)
        ->patch(route('admin.cms.pages.sections.update', [$page->public_id, $section->public_id]), [
            'kind' => 'hero',
            'content' => cmsTestHeroContent('Edited by SEO manager'),
        ])
        ->assertRedirect();

    $this->actingAs($seoManager)
        ->post(route('admin.cms.pages.publish', $page->public_id))
        ->assertForbidden();

    $this->actingAs($seoManager)
        ->post(route('admin.cms.media.store'), [
            'file' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertForbidden();

    $this->actingAs($seoManager)
        ->post(route('admin.cms.redirects.store'), [
            'from_path' => '/old', 'to_path' => '/new', 'status_code' => 301,
        ])
        ->assertForbidden();

    $this->actingAs($seoManager)
        ->post(route('admin.cms.menus.items.store', 'header'), [
            'label_en' => 'New link', 'external_url' => '/somewhere', 'link_target' => 'self',
        ])
        ->assertForbidden();
});

it('refuses every staff CMS admin route to a Client/Partner business account', function () {
    $account = testBusinessAccount(AccountStatus::Active);
    $page = cmsTestPage();
    $media = Media::query()->create([
        'disk' => 'public', 'path' => 'cms/x.jpg', 'original_filename' => 'x.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        'alt_text_en' => 'x', 'alt_text_bn' => 'x',
    ]);

    $this->actingAs($account->owner)->get(route('admin.cms.pages.index'))->assertForbidden();
    $this->actingAs($account->owner)->get(route('admin.cms.pages.edit', $page->public_id))->assertForbidden();
    $this->actingAs($account->owner)->get(route('admin.cms.media.index'))->assertForbidden();
    $this->actingAs($account->owner)->delete(route('admin.cms.media.destroy', $media->public_id))->assertForbidden();
    $this->actingAs($account->owner)->get(route('admin.cms.seo.index'))->assertForbidden();
    $this->actingAs($account->owner)->get(route('admin.cms.redirects.index'))->assertForbidden();
    $this->actingAs($account->owner)->get(route('admin.cms.menus.index'))->assertForbidden();
});

it('never lets a Supplier session, on its own guard, reach a staff CMS admin route', function () {
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
    supplierTestSignIn($supplier);

    // The `web` guard's `auth` middleware sees a guest — a Supplier
    // authenticates on a completely separate guard (D25) — so this is a
    // redirect to the login screen, not a 403 from a policy that ran.
    $this->get(route('admin.cms.pages.index'))->assertRedirect(route('login'));
});

<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\ManageCategories;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Category;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Product categories (P3-1, §11.3, §12).
 *
 * Two things carry this file. The tree has to stay a tree — bounded depth, no
 * cycles — because every walk of it (a menu, an availability check, a filter)
 * runs until it exhausts memory otherwise. And writing it is a platform
 * privilege: §12 says a regular user cannot create a category, and that is
 * enforced at the controller rather than by hiding a button.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
});

function catalogCategory(array $attributes = []): Category
{
    return Category::create([
        'name' => 'Electronics',
        'is_active' => true,
        ...$attributes,
    ]);
}

describe('only the platform writes the catalogue (§12)', function () {
    it('refuses a business account holder outright', function () {
        /*
         * The §12 hard requirement. A partner holds no platform permission at
         * all, so this is a 403 rather than a screen with its buttons hidden —
         * hiding a control is not enforcement.
         */
        $account = testBusinessAccount(AccountStatus::Active);

        $this->actingAs($account->owner)
            ->get(route('admin.catalog.categories.index'))
            ->assertForbidden();

        $this->actingAs($account->owner)
            ->post(route('admin.catalog.categories.store'), ['name' => 'Mine'])
            ->assertForbidden();

        expect(Category::query()->count())->toBe(0);
    });

    it('refuses staff who may read the catalogue but not write it', function () {
        // An inventory manager holds `catalog.view` and nothing more.
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);

        $this->actingAs($viewer)
            ->get(route('admin.catalog.categories.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->post(route('admin.catalog.categories.store'), ['name' => 'Mine'])
            ->assertForbidden();
    });

    it('refuses staff with no catalogue permission at all', function () {
        $stranger = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($stranger)
            ->get(route('admin.catalog.categories.index'))
            ->assertForbidden();
    });

    it('lets a product manager write it', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), [
                'name' => 'Electronics',
                'is_active' => true,
            ])
            ->assertRedirect();

        expect(Category::query()->where('name', 'Electronics')->exists())->toBeTrue();
    });
});

describe('validation and database constraints', function () {
    it('requires a name', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    });

    it('generates a slug when none is given', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), ['name' => 'Home & Kitchen']);

        expect(Category::query()->firstOrFail()->slug)->toBe('home-kitchen');
    });

    it('refuses a slug another category already holds', function () {
        catalogCategory(['name' => 'Electronics', 'slug' => 'electronics']);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), [
                'name' => 'Consumer electronics',
                'slug' => 'electronics',
            ])
            ->assertSessionHasErrors('slug');
    });

    it('refuses a slug with characters a URL cannot carry', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), [
                'name' => 'Electronics',
                'slug' => 'Electronics Ltd!',
            ])
            ->assertSessionHasErrors('slug');
    });

    it('refuses a parent that does not exist', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), [
                'name' => 'Phones',
                'parent_id' => 'not-a-category',
            ])
            ->assertSessionHasErrors('parent_id');
    });

    it('holds the slug unique in the database, not only in validation', function () {
        // Validation catches the ordinary case; the index is what holds when
        // two requests arrive together and both pass it.
        catalogCategory(['name' => 'Electronics', 'slug' => 'electronics']);

        expect(fn () => Category::create(['name' => 'Other', 'slug' => 'electronics']))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('the tree stays a tree', function () {
    it('allows a subcategory', function () {
        $parent = catalogCategory(['name' => 'Electronics']);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), [
                'name' => 'Phones',
                'parent_id' => $parent->public_id,
            ])
            ->assertRedirect();

        $child = Category::query()->where('name', 'Phones')->firstOrFail();

        expect($child->parent_id)->toBe($parent->id)
            ->and($child->depth())->toBe(2);
    });

    it('refuses a third level', function () {
        /*
         * §11.3 describes categories and subcategories, not an arbitrary
         * hierarchy — and a tree nobody bounded is one that cannot be rendered
         * in a menu or reasoned about in a filter.
         */
        $parent = catalogCategory(['name' => 'Electronics']);
        $child = catalogCategory(['name' => 'Phones', 'parent_id' => $parent->id]);

        expect(fn () => app(ManageCategories::class)->create($this->manager, [
            'name' => 'Smartphones',
            'parent_id' => $child->public_id,
        ]))->toThrow(CatalogRefused::class, 'cannot have subcategories');
    });

    it('refuses to move a category inside itself', function () {
        // A cycle makes every walk of the tree run until it exhausts memory.
        $category = catalogCategory(['name' => 'Electronics']);

        expect(fn () => app(ManageCategories::class)->update($this->manager, $category, [
            'parent_id' => $category->public_id,
        ]))->toThrow(CatalogRefused::class, 'inside itself');
    });

    it('refuses to move a category inside its own subcategory', function () {
        $parent = catalogCategory(['name' => 'Electronics']);
        $child = catalogCategory(['name' => 'Phones', 'parent_id' => $parent->id]);

        expect(fn () => app(ManageCategories::class)->update($this->manager, $parent, [
            'parent_id' => $child->public_id,
        ]))->toThrow(CatalogRefused::class, 'inside itself');
    });

    it('refuses to move a category with children to the deepest level', function () {
        // Its children would end up one level below the cap, which is the same
        // violation one step removed.
        $electronics = catalogCategory(['name' => 'Electronics']);
        catalogCategory(['name' => 'Phones', 'parent_id' => $electronics->id]);

        $other = catalogCategory(['name' => 'Home']);
        $otherChild = catalogCategory(['name' => 'Kitchen', 'parent_id' => $other->id]);

        expect(fn () => app(ManageCategories::class)->update($this->manager, $electronics, [
            'parent_id' => $otherChild->public_id,
        ]))->toThrow(CatalogRefused::class);
    });
});

describe('switching a category off rather than deleting it (§11.3)', function () {
    it('switches one off without touching its products or history', function () {
        $category = catalogCategory(['name' => 'Electronics']);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.categories.active', $category->public_id), [
                'is_active' => false,
            ])
            ->assertRedirect();

        expect($category->refresh()->is_active)->toBeFalse()
            ->and(Category::query()->count())->toBe(1);
    });

    it('takes subcategories off with the parent', function () {
        /*
         * A live subcategory under a disabled parent would stay on partner
         * storefronts, which is the opposite of what the administrator asked
         * for.
         */
        $parent = catalogCategory(['name' => 'Electronics']);
        $child = catalogCategory(['name' => 'Phones', 'parent_id' => $parent->id]);

        app(ManageCategories::class)->setActive($this->manager, $parent, false);

        expect($child->refresh()->is_active)->toBeTrue()
            ->and($child->isAvailable())->toBeFalse();
    });

    it('removes an empty category', function () {
        $category = catalogCategory(['name' => 'Electronics']);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.categories.destroy', $category->public_id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(Category::query()->count())->toBe(0);
    });

    it('refuses to remove a category that still has subcategories', function () {
        $parent = catalogCategory(['name' => 'Electronics']);
        catalogCategory(['name' => 'Phones', 'parent_id' => $parent->id]);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.categories.destroy', $parent->public_id))
            ->assertSessionHasErrors('category');

        expect(Category::query()->count())->toBe(2);
    });
});

describe('the screen', function () {
    it('lists disabled categories alongside live ones', function () {
        // "Why has that range stopped showing" is answered by seeing it
        // switched off. A list that hides it answers nothing.
        catalogCategory(['name' => 'Electronics']);
        catalogCategory(['name' => 'Retired', 'is_active' => false]);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.categories.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/catalog/categories')
                ->has('categories', 2)
                ->where('can.create', true)
                ->where('can.edit', true),
            );
    });

    it('tells a read-only viewer they may not write', function () {
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);

        $this->actingAs($viewer)
            ->get(route('admin.catalog.categories.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', false)
                ->where('can.edit', false)
                ->where('can.delete', false),
            );
    });

    it('reports whether a category is actually reachable', function () {
        $parent = catalogCategory(['name' => 'Electronics', 'is_active' => false]);
        catalogCategory(['name' => 'Phones', 'parent_id' => $parent->id]);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.categories.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.1.is_active', true)
                ->where('categories.1.is_available', false),
            );
    });
});

describe('the category image and slug', function () {
    beforeEach(fn () => Storage::fake('public'));

    it('stores an uploaded tile and sends its address to the screen', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), [
                'name' => 'Electronics',
                'image' => UploadedFile::fake()->image('tile.png', 300, 300),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $category = Category::query()->firstOrFail();

        expect($category->image_path)->toStartWith('catalog/categories/')
            ->and($category->image_path)->toEndWith('.png');

        Storage::disk('public')->assertExists((string) $category->image_path);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.categories.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.image_url', fn (string $url) => str_contains($url, (string) $category->image_path))
                ->where('limits.image_max_kb', 2048),
            );
    });

    it('refuses a file that is not an image, and writes nothing', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), [
                'name' => 'Electronics',
                'image' => UploadedFile::fake()->create('brochure.pdf', 50, 'application/pdf'),
            ])
            ->assertSessionHasErrors('image');

        expect(Category::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('keeps the old tile when a move is refused, and discards the new one', function () {
        /*
         * The file is written before the transaction, because a rollback cannot
         * unwrite it. What has to hold is the other half: the category still
         * points at a file that exists, and the upload nobody will reference is
         * gone.
         */
        $electronics = app(ManageCategories::class)->create(
            $this->manager,
            ['name' => 'Electronics'],
            UploadedFile::fake()->image('old.png'),
        );
        catalogCategory(['name' => 'Phones', 'parent_id' => $electronics->id]);

        $home = catalogCategory(['name' => 'Home']);
        $kitchen = catalogCategory(['name' => 'Kitchen', 'parent_id' => $home->id]);

        $original = (string) $electronics->image_path;

        expect(fn () => app(ManageCategories::class)->update(
            $this->manager,
            $electronics,
            ['parent_id' => $kitchen->public_id],
            UploadedFile::fake()->image('new.png'),
        ))->toThrow(CatalogRefused::class);

        expect($electronics->refresh()->image_path)->toBe($original);

        Storage::disk('public')->assertExists($original);
        expect(Storage::disk('public')->allFiles())->toBe([$original]);
    });

    it('replaces a tile and removes the one it replaced', function () {
        $category = app(ManageCategories::class)->create(
            $this->manager,
            ['name' => 'Electronics'],
            UploadedFile::fake()->image('old.png'),
        );

        $old = (string) $category->image_path;

        $updated = app(ManageCategories::class)->update(
            $this->manager,
            $category,
            ['name' => 'Electronics'],
            UploadedFile::fake()->image('new.png'),
        );

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists((string) $updated->image_path);
    });

    it('takes a slug the administrator typed', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.store'), [
                'name' => 'Consumer electronics',
                'slug' => 'gadgets',
            ])
            ->assertSessionHasNoErrors();

        expect(Category::query()->firstOrFail()->slug)->toBe('gadgets');
    });
});

describe('navigation', function () {
    /*
     * Read out of the raw prop rather than through Inertia's dotted path
     * assertions: the abilities are a flat map keyed `catalog.view`, and a
     * dotted assertion would read that as three nested keys.
     */
    it('sends the catalogue ability to somebody who may see it', function () {
        $response = $this->actingAs($this->manager)
            ->get(route('admin.catalog.categories.index'));

        $response->assertOk();

        expect($response->viewData('page')['props']['permissions']['catalog.view'] ?? null)
            ->toBeTrue();
    });

    it('sends it as false to a business account holder, so the link never appears', function () {
        $account = testBusinessAccount(AccountStatus::Active);

        $response = $this->actingAs($account->owner)->get(route('dashboard'));

        $response->assertOk();

        expect($response->viewData('page')['props']['permissions']['catalog.view'] ?? null)
            ->toBeFalse();
    });
});

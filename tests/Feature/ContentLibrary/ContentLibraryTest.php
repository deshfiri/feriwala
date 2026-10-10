<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\ContentLibrary\ContentBlocks;
use App\Domain\ContentLibrary\Models\ContentLibraryItem;
use App\Domain\Storage\Models\StoredFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Content Library: content written once as blocks (text, image, video,
 * link) and released to chosen Products. Data, never HTML; staff only.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::ContentManager);
    $this->a = websiteTestProduct(['name' => 'Cotton Pants']);
    $this->b = websiteTestProduct(['name' => 'Denim Jacket']);
});

function contentLibraryPayload(array $overrides = []): array
{
    return [
        'title' => 'Care guide',
        'product_ids' => [test()->a->public_id],
        'blocks' => [
            ['type' => 'text', 'text' => "Wash cold.\nHang dry."],
            ['type' => 'link', 'url' => 'https://example.com/care', 'label' => 'Full guide'],
        ],
        ...$overrides,
    ];
}

describe('releasing content', function () {
    it('publishes blocks to every chosen Product at once, audited', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.content-library.store'), contentLibraryPayload(['product_ids' => [$this->a->public_id, $this->b->public_id]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.content-library.index'));

        $item = ContentLibraryItem::query()->firstOrFail();

        expect($item->title)->toBe('Care guide')
            ->and($item->blocks)->toHaveCount(2)
            ->and($item->products()->count())->toBe(2)
            ->and($item->created_by)->toBe($this->staff->id)
            ->and($this->a->libraryContent()->count())->toBe(1)
            ->and($this->b->libraryContent()->count())->toBe(1);
    });

    it('sets no limit on how many blocks, how long a text or how many Products', function () {
        $products = collect(range(1, 60))->map(fn ($n) => websiteTestProduct(['name' => "Bulk {$n}"]))->pluck('public_id')->all();
        $blocks = array_map(fn ($n) => ['type' => 'text', 'text' => "Block {$n}"], range(1, 120));
        $blocks[] = ['type' => 'text', 'text' => str_repeat('long text ', 5000)];

        $this->actingAs($this->staff)
            ->post(route('admin.content-library.store'), contentLibraryPayload([
                'title' => str_repeat('T', 400), 'product_ids' => $products, 'blocks' => $blocks,
            ]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $item = ContentLibraryItem::query()->firstOrFail();

        expect($item->blocks)->toHaveCount(121)
            ->and(mb_strlen($item->title))->toBe(400)
            ->and($item->products()->count())->toBe(60);
    });

    it('needs a title, at least one Product and at least one block', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.content-library.store'), ['title' => '', 'product_ids' => [], 'blocks' => []])
            ->assertSessionHasErrors(['title', 'product_ids', 'blocks']);

        $this->post(route('admin.content-library.store'), contentLibraryPayload(['product_ids' => ['does-not-exist']]))
            ->assertSessionHasErrors('blocks');

        expect(ContentLibraryItem::query()->count())->toBe(0);
    });

    it('refuses an unknown block type and a javascript link, storing nothing', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.content-library.store'), contentLibraryPayload(['blocks' => [['type' => 'html', 'text' => '<script>x</script>']]]))
            ->assertSessionHasErrors('blocks.0.type');

        $this->post(route('admin.content-library.store'), contentLibraryPayload(['blocks' => [['type' => 'link', 'url' => 'javascript:alert(1)', 'label' => 'x']]]))
            ->assertSessionHasErrors('blocks');

        expect(ContentLibraryItem::query()->count())->toBe(0);
    });

    it('stores only the keys each block owns, as plain data', function () {
        $this->actingAs($this->staff)->post(route('admin.content-library.store'), contentLibraryPayload([
            'blocks' => [['type' => 'text', 'text' => '<b>bold</b>', 'url' => 'https://stray.example', 'file_id' => 'x']],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        expect(ContentLibraryItem::query()->firstOrFail()->blocks)->toEqual([['type' => 'text', 'text' => '<b>bold</b>']]);
    });

    it('uploads an image and a video, and lets a block use only a library upload of the right kind', function () {
        Storage::fake('public');

        $image = $this->actingAs($this->staff)
            ->post(route('admin.content-library.uploads.store'), ['kind' => 'image', 'file' => UploadedFile::fake()->image('a.png', 40, 40)])
            ->assertCreated()->json();

        expect($image)->toHaveKeys(['id', 'url', 'mime_type']);

        // A text file is not an image.
        $this->post(route('admin.content-library.uploads.store'), ['kind' => 'image', 'file' => UploadedFile::fake()->create('a.txt', 2, 'text/plain')])
            ->assertSessionHasErrors('file');

        // A block may not name a file that is not a library upload.
        $other = StoredFile::query()->create([
            'purpose' => 'kyc', 'disk' => 'public', 'path' => 'x.png', 'visibility' => 'public',
            'mime_type' => 'image/png', 'size_bytes' => 1, 'is_encrypted' => false,
        ]);

        $this->post(route('admin.content-library.store'), contentLibraryPayload(['blocks' => [['type' => 'image', 'file_id' => $other->public_id]]]))
            ->assertSessionHasErrors('blocks');

        // An image file cannot sit in a video block either.
        $this->post(route('admin.content-library.store'), contentLibraryPayload(['blocks' => [['type' => 'video', 'file_id' => $image['id']]]]))
            ->assertSessionHasErrors('blocks');

        $this->post(route('admin.content-library.store'), contentLibraryPayload(['blocks' => [['type' => 'image', 'file_id' => $image['id'], 'alt' => 'Pants']]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.content-library.index'));

        $item = ContentLibraryItem::query()->firstOrFail();

        expect($item->blocks[0]['file_id'])->toBe($image['id'])
            ->and(app(ContentBlocks::class)->present($item->blocks)[0]['url'])->toBe($image['url']);
    });

    it('needs a video block to have either an upload or a link, not both', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.content-library.store'), contentLibraryPayload(['blocks' => [['type' => 'video']]]))
            ->assertSessionHasErrors('blocks');

        $this->post(route('admin.content-library.store'), contentLibraryPayload(['blocks' => [['type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ']]]))
            ->assertSessionHasNoErrors()->assertRedirect();

        expect(ContentLibraryItem::query()->count())->toBe(1);
    });
});

describe('editing and removing', function () {
    it('updates what was released and the Products it shows on', function () {
        $this->actingAs($this->staff)->post(route('admin.content-library.store'), contentLibraryPayload());
        $item = ContentLibraryItem::query()->firstOrFail();

        $this->get(route('admin.content-library.edit', $item->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('item.title', 'Care guide')->has('item.blocks', 2)->has('item.products', 1));

        $this->patch(route('admin.content-library.update', $item->public_id), contentLibraryPayload([
            'title' => 'Care guide v2', 'product_ids' => [$this->b->public_id],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        expect($item->refresh()->title)->toBe('Care guide v2')
            ->and($this->a->libraryContent()->count())->toBe(0)
            ->and($this->b->libraryContent()->count())->toBe(1);
    });

    it('takes content down from every Product with a soft delete', function () {
        $this->actingAs($this->staff)->post(route('admin.content-library.store'), contentLibraryPayload());
        $item = ContentLibraryItem::query()->firstOrFail();

        $this->delete(route('admin.content-library.destroy', $item->public_id))->assertSessionHasNoErrors()->assertRedirect();

        expect($this->a->libraryContent()->count())->toBe(0)
            ->and(ContentLibraryItem::withTrashed()->count())->toBe(1);
    });
});

describe('what partners see', function () {
    it('resolves blocks to addresses, embeds only YouTube and Vimeo, and drops a missing file', function () {
        $item = ContentLibraryItem::create([
            'title' => 'T', 'published_at' => now(),
            'blocks' => [
                ['type' => 'text', 'text' => 'Hello'],
                ['type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ'],
                ['type' => 'video', 'url' => 'https://evil.example/watch'],
                ['type' => 'image', 'file_id' => 'gone'],
            ],
        ]);

        $presented = app(ContentBlocks::class)->present($item->blocks);

        expect($presented)->toHaveCount(3)
            ->and($presented[1]['embed_url'])->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
            ->and($presented[2]['embed_url'])->toBeNull();
    });

    it('shows library content in the admin Product\'s Content section, with what the viewer may do', function () {
        $admin = testPlatformStaff(PlatformRole::Admin);
        $this->actingAs($admin)->post(route('admin.content-library.store'), contentLibraryPayload())->assertRedirect();
        $item = ContentLibraryItem::query()->firstOrFail();

        $this->get(route('admin.catalog.products.edit', $this->a->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('library_content.items', 1)
                ->where('library_content.items.0.id', $item->public_id)
                ->has('library_content.items.0.blocks', 2)
                ->where('library_content.can.edit', true)
                ->where('library_content.can.delete', true));

        // A Product it was not released to shows nothing; a viewer without
        // library access gets no section at all.
        $this->get(route('admin.catalog.products.edit', $this->b->public_id))
            ->assertInertia(fn (Assert $page) => $page->has('library_content.items', 0));

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->get(route('admin.catalog.products.edit', $this->a->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('library_content', null));
    });

    it('shows released content on the Product page, and not once it is removed', function () {
        $this->actingAs($this->staff)->post(route('admin.content-library.store'), contentLibraryPayload());
        $item = ContentLibraryItem::query()->firstOrFail();

        expect($this->a->fresh()->libraryContent)->toHaveCount(1);

        $item->delete();

        expect($this->a->fresh()->libraryContent)->toHaveCount(0);
    });
});

describe('who may use it', function () {
    it('is closed to staff without the permission and to business accounts', function () {
        $outsider = testPlatformStaff(PlatformRole::SmsManager);
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        foreach ([$outsider, $owner] as $user) {
            $this->actingAs($user)->get(route('admin.content-library.index'))->assertForbidden();
            $this->post(route('admin.content-library.store'), contentLibraryPayload())->assertForbidden();
            $this->postJson(route('admin.content-library.uploads.store'), [])->assertForbidden();
            $this->getJson(route('admin.content-library.products', ['q' => 'cotton']))->assertForbidden();
        }

        expect(ContentLibraryItem::query()->count())->toBe(0);
    });

    it('lets a viewer read but neither publish, edit nor remove', function () {
        $this->actingAs($this->staff)->post(route('admin.content-library.store'), contentLibraryPayload());
        $item = ContentLibraryItem::query()->firstOrFail();

        $viewer = testPlatformStaff(PlatformRole::SmsManager);
        $viewer->givePermissionTo('content_library.view');

        $this->actingAs($viewer)->get(route('admin.content-library.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.publish', false)->where('can.delete', false)->has('items.data', 1));

        $this->post(route('admin.content-library.store'), contentLibraryPayload())->assertForbidden();
        $this->patch(route('admin.content-library.update', $item->public_id), contentLibraryPayload(['title' => 'X']))->assertForbidden();
        $this->delete(route('admin.content-library.destroy', $item->public_id))->assertForbidden();

        expect($item->refresh()->title)->toBe('Care guide');
    });

    it('lists Products for the side panel before any search, and a search narrows the list', function () {
        $all = collect($this->actingAs($this->staff)->getJson(route('admin.content-library.products'))->assertOk()->json('data'))->pluck('id')->all();

        expect($all)->toContain($this->a->public_id, $this->b->public_id);

        // Past one page, every Product is still reachable: nothing is cut off.
        foreach (range(1, 45) as $n) {
            websiteTestProduct(['name' => sprintf('Zed %02d', $n)]);
        }

        $first = $this->getJson(route('admin.content-library.products', ['q' => 'Zed']))->assertOk()->json();
        $second = $this->getJson(route('admin.content-library.products', ['q' => 'Zed', 'page' => 2]))->assertOk()->json();

        expect($first['data'])->toHaveCount(40)->and($first['has_more'])->toBeTrue()
            ->and($second['data'])->toHaveCount(5)->and($second['has_more'])->toBeFalse();

        // The Product links search is unchanged: nothing without a real term.
        $this->getJson(route('admin.catalog.product-links.search', ['q' => '']))->assertForbidden();
    });

    it('searches Products for the picker by BPC and title', function () {
        $found = collect($this->actingAs($this->staff)->getJson(route('admin.content-library.products', ['q' => 'denim']))->assertOk()->json('data'))->pluck('id')->all();

        expect($found)->toBe([$this->b->public_id]);
    });
});

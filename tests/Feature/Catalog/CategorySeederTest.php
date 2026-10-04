<?php

use App\Domain\Catalog\Models\Category;
use Database\Seeders\CategorySeeder;

/*
 * The reference category tree bundled with the app (§11.3).
 *
 * Tested for the same reason DemoSeederTest covers its own seeder: a seeder
 * that has silently stopped working is otherwise discovered by somebody
 * relying on it, not by the suite.
 */

it('seeds every top category with its subcategories, within the depth cap', function () {
    $this->seed(CategorySeeder::class);

    expect(Category::query()->whereNull('parent_id')->count())->toBe(20)
        ->and(Category::query()->whereNotNull('parent_id')->count())->toBe(178)
        ->and(Category::query()->where('name', 'Electronics')->first()->children)->toHaveCount(12);

    $electronics = Category::query()->where('name', 'Electronics')->firstOrFail();
    $mobilePhones = $electronics->children()->where('name', 'Mobile Phones')->firstOrFail();

    expect($electronics->depth())->toBe(1)
        ->and($mobilePhones->depth())->toBe(2)
        ->and($mobilePhones->isAvailable())->toBeTrue();
});

it('gives every category a unique slug even when the same subcategory name repeats under different parents', function () {
    // "Kids Shoes" sits under both Kids & Baby Fashion and Shoes & Accessories.
    $this->seed(CategorySeeder::class);

    $kidsShoes = Category::query()->where('name', 'Kids Shoes')->get();

    expect($kidsShoes)->toHaveCount(2)
        ->and($kidsShoes->pluck('slug')->unique())->toHaveCount(2);
});

it('changes nothing when run again', function () {
    $this->seed(CategorySeeder::class);
    $before = Category::query()->count();

    $this->seed(CategorySeeder::class);

    expect(Category::query()->count())->toBe($before);
});

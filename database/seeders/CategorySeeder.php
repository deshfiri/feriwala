<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Seeder;

/**
 * The initial category tree (§11.3): one level of parent categories, each
 * with its subcategories.
 *
 * Matched by `name` and `parent_id` with `updateOrCreate`, so running this
 * again against a populated database adds nothing new and changes nothing
 * that already matches — safe to rerun, including in production.
 */
class CategorySeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, subcategories: array<int, string>}>
     */
    protected const CATEGORIES = [
        ['name' => 'Electronics', 'subcategories' => [
            'Mobile Phones', 'Tablets', 'Laptops', 'Computers', 'Computer Accessories',
            'Mobile Accessories', 'Audio & Headphones', 'Smartwatches & Wearables',
            'Cameras', 'Gaming', 'Networking', 'Storage Devices',
        ]],
        ['name' => 'Home Appliances', 'subcategories' => [
            'Refrigerators', 'Air Conditioners', 'Washing Machines', 'Fans', 'TVs',
            'Vacuum Cleaners', 'Irons & Steamers', 'Water Purifiers', 'Water Heaters',
            'Small Appliances',
        ]],
        ['name' => 'Kitchen & Dining', 'subcategories' => [
            'Cookware', 'Kitchen Appliances', 'Kitchen Tools', 'Dinnerware', 'Drinkware',
            'Food Storage', 'Kitchen Organizers', 'Bakeware',
        ]],
        ['name' => 'Home & Living', 'subcategories' => [
            'Home Decor', 'Bedding', 'Bath', 'Curtains', 'Rugs & Carpets',
            'Storage & Organization', 'Cleaning Supplies', 'Household Essentials',
        ]],
        ['name' => 'Furniture', 'subcategories' => [
            'Bedroom Furniture', 'Living Room Furniture', 'Dining Furniture',
            'Office Furniture', 'Kids Furniture', 'Storage Furniture', 'Outdoor Furniture',
        ]],
        ['name' => "Men's Fashion", 'subcategories' => [
            'T-Shirts', 'Shirts', 'Polo Shirts', 'Panjabi & Kurtas', 'Jeans', 'Trousers',
            'Shorts', 'Jackets', 'Sportswear', 'Innerwear',
        ]],
        ['name' => "Women's Fashion", 'subcategories' => [
            'Sarees', 'Salwar Kameez', 'Three-Piece Sets', 'Kurtis', 'Tops', 'Dresses',
            'Abayas & Burqas', 'Hijabs', 'Jeans & Trousers', 'Nightwear', 'Innerwear',
        ]],
        ['name' => 'Kids & Baby Fashion', 'subcategories' => [
            'Baby Clothing', 'Boys Clothing', 'Girls Clothing', 'Kids Shoes', 'School Wear',
            'Baby Accessories', 'Kids Accessories',
        ]],
        ['name' => 'Shoes & Accessories', 'subcategories' => [
            "Men's Shoes", "Women's Shoes", 'Kids Shoes', 'Bags', 'Backpacks', 'Wallets',
            'Belts', 'Sunglasses', 'Watches', 'Fashion Accessories',
        ]],
        ['name' => 'Beauty & Personal Care', 'subcategories' => [
            'Skincare', 'Hair Care', 'Makeup', 'Body Care', 'Oral Care', 'Grooming',
            'Fragrances', 'Beauty Tools', 'Personal Care Appliances',
        ]],
        ['name' => 'Health & Wellness', 'subcategories' => [
            'Health Devices', 'First Aid', 'Personal Hygiene', 'Fitness & Wellness',
            'Orthopedic Supports', 'Mobility Aids', 'Feminine Care',
        ]],
        ['name' => 'Mother & Baby', 'subcategories' => [
            'Diapers & Wipes', 'Baby Feeding', 'Baby Care', 'Baby Gear', 'Strollers',
            'Car Seats', 'Baby Bedding', 'Maternity Care', 'Baby Safety',
        ]],
        ['name' => 'Toys & Games', 'subcategories' => [
            'Educational Toys', 'Baby Toys', 'Building Toys', 'Dolls', 'Toy Vehicles',
            'Remote Control Toys', 'Puzzles', 'Board Games', 'Outdoor Toys', 'Arts & Crafts',
        ]],
        ['name' => 'Sports & Outdoors', 'subcategories' => [
            'Fitness Equipment', 'Cricket', 'Football', 'Badminton', 'Cycling', 'Swimming',
            'Camping & Hiking', 'Sportswear', 'Sports Accessories',
        ]],
        ['name' => 'Grocery & Food', 'subcategories' => [
            'Rice & Grains', 'Oil', 'Lentils', 'Spices', 'Snacks', 'Beverages',
            'Tea & Coffee', 'Breakfast Foods', 'Dairy', 'Cooking Essentials',
        ]],
        ['name' => 'Books & Stationery', 'subcategories' => [
            'Books', "Children's Books", 'Academic Books', 'Religious Books', 'Notebooks',
            'Writing Supplies', 'Art Supplies', 'School Supplies', 'Office Supplies',
        ]],
        ['name' => 'Automotive', 'subcategories' => [
            'Car Accessories', 'Motorcycle Accessories', 'Car Electronics',
            'Helmets & Riding Gear', 'Car Care', 'Oils & Lubricants', 'Spare Parts', 'Tools',
        ]],
        ['name' => 'Tools & Home Improvement', 'subcategories' => [
            'Hand Tools', 'Power Tools', 'Hardware', 'Electrical Supplies', 'Lighting',
            'Plumbing', 'Bathroom Accessories', 'Home Security', 'DIY Supplies',
        ]],
        ['name' => 'Pet Supplies', 'subcategories' => [
            'Dog Supplies', 'Cat Supplies', 'Bird Supplies', 'Fish & Aquarium', 'Pet Food',
            'Grooming', 'Pet Toys', 'Pet Accessories',
        ]],
        ['name' => 'Gifts & Lifestyle', 'subcategories' => [
            'Gifts', 'Personalized Gifts', 'Party Supplies', 'Religious Products',
            'Travel Accessories', 'Arts & Crafts', 'Musical Instruments',
        ]],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $position => $category) {
            $parent = Category::query()->updateOrCreate(
                ['name' => $category['name'], 'parent_id' => null],
                ['sort_order' => $position],
            );

            foreach ($category['subcategories'] as $subPosition => $name) {
                Category::query()->updateOrCreate(
                    ['name' => $name, 'parent_id' => $parent->id],
                    ['sort_order' => $subPosition],
                );
            }
        }

        $this->command->info('Categories: '.Category::query()->count().' total.');
    }
}

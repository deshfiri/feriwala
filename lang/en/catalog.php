<?php

return [
    'forbidden_title' => 'The catalogue is not yours to manage',
    'forbidden_description' => 'Products, categories and brands are written by Feriwala. Partners select from the catalogue rather than adding to it.',

    'categories' => [
        'title' => 'Product categories',
        'description' => 'How the catalogue is arranged, and what partner storefronts show.',
        'nav' => 'Product categories',

        'name' => 'Name',
        'slug' => 'URL slug',
        'slug_placeholder' => 'Left blank, one is made from the name',
        'slug_help' => 'Storefront links carry this. It is not changed automatically when the name changes, so existing links keep working.',
        'parent' => 'Parent category',
        'no_parent' => 'None — a top-level category',
        'field_description' => 'Description',

        'seo' => 'Search engine listing',
        'meta_title' => 'SEO title',
        'meta_description' => 'SEO description',
        'meta_keywords' => 'Keywords',
        'image_alt' => 'Image alt text',
        'image_alt_help' => 'Describes the category image for screen readers and for search.',
        'image' => 'Category image',
        'image_help' => 'JPEG, PNG or WebP, up to :size KB. Shown as the category tile on partner storefronts.',
        'remove_image' => 'Remove the current image',

        'create' => 'New category',
        'create_title' => 'New category',
        'edit_title' => 'Edit category',
        'dialog_description' => 'Categories and their subcategories. A category can hold products directly.',

        'search' => 'Search categories',
        'state_live' => 'Live',
        'state_off' => 'Switched off',
        'state_parent_off' => 'Parent is off',
        'enable' => 'Switch on',
        'disable' => 'Switch off',
        'products_count' => ':count products',

        'created' => ':name added.',
        'updated' => ':name saved.',
        'enabled' => ':name is live again.',
        'disabled' => ':name is switched off.',
        'deleted' => 'Category removed.',
        'delete_confirm' => 'Remove this category? Only an empty one can be removed — switch it off instead to keep its history.',

        'empty' => 'No categories yet',
        'empty_help' => 'Add the first one to start arranging the catalogue.',
        'no_matches' => 'Nothing matches that',
        'no_matches_help' => 'Try a different name or slug.',
    ],

    'brands' => [
        'title' => 'Brands',
        'description' => 'Who makes what the catalogue sells. Partners browse and filter by these.',
        'caption' => 'Product brands',

        'name' => 'Name',
        'name_taken' => 'A brand with that name already exists, whatever the capitals.',
        'slug' => 'URL slug',
        'slug_placeholder' => 'Left blank, one is made from the name',
        'slug_help' => 'Storefront links carry this. It is not changed automatically when the name changes, so existing links keep working.',
        'field_description' => 'Description',

        'logo' => 'Logo',
        'logo_help' => 'JPEG, PNG or WebP, up to :size KB.',
        'remove_logo' => 'Remove the current logo',
        'logo_alt' => 'Logo alt text',
        'logo_alt_help' => 'Describes the logo for screen readers and for search.',
        'no_logo' => 'No logo',

        'create' => 'New brand',
        'create_title' => 'New brand',
        'edit_title' => 'Edit brand',
        'dialog_description' => 'A brand says who made a product, not where it sits in the catalogue.',

        'search' => 'Search by name or slug',
        'filter_status' => 'Status',
        'filter_all' => 'All statuses',

        'columns' => [
            'brand' => 'Brand',
            'slug' => 'Slug',
            'status' => 'Status',
        ],

        'state_live' => 'Live',
        'state_off' => 'Switched off',
        'enable' => 'Switch on',
        'disable' => 'Switch off',
        'products_count' => ':count products',

        'created' => ':name added.',
        'updated' => ':name saved.',
        'enabled' => ':name is live again.',
        'disabled' => ':name is switched off.',
        'deleted' => 'Brand removed.',
        'delete_confirm' => 'Remove this brand? Its logo is removed with it. Switch it off instead to keep it on record.',

        'empty' => 'No brands yet',
        'empty_help' => 'Add the first brand so products can be assigned to it.',
        'no_matches' => 'No brand matches',
        'no_matches_help' => 'Try a different name, or clear the status filter.',
    ],
];

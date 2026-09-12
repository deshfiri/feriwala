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
];

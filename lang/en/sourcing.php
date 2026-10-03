<?php

return [
    'title' => 'Sourcing groups',
    'description' => 'Groups of catalogue products that can fulfil the same order. Suppliers and warehouses holding any product in a group appear together when allocating an order.',
    'search_placeholder' => 'Search by code or name',
    'all_statuses' => 'All statuses',
    'create' => 'New group',
    'open' => 'Open',
    'empty_title' => 'No sourcing groups yet',
    'empty_description' => 'Create a group, then add the products that can fulfil one another.',

    'status' => ['active' => 'Active', 'inactive' => 'Inactive'],

    'columns' => [
        'group' => 'Group',
        'products' => 'Products',
        'mappings' => 'Variant mappings',
        'status' => 'Status',
    ],

    'form' => [
        'code' => 'Code',
        'code_help' => 'Lowercase letters, numbers, dashes and underscores. Cannot be changed later.',
        'name_en' => 'Name (English)',
        'name_bn' => 'Name (Bangla)',
        'description' => 'Description',
        'reason' => 'Reason',
        'reason_help' => 'Recorded in the group history.',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'create_title' => 'New sourcing group',
    ],

    'tabs' => [
        'products' => 'Products',
        'variants' => 'Variant mapping',
        'sources' => 'Sources',
        'history' => 'History',
    ],

    'products' => [
        'canonical' => 'Canonical',
        'canonical_help' => 'Its variations define what an order requires.',
        'add_heading' => 'Add a product',
        'search_label' => 'Search products by name or SKU',
        'search_hint' => 'Type at least two letters. Products already in a group are not listed.',
        'no_matches' => 'No ungrouped product matches.',
        'add' => 'Add',
        'remove' => 'Remove',
        'none' => 'No products in this group yet.',
        'no_variants' => 'No variations',
    ],

    'mappings' => [
        'help' => 'A variation only fulfils a canonical variation through an explicit mapping. Black / M never matches Blue / L by label.',
        'product' => 'Member product',
        'variant' => 'Variation',
        'canonical_variant' => 'Fulfils canonical variation',
        'product_level' => 'Whole product',
        'add' => 'Add mapping',
        'remove' => 'Remove',
        'none' => 'No variant mappings yet.',
        'pick' => 'Choose…',
    ],

    'sources' => [
        'offers' => 'Supplier offers',
        'stock' => 'Warehouse stock',
        'no_offers' => 'No Supplier offers on these products.',
        'no_stock' => 'No warehouse stock on these products.',
        'available' => ':count available',
    ],

    'history' => [
        'none' => 'Nothing recorded yet.',
        'by' => 'by :name',
        'actions' => [
            'created' => 'Group created',
            'updated' => 'Group updated',
            'activated' => 'Group activated',
            'deactivated' => 'Group deactivated',
            'product_added' => 'Product added',
            'product_removed' => 'Product removed',
            'variant_mapped' => 'Variation mapped',
            'variant_unmapped' => 'Variation unmapped',
        ],
    ],

    'picker' => [
        'heading' => 'Sourcing group',
        'help' => 'Decides which orders this offer can fulfil. Required to approve; only staff can choose or create a group.',
        'search' => 'Search groups by name or code',
        'none' => 'No group matches.',
        'selected' => 'Selected',
        'create' => 'Create new group',
        'create_help' => 'The new group is selected as soon as it is created. Add its products later under Catalogue → Sourcing groups.',
        'empty_group' => 'no products yet',
        'canonical' => 'Fulfils orders for: :product',
        'locked' => 'This product already belongs to :name.',
        'canonical_variant' => 'Canonical variation it fulfils',
        'canonical_variant_help' => 'Needed when this product is not the group\'s canonical product. Never matched by label.',
        'canonical_variant_none' => 'Not needed / use existing mapping',
    ],

    'activate' => 'Activate',
    'deactivate' => 'Deactivate',

    'messages' => [
        'created' => 'Sourcing group created.',
        'updated' => 'Sourcing group updated.',
        'product_added' => 'Product added to the group.',
        'product_removed' => 'Product removed from the group.',
        'mapped' => 'Variation mapped.',
        'unmapped' => 'Mapping removed.',
    ],
];

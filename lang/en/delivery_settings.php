<?php

return [

    'admin' => [
        'title' => 'Delivery charge settings',
        'description' => 'Weight-tier rules and the global knobs the delivery-charge calculator applies on top of them.',
        'settings_title' => 'Global settings',
        'volumetric_divisor' => 'Volumetric-weight divisor',
        'volumetric_divisor_help' => 'Cubic centimetres per kilogram. Common couriers use 5000 or 6000.',
        'use_greater' => 'Use the greater of actual and volumetric weight',
        'additional_per_kg_charge' => 'Additional per-kg charge',
        'per_box_charge' => 'Per-box handling charge',
        'fragile_handling_charge' => 'Fragile / special-handling charge',
        'minimum_charge' => 'Minimum delivery charge',
        'maximum_charge' => 'Maximum delivery charge',
        'maximum_charge_help' => 'Blank means no cap.',
        'free_delivery_threshold' => 'Free-delivery order threshold',
        'free_delivery_threshold_help' => 'Blank means delivery is never free regardless of order value.',
        'settings_saved' => 'Delivery charge settings saved.',

        'rules_title' => 'Weight-tier rules',
        'rules_description' => 'Dated rules by chargeable weight and an optional area or courier scope. A rule is never edited, only closed and replaced.',
        'add_rule' => 'Add rule',
        'close' => 'Close',
        'rule_created' => 'Delivery charge rule created.',
        'rule_closed' => 'Delivery charge rule closed.',
        'no_rules' => 'No weight-tier rules configured yet. Delivery charges will be zero until one is added.',
        'active' => 'Active',
        'closed' => 'Closed',
        'general_scope' => 'General (no area or courier)',

        'weight_from_grams' => 'From weight (g)',
        'weight_to_grams' => 'To weight (g)',
        'weight_to_grams_help' => 'Blank means unbounded.',
        'base_charge' => 'Base charge',
        'per_kg_charge_override' => 'Per-kg charge override',
        'per_kg_charge_override_help' => 'Blank uses the global additional per-kg charge.',
        'area' => 'Area',
        'area_help' => 'A free-text zone label, matched against an order\'s own area when given.',
        'courier_provider' => 'Courier',
        'effective_from' => 'Effective from',
        'note' => 'Note',

        'columns' => [
            'weight_band' => 'Weight band',
            'charge' => 'Charge',
            'scope' => 'Scope',
            'status' => 'Status',
            'effective' => 'Effective',
        ],
    ],

];

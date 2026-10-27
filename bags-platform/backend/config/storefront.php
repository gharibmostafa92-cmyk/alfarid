<?php

return [
    'new_days' => 30,          // المنتج يعتبر NEW لو published_at خلال المدة دي
    'showcase_limit' => 6,
    'max_per_page' => 48,
    // الحدود شاملة (>= و <=) عشان كده ما فيش تداخل
    'price_ranges' => [
        ['id' => 'under-800', 'min' => null,    'max' => 799.99],
        ['id' => '800-1500',  'min' => 800,     'max' => 1500],
        ['id' => 'over-1500', 'min' => 1500.01, 'max' => null],
    ],
];

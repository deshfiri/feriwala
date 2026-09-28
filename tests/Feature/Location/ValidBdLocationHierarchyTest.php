<?php

use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Rules\ValidBdLocationHierarchy;
use Illuminate\Support\Facades\Validator;

it('accepts a division/district/upazila/union chain that actually nests', function () {
    $chain = addressTestLocationChain();

    $validator = Validator::make(
        [
            'division_id' => $chain['division']->id,
            'district_id' => $chain['district']->id,
            'upazila_id' => $chain['upazila']->id,
            'union_id' => $chain['union']->id,
        ],
        [
            'division_id' => [new ValidBdLocationHierarchy(BdLocationType::Division)],
            'district_id' => [new ValidBdLocationHierarchy(BdLocationType::District)],
            'upazila_id' => [new ValidBdLocationHierarchy(BdLocationType::Upazila)],
            'union_id' => [new ValidBdLocationHierarchy(BdLocationType::Union)],
        ],
    );

    expect($validator->passes())->toBeTrue();
});

it('rejects a union that does not belong to the chosen upazila', function () {
    $chain = addressTestLocationChain();
    $otherChain = addressTestLocationChain();

    $validator = Validator::make(
        [
            'division_id' => $chain['division']->id,
            'district_id' => $chain['district']->id,
            'upazila_id' => $chain['upazila']->id,
            'union_id' => $otherChain['union']->id,
        ],
        [
            'division_id' => [new ValidBdLocationHierarchy(BdLocationType::Division)],
            'district_id' => [new ValidBdLocationHierarchy(BdLocationType::District)],
            'upazila_id' => [new ValidBdLocationHierarchy(BdLocationType::Upazila)],
            'union_id' => [new ValidBdLocationHierarchy(BdLocationType::Union)],
        ],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('union_id'))->toBeTrue();
});

it('rejects an id that belongs to the wrong level', function () {
    $chain = addressTestLocationChain();

    $validator = Validator::make(
        ['district_id' => $chain['division']->id],
        ['district_id' => [new ValidBdLocationHierarchy(BdLocationType::District)]],
    );

    expect($validator->fails())->toBeTrue();
});

it('rejects a deactivated location', function () {
    $chain = addressTestLocationChain();
    $chain['upazila']->forceFill(['is_active' => false])->save();

    $validator = Validator::make(
        ['district_id' => $chain['district']->id, 'upazila_id' => $chain['upazila']->id],
        [
            'district_id' => [new ValidBdLocationHierarchy(BdLocationType::District)],
            'upazila_id' => [new ValidBdLocationHierarchy(BdLocationType::Upazila)],
        ],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('upazila_id'))->toBeTrue();
});

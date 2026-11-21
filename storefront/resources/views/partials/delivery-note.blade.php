{{-- What delivery costs, in one sentence, from the ERP's quote (D37). --}}
@php
    $delivery = $quote['delivery'] ?? ['status' => 'address_required', 'area' => null, 'is_free' => false];
    $areaLabel = $delivery['area'] !== null ? t('delivery.'.$delivery['area']) : '';
@endphp
@if ($quote['shipping'] === null)
    {{ t('delivery.choose_area') }}
@elseif ($delivery['is_free'])
    {{ t('delivery.note_free', ['area' => $areaLabel]) }}
@else
    {{ t('delivery.note_charge', ['area' => $areaLabel, 'amount' => money($quote['shipping'])]) }}
@endif

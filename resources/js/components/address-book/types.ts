import type { LocationOption } from '@/components/address-book/location-picker';

export type AddressLocationSnapshot = {
    division: { en: string; bn: string } | null;
    district: { en: string; bn: string } | null;
    upazila: { en: string; bn: string } | null;
    union: { en: string; bn: string } | null;
};

export type AddressRow = {
    id: string;
    type: string;
    is_active: boolean;
    is_default: boolean;
    contact_name: string;
    contact_mobile: string;
    detailed_address: string;
    landmark: string | null;
    postcode: string | null;
    location_snapshot: AddressLocationSnapshot;
    location: {
        division: LocationOption | null;
        district: LocationOption | null;
        upazila: LocationOption | null;
        union: LocationOption | null;
    };
};

/** What a `<Form>` needs to submit — the shape Wayfinder's `.form()` returns. */
export type AddressFormAction = {
    action: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

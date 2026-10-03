// @vitest-environment jsdom

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const translations = {
    orders: {
        admin: {
            allocation: {
                panel_title: 'Allocate :product',
                panel_description: 'Choose a source.',
                search: 'Search sources',
                sort: 'Sort',
                source_filter: 'Source type',
                source_filter_all: 'Suppliers and warehouses',
                sort_options: {
                    margin_desc: 'Margin high',
                    margin_asc: 'Margin low',
                    cost_asc: 'Cost low',
                    cost_desc: 'Cost high',
                },
                eligible_only: 'Only eligible',
                empty: 'No sources',
                no_matches: 'No matches',
                close: 'Close',
                confirm: 'Confirm',
                preferred: 'Preferred',
                same_group: 'Same sourcing group',
                group_title: 'Sourcing group: :group',
                group_help: 'Nothing is chosen for you.',
                unmatched_title: 'Unmatched — manual review',
                unmatched_help: 'No group.',
                related: 'Related',
                not_related: 'Not related',
                lead_time: ':days days',
                capacity: 'Capacity :capacity',
                columns: {
                    available: 'Available',
                    unit_cost: 'Cost',
                    platform_rate: 'Rate',
                    margin: 'Margin',
                },
                tabs: {
                    recommended: 'Recommended',
                    suppliers: 'All suppliers',
                    warehouses: 'All warehouses',
                },
            },
        },
    },
    status: {
        allocation_source: {
            supplier_offer: 'Supplier',
            warehouse: 'Warehouse',
        },
    },
};

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Form: ({ children }: { children: (s: unknown) => unknown }) =>
            createElement(
                'form',
                null,
                children({ processing: false, errors: {} }) as never,
            ),
        usePage: () => ({ props: { translations, locale: { current: 'en' } } }),
    };
});

vi.mock('@/actions/App/Http/Controllers/Admin/OrderController', () => ({
    default: {
        allocationCandidates: { url: () => '/sources' },
        searchAllocationSources: { url: () => '/sources/search' },
        confirmSourceLink: { form: () => ({}) },
        allocate: { form: () => ({}) },
    },
}));

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const candidate = (overrides: Record<string, unknown>) => ({
    source_type: 'supplier_offer',
    source_id: 'c1',
    source_label: 'Alpha Textiles',
    available: 10,
    reserved: 0,
    available_to_promise: 10,
    unit_cost: money('780.00'),
    platform_rate: money('1300.00'),
    expected_margin: money('1040.00'),
    currency_code: 'BDT',
    is_eligible: true,
    ineligible_reason: null,
    is_currently_allocated: false,
    supplier_id: 's1',
    supplier_name: 'Alpha Textiles',
    lead_time_days: 3,
    is_preferred: false,
    supplier_status: 'approved',
    offer_status: 'active',
    supply_mode: 'ready_stock',
    supply_mode_label: 'Ready stock',
    buyer_facing_availability_label: null,
    fulfilment_capacity: null,
    requires_confirmation: false,
    is_related: true,
    source_product_name: 'Cotton Pants',
    source_product_sku: 'CP-1',
    match_kind: 'group',
    ...overrides,
});

const { default: AllocationPanel } = await import('./allocation-panel');

function mockSources(body: Record<string, unknown>) {
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ json: () => Promise.resolve(body) })),
    );
}

const renderPanel = () =>
    render(
        <AllocationPanel
            open
            onOpenChange={vi.fn()}
            orderId="order-1"
            line={{ id: 'line-1', name: 'Regular Pants', sku: 'RP-M' }}
        />,
    );

afterEach(() => vi.unstubAllGlobals());

describe('the allocation panel', () => {
    it('explains the sourcing group and marks group sources, hiding catalogue-wide tabs', async () => {
        mockSources({
            remaining_quantity: 2,
            sourcing: {
                state: 'matched',
                group: {
                    id: 'g1',
                    code: 'regular-pants',
                    name_en: "Men's regular pants",
                    name_bn: 'প্যান্ট',
                    is_active: true,
                },
                canonical_product: { name: 'Regular Pants', sku: 'RP' },
                canonical_variant: 'RP-M — M',
            },
            candidates: [
                candidate({}),
                candidate({
                    source_type: 'warehouse',
                    source_id: 'w1',
                    source_label: 'Dhaka Central',
                    supplier_name: null,
                    supply_mode_label: 'Ready stock',
                }),
            ],
        });

        renderPanel();

        await waitFor(() =>
            expect(
                screen.getByText("Sourcing group: Men's regular pants"),
            ).toBeInTheDocument(),
        );
        expect(screen.getAllByText('Same sourcing group')).toHaveLength(2);
        expect(
            screen.queryByRole('button', { name: 'All suppliers' }),
        ).not.toBeInTheDocument();
    });

    it('filters between Supplier and Warehouse sources', async () => {
        mockSources({
            remaining_quantity: 2,
            sourcing: {
                state: 'matched',
                group: {
                    id: 'g1',
                    code: 'x',
                    name_en: 'Group',
                    name_bn: 'গ্রুপ',
                    is_active: true,
                },
                canonical_product: null,
                canonical_variant: null,
            },
            candidates: [
                candidate({}),
                candidate({
                    source_type: 'warehouse',
                    source_id: 'w1',
                    source_label: 'Dhaka Central',
                    supplier_name: null,
                }),
            ],
        });

        renderPanel();

        await screen.findByText('Dhaka Central');

        fireEvent.change(screen.getByLabelText('Source type'), {
            target: { value: 'warehouse' },
        });

        expect(screen.queryByText('Alpha Textiles')).not.toBeInTheDocument();
        expect(screen.getByText('Dhaka Central')).toBeInTheDocument();
    });

    it('flags an unmatched line for manual review and keeps the catalogue tabs', async () => {
        mockSources({
            remaining_quantity: 1,
            sourcing: {
                state: 'unmatched',
                group: null,
                canonical_product: null,
                canonical_variant: null,
            },
            candidates: [candidate({ match_kind: 'exact' })],
        });

        renderPanel();

        expect(
            await screen.findByText('Unmatched — manual review'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'All suppliers' }),
        ).toBeInTheDocument();
    });
});

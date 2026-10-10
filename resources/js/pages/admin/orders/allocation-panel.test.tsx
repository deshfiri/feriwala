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
                mode_filter: 'Supply mode',
                mode_filter_all: 'Any supply mode',
                mode_options: {
                    ready_stock: 'Ready stock',
                    on_demand: 'On demand',
                    pre_order: 'Pre-order',
                },
                sort_options: {
                    margin_desc: 'Margin high',
                    margin_asc: 'Margin low',
                    cost_asc: 'Cost low',
                    cost_desc: 'Cost high',
                    availability_desc: 'Availability',
                    lead_time_asc: 'Lead time',
                    name_asc: 'Name',
                },
                eligible_only: 'Only eligible',
                empty: 'No sources',
                no_matches: 'No matches',
                close: 'Close',
                confirm: 'Confirm',
                preferred: 'Preferred',
                same_product: 'Linked Product',
                unique_title: 'Unique Product',
                unique_help: 'Not linked to any other.',
                linked_title: ':count linked Product(s) included',
                linked_help: 'Nothing is chosen for you.',
                related: 'Related',
                not_related: 'Not related',
                lead_time: ':days days',
                capacity: 'Capacity :capacity',
                columns: {
                    available: 'Available',
                    payable: 'Payable',
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
    product_links: { summary: { bpc: 'BPC' } },
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
    expected_payable: money('1560.00'),
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
    source_product_bpc: 'AB12CD34E',
    source_variant_label: 'Black / XL',
    match_kind: 'linked_product',
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
    it('shows each linked-Product source with its BPC, variation and payable, hiding catalogue-wide tabs', async () => {
        mockSources({
            remaining_quantity: 2,
            sourcing: { linked_product_count: 2 },
            candidates: [
                candidate({}),
                candidate({
                    source_type: 'warehouse',
                    source_id: 'w1',
                    source_label: 'Dhaka Central',
                    supplier_name: null,
                    expected_payable: null,
                }),
            ],
        });

        renderPanel();

        await waitFor(() =>
            expect(
                screen.getByText('2 linked Product(s) included'),
            ).toBeInTheDocument(),
        );
        expect(screen.getAllByText('Linked Product')).toHaveLength(2);
        expect(screen.getAllByText(/BPC AB12CD34E · Black \/ XL/)).toHaveLength(
            2,
        );
        // Only a Supplier source carries a payable.
        expect(screen.getAllByText('Payable')).toHaveLength(1);
        expect(
            screen.queryByRole('button', { name: 'All suppliers' }),
        ).not.toBeInTheDocument();
    });

    it('filters between Supplier and Warehouse sources, and by supply mode', async () => {
        mockSources({
            remaining_quantity: 2,
            sourcing: { linked_product_count: 1 },
            candidates: [
                candidate({}),
                candidate({
                    source_id: 'c2',
                    source_label: 'Beta Mills',
                    supplier_name: 'Beta Mills',
                    supply_mode: 'pre_order',
                    supply_mode_label: 'Pre-order',
                }),
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

        fireEvent.change(screen.getByLabelText('Source type'), {
            target: { value: 'all' },
        });
        fireEvent.change(screen.getByLabelText('Supply mode'), {
            target: { value: 'pre_order' },
        });

        expect(screen.getByText('Beta Mills')).toBeInTheDocument();
        expect(screen.queryByText('Alpha Textiles')).not.toBeInTheDocument();
        expect(screen.queryByText('Dhaka Central')).not.toBeInTheDocument();
    });

    it('says a Product with no links is unique and keeps the catalogue tabs', async () => {
        mockSources({
            remaining_quantity: 1,
            sourcing: { linked_product_count: 0 },
            candidates: [candidate({ match_kind: 'exact' })],
        });

        renderPanel();

        expect(await screen.findByText('Unique Product')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'All suppliers' }),
        ).toBeInTheDocument();
    });
});

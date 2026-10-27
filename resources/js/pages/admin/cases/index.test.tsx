// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string | { url: string };
            children?: unknown;
        }) =>
            createElement(
                'a',
                { href: typeof href === 'string' ? href : href.url, ...rest },
                children as never,
            ),
        router: { get, post: vi.fn(), on: vi.fn(() => () => {}) },
        usePage: () => ({ props: { translations: {}, permissions: {} } }),
    };
});

const { default: AdminCases } = await import('./index');

type Props = ComponentProps<typeof AdminCases>;
type Row = Props['cases']['data'][number];

const row = (overrides: Partial<Row> = {}): Row => ({
    id: '01RET',
    kind: 'return',
    legacy: false,
    reference: 'RET-261020-AAAA1111',
    order: { reference: 'ORD-261015-TEST0001', id: '01ORDER' },
    exchange_order: null,
    partner: 'Karim Traders',
    store: 'Ayesha Fashion',
    product: { name: 'Cotton panjabi', variant: 'Navy / M', more: 0 },
    quantity: 2,
    status: 'requested',
    status_label: 'Requested',
    status_tone: 'warning',
    responsibility: 'partner',
    submitted_at: '2026-10-20T09:00:00+06:00',
    next_action: { key: 'review', label: 'Review the request' },
    waiting_for: 'Review the request',
    ...overrides,
});

function props(overrides: Partial<Props> = {}): Props {
    return {
        cases: {
            data: [row()],
            current_page: 1,
            last_page: 1,
            per_page: 25,
            from: 1,
            to: 1,
            total: 1,
        },
        filters: {
            type: null,
            status: null,
            partner: null,
            store: null,
            responsibility: null,
            from: null,
            to: null,
            search: null,
        },
        counts: {
            all: { total: 3, actionable: 2 },
            return: { total: 2, actionable: 1 },
            exchange: { total: 0, actionable: 0 },
            damage: { total: 1, actionable: 1 },
        },
        options: {
            kinds: ['return', 'exchange', 'damage'],
            partners: [{ id: '01PARTNER', name: 'Karim Traders' }],
            stores: [{ id: '01STORE', name: 'Ayesha Fashion' }],
            statuses: [
                { value: 'requested', label: 'Requested', kind: 'return' },
                { value: 'reported', label: 'Reported', kind: 'damage' },
            ],
        },
        ...overrides,
    };
}

describe('the returns, exchanges and damages queue', () => {
    beforeEach(() => {
        get.mockClear();
        window.history.replaceState({}, '', '/admin/cases');
    });

    it('offers a tab only for the kinds this person may see, with their counts', () => {
        render(
            <AdminCases
                {...props({
                    counts: {
                        all: { total: 2, actionable: 0 },
                        damage: { total: 2, actionable: 0 },
                    },
                    options: { ...props().options, kinds: ['damage'] },
                })}
            />,
        );

        const tabs = screen.getAllByRole('tab').map((tab) => tab.textContent);

        expect(tabs).toHaveLength(2);
        expect(tabs[0]).toContain('cases.tabs.all');
        expect(tabs[1]).toContain('cases.tabs.damage');
        expect(
            screen.queryByRole('tab', { name: /cases\.tabs\.return/ }),
        ).toBeNull();
        expect(
            screen.queryByRole('tab', { name: /cases\.tabs\.exchange/ }),
        ).toBeNull();
    });

    it('says how many are waiting for somebody on each tab, in words as well as a badge', () => {
        render(<AdminCases {...props()} />);

        expect(
            screen.getByRole('tab', { name: /cases\.tabs\.damage/ }),
        ).toHaveTextContent('1');
        // Said in words for a screen reader, not only as a coloured badge.
        expect(
            screen.getAllByRole('tab', { name: /cases\.tabs\.waiting/ }),
        ).toHaveLength(3);
    });

    it('filters by kind through the tabs, and by status, Partner and responsibility through the bar', async () => {
        const user = userEvent.setup();
        render(<AdminCases {...props()} />);

        await user.click(
            screen.getByRole('tab', { name: /cases\.tabs\.damage/ }),
        );
        expect(get).toHaveBeenLastCalledWith(
            '/admin/cases',
            expect.objectContaining({ type: 'damage' }),
            expect.anything(),
        );

        await user.selectOptions(
            screen.getByLabelText('cases.filters.partner'),
            '01PARTNER',
        );
        expect(get).toHaveBeenLastCalledWith(
            '/admin/cases',
            expect.objectContaining({ partner: '01PARTNER' }),
            expect.anything(),
        );

        await user.selectOptions(
            screen.getByLabelText('cases.filters.responsibility'),
            'banij',
        );
        expect(get).toHaveBeenLastCalledWith(
            '/admin/cases',
            expect.objectContaining({ responsibility: 'banij' }),
            expect.anything(),
        );

        await user.selectOptions(
            screen.getByLabelText('cases.filters.status'),
            'requested',
        );
        expect(get).toHaveBeenLastCalledWith(
            '/admin/cases',
            expect.objectContaining({ status: 'requested' }),
            expect.anything(),
        );
    });

    it('narrows the status choices to the kind being viewed', () => {
        window.history.replaceState({}, '', '/admin/cases?type=damage');
        render(
            <AdminCases
                {...props({ filters: { ...props().filters, type: 'damage' } })}
            />,
        );

        const statuses = within(screen.getByLabelText('cases.filters.status'))
            .getAllByRole('option')
            .map((option) => option.textContent);

        expect(statuses).toContain('Reported');
        expect(statuses).not.toContain('Requested');
    });

    it('shows the step this person may take, or who the case is waiting on, and never a customer', () => {
        render(
            <AdminCases
                {...props({
                    cases: {
                        ...props().cases,
                        data: [
                            row(),
                            row({
                                id: '01DMG',
                                kind: 'damage',
                                reference: 'DMG-261021-BBBB2222',
                                next_action: null,
                                waiting_for: 'Decide who bears the loss',
                            }),
                        ],
                        total: 2,
                    },
                })}
            />,
        );

        const table = screen.getByRole('table');

        expect(within(table).getByText('Review the request')).toBeVisible();
        expect(within(table).getByText('cases.waiting_for')).toBeVisible();
        expect(
            within(table).getByText('RET-261020-AAAA1111').closest('a'),
        ).toHaveAttribute('href', '/admin/cases/returns/01RET');
        expect(
            within(table).getByText('DMG-261021-BBBB2222').closest('a'),
        ).toHaveAttribute('href', '/admin/cases/damages/01DMG');
        expect(table.textContent).not.toMatch(/Ayesha Rahman|\+8801/);
    });

    it('marks a return from before the case workflow, and opens it on the page it always had', () => {
        render(
            <AdminCases
                {...props({
                    cases: {
                        ...props().cases,
                        data: [
                            row({
                                legacy: true,
                                next_action: null,
                                waiting_for: null,
                            }),
                        ],
                    },
                })}
            />,
        );

        const table = screen.getByRole('table');

        expect(within(table).getByText(/cases\.legacy/)).toBeVisible();
        expect(
            within(table).getByText('RET-261020-AAAA1111').closest('a'),
        ).toHaveAttribute('href', '/admin/returns/01RET');
    });

    it('says plainly when there is nothing, and when a filter matched nothing', () => {
        const { rerender } = render(
            <AdminCases
                {...props({
                    cases: {
                        ...props().cases,
                        data: [],
                        total: 0,
                        from: null,
                        to: null,
                    },
                })}
            />,
        );
        expect(screen.getByText('cases.empty')).toBeVisible();

        rerender(
            <AdminCases
                {...props({
                    cases: {
                        ...props().cases,
                        data: [],
                        total: 0,
                        from: null,
                        to: null,
                    },
                    filters: { ...props().filters, status: 'requested' },
                })}
            />,
        );
        expect(screen.getByText('cases.no_matches')).toBeVisible();
    });
});

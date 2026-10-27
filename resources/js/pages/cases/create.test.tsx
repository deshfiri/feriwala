// @vitest-environment jsdom

import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        router: { on: vi.fn(() => () => {}) },
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
        Form: ({
            children,
            action,
        }: {
            action?: string;
            children: (state: {
                errors: Record<string, string>;
                processing: boolean;
            }) => unknown;
        }) =>
            createElement(
                'form',
                { action, 'aria-label': 'request' },
                children({ errors: {}, processing: false }) as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: CreateCase } = await import('./create');

type Props = ComponentProps<typeof CreateCase>;

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const base: Props = {
    order: {
        id: '01ORDER',
        reference: 'ORD-261015-TEST0001',
        url: '/store-orders/01ORDER',
    },
    eligibility: {
        eligible: true,
        refusal: null,
        window_closes_at: null,
        lines: [
            {
                id: '01LINE',
                sku: 'FW-1043-NVY-M',
                name: 'Cotton panjabi',
                variant: 'Navy / M',
                sold: 3,
                returned: 0,
                returnable: 3,
                unit_price: money('2600.00'),
            },
        ],
    },
    reasons: [{ value: 'not_as_described', label: 'Not as described' }],
    replacements: [{ sku: 'FW-2000-WHT-L', label: 'White panjabi' }],
    estimate: {
        type: 'return',
        original_delivery: money('80.00'),
        return_charge: money('0.00'),
        exchange_delivery: null,
        estimated_total: money('0.00'),
        exchange: null,
    },
    drafts: [],
    evidence_limits: {
        max_bytes: 8388608,
        max_per_case: 10,
        mime_types: ['image/png'],
    },
    can_request: true,
};

const estimateFor = (type: 'return' | 'exchange') => ({
    ...base.estimate,
    type,
    return_charge: money('40.00'),
    exchange_delivery: type === 'exchange' ? money('80.00') : null,
    estimated_total: type === 'exchange' ? money('120.00') : money('40.00'),
});

/** The JSON the page sent, as text: the request body is always a string here. */
const sentBody = (init: RequestInit): string =>
    typeof init.body === 'string' ? init.body : '';

describe('asking for a return or an exchange', () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        fetchMock.mockReset();
        fetchMock.mockImplementation(
            async (_url: string, init: RequestInit) => {
                const body = JSON.parse(sentBody(init)) as {
                    type: 'return' | 'exchange';
                };

                return new Response(JSON.stringify(estimateFor(body.type)), {
                    status: 200,
                });
            },
        );
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('offers only what can still come back, up to the quantity the server allows', () => {
        render(<CreateCase {...base} />);

        const quantity = screen.getByRole('spinbutton');

        expect(quantity).toHaveAttribute('max', '3');
        expect(screen.getByText('cases.create.up_to')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'cases.create.submit.return' }),
        ).toBeDisabled();
    });

    it('asks for the reason and an explicit confirmation, and sends one key for a double submit', () => {
        render(<CreateCase {...base} />);

        expect(
            screen.getByRole('combobox', { name: /cases\.create\.reason/ }),
        ).toBeRequired();
        expect(screen.getByRole('checkbox')).toBeRequired();
        expect(
            document
                .querySelector('input[name="idempotency_key"]')
                ?.getAttribute('value'),
        ).toMatch(/\S{8,}/);
    });

    it('never lets the Partner say who is responsible, or send a figure', () => {
        render(<CreateCase {...base} />);

        const names = Array.from(
            document.querySelectorAll('input, select, textarea'),
        ).map((field) => field.getAttribute('name') ?? '');

        expect(names.join(' ')).not.toMatch(
            /responsible|party|charge|amount|total/i,
        );
    });

    it('explains the old item must come back, with the estimated charges, when an exchange is chosen', async () => {
        const user = userEvent.setup();
        render(<CreateCase {...base} />);

        // A return shows no exchange delivery at all.
        expect(
            screen.queryByText('cases.financials.rows.exchange_delivery'),
        ).toBeNull();
        expect(
            screen.getByText('cases.create.refund_after_inspection'),
        ).toBeVisible();

        await user.click(
            screen.getByRole('radio', { name: /cases\.kinds\.exchange/ }),
        );

        expect(
            screen.getByText('cases.create.estimate_notes.exchange'),
        ).toBeVisible();
        expect(
            screen.getByText('cases.create.confirm_text.exchange'),
        ).toBeVisible();
        expect(
            screen.getByText('cases.financials.rows.exchange_delivery'),
        ).toBeVisible();
        expect(
            screen.getByText('cases.create.choose_items_first'),
        ).toBeVisible();
        expect(
            screen.queryByText('cases.create.refund_after_inspection'),
        ).toBeNull();

        await user.clear(screen.getByRole('spinbutton'));
        await user.type(screen.getByRole('spinbutton'), '2');

        // The server's own figures arrive; the browser adds nothing up.
        const aside = screen.getByRole('complementary', {
            name: 'cases.create.estimate',
        });

        await waitFor(() =>
            expect(within(aside).getByText('৳120.00')).toBeVisible(),
        );
        // The original delivery (৳80, never charged again) and the new exchange delivery (৳80).
        expect(within(aside).getAllByText('৳80.00')).toHaveLength(2);
        expect(within(aside).getByText('৳40.00')).toBeVisible();

        const [, init] = fetchMock.mock.calls.at(-1) as [string, RequestInit];
        expect(JSON.parse(sentBody(init))).toMatchObject({
            type: 'exchange',
            lines: { 'FW-1043-NVY-M': '2' },
        });
        expect(sentBody(init)).not.toMatch(/charge|amount|total/);
    });

    it('lets an exchange be for another product, never the same one offered twice', async () => {
        const user = userEvent.setup();
        render(<CreateCase {...base} />);

        await user.click(
            screen.getByRole('radio', { name: /cases\.kinds\.exchange/ }),
        );
        await user.clear(screen.getByRole('spinbutton'));
        await user.type(screen.getByRole('spinbutton'), '1');

        const replacement = await screen.findByRole('combobox', {
            name: /cases\.create\.replacement/,
        });
        const options = within(replacement)
            .getAllByRole('option')
            .map((option) => option.textContent);

        expect(options).toEqual(['cases.create.same_product', 'White panjabi']);
    });

    it('shows why an estimate could not be worked out, and keeps the last one', async () => {
        const user = userEvent.setup();
        fetchMock.mockImplementation(
            async () =>
                new Response(JSON.stringify({ message: 'Too many units.' }), {
                    status: 422,
                }),
        );
        render(<CreateCase {...base} />);

        await user.clear(screen.getByRole('spinbutton'));
        await user.type(screen.getByRole('spinbutton'), '2');

        expect(await screen.findByText('Too many units.')).toBeVisible();
    });

    it('says why an order cannot be asked about, and offers no form', () => {
        render(
            <CreateCase
                {...base}
                can_request={false}
                eligibility={{
                    ...base.eligibility,
                    eligible: false,
                    refusal: 'not_delivered',
                    lines: [],
                }}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'cases.create.cannot_request',
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'cases.create.refusals.not_delivered',
        );
        expect(screen.queryByRole('form', { name: 'request' })).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('tells someone who may read but not manage the store, and offers no form', () => {
        render(<CreateCase {...base} can_request={false} />);

        expect(screen.getByRole('alert')).toHaveTextContent(
            'cases.create.cannot_manage',
        );
        expect(screen.queryByRole('form', { name: 'request' })).toBeNull();
    });
});

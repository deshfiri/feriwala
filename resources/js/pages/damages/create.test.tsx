// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';

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
                { action, 'aria-label': 'report' },
                children({ errors: {}, processing: false }) as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: ReportDamage } = await import('./create');

type Props = ComponentProps<typeof ReportDamage>;

const line = (
    overrides: Partial<Props['eligibility']['lines'][number]> = {},
) => ({
    id: '01LINE',
    sku: 'FW-1043-NVY-M',
    name: 'Cotton panjabi',
    variant: 'Navy / M',
    sold: 3,
    reported: 0,
    reportable: 3,
    supplier_backed: false,
    ...overrides,
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
        lines: [line()],
    },
    conditions: [
        { value: 'torn', label: 'Torn' },
        { value: 'stained', label: 'Stained' },
    ],
    minimum_description: 10,
    drafts: [],
    evidence_limits: {
        max_bytes: 8388608,
        max_per_case: 10,
        mime_types: ['image/png'],
    },
    can_report: true,
};

describe('reporting a damaged item', () => {
    it('offers the item, up to what can still be reported', () => {
        render(<ReportDamage {...base} />);

        expect(screen.getByRole('spinbutton')).toHaveAttribute('max', '3');
        expect(screen.getByText('damages.report.up_to')).toBeVisible();
        expect(
            screen.getByRole('option', { name: /Cotton panjabi/ }),
        ).toBeVisible();
    });

    it('asks whether the item comes back or stays with the customer, and explains no parcel for the second', async () => {
        const user = userEvent.setup();
        render(<ReportDamage {...base} />);

        expect(
            screen.getByRole('radio', {
                name: /damages\.report\.locations\.send_back/,
            }),
        ).toBeChecked();

        await user.click(
            screen.getByRole('radio', {
                name: /damages\.report\.locations\.stays_with_customer/,
            }),
        );

        expect(
            screen.getByText(
                'damages.report.locations_help.stays_with_customer',
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('radio', {
                name: /damages\.report\.locations\.stays_with_customer/,
            }),
        ).toBeChecked();
    });

    it('cannot be sent until the damage is described, and never offers responsibility or an amount', async () => {
        const user = userEvent.setup();
        render(<ReportDamage {...base} />);

        const submit = screen.getByRole('button', {
            name: 'damages.report.submit',
        });
        expect(submit).toBeDisabled();

        await user.type(
            screen.getByRole('textbox'),
            'The seam tore on arrival.',
        );
        expect(submit).toBeEnabled();

        const names = Array.from(
            document.querySelectorAll('input, select, textarea'),
        ).map((field) => field.getAttribute('name') ?? '');
        expect(names.join(' ')).not.toMatch(
            /responsible|party|loss|amount|price|total|status/i,
        );
        expect(
            screen.getByRole('checkbox', {
                name: /cases\.create\.confirm_label/,
            }),
        ).toBeRequired();
    });

    it('lists a Supplier-supplied item with its reason and no way to report it', () => {
        render(
            <ReportDamage
                {...base}
                eligibility={{
                    ...base.eligibility,
                    lines: [
                        line(),
                        line({
                            id: '01SUP',
                            name: 'Silk scarf',
                            sku: 'SC-1',
                            supplier_backed: true,
                        }),
                    ],
                }}
            />,
        );

        expect(screen.queryByRole('option', { name: /Silk scarf/ })).toBeNull();
        expect(screen.getByText(/Silk scarf/)).toBeVisible();
        expect(
            screen.getByText(/damages\.report\.supplier_backed/),
        ).toBeVisible();
    });

    it('says why nothing can be reported, and offers no form', () => {
        render(
            <ReportDamage
                {...base}
                can_report={false}
                eligibility={{
                    ...base.eligibility,
                    eligible: false,
                    refusal: 'damage_window_closed',
                }}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'damages.report.cannot_report',
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'damages.report.refusals.damage_window_closed',
        );
        expect(screen.queryByRole('form', { name: 'report' })).toBeNull();
    });

    it('explains a Supplier-only order, where nothing at all can be reported', () => {
        render(
            <ReportDamage
                {...base}
                can_report={false}
                eligibility={{
                    ...base.eligibility,
                    eligible: false,
                    refusal: 'supplier_backed',
                    lines: [line({ supplier_backed: true })],
                }}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'damages.report.refusals.supplier_backed',
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'damages.report.supplier_backed',
        );
    });
});

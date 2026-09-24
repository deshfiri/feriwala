// @vitest-environment jsdom

import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Earning } from '@/types/referral';
import type { ReferredBusiness } from './index';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
        }: {
            href: string | { url: string };
            children?: unknown;
        }) =>
            createElement(
                'a',
                { href: typeof href === 'string' ? href : href.url },
                children as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: Referrals } = await import('./index');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const paginator = <T,>(data: T[]) => ({
    data,
    current_page: 1,
    last_page: 1,
    per_page: 20,
    from: data.length > 0 ? 1 : null,
    to: data.length,
    total: data.length,
});

const earning = (overrides: Partial<Earning>): Earning => ({
    id: '01EARNING',
    level: 2,
    is_joining_reward: false,
    amount: money('325.00'),
    rule: { type: 'percentage', percent: '5', amount: null },
    status: 'paid',
    status_label: 'Paid',
    skip_reason: null,
    capped: false,
    available_at: '2026-09-19T10:00:00+06:00',
    paid_at: '2026-09-19T10:00:00+06:00',
    created_at: '2026-09-19T10:00:00+06:00',
    ...overrides,
});

const summary = {
    direct: 1,
    direct_active: 1,
    paid: money('325.00'),
    pending: money('0.00'),
    reversed: money('0.00'),
};

const referred: ReferredBusiness = {
    id: '01REFERRED',
    name: 'Sohel Supplies',
    state: 'joining',
    since: '2026-09-18T10:00:00+06:00',
};

describe("an account's own referrals", () => {
    it('shows the code and link, and copies them', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText } });

        render(
            <Referrals
                code="ABCD2345"
                link="https://erp.test/register?ref=ABCD2345"
                summary={summary}
                direct={paginator([referred])}
                earnings={paginator([earning({})])}
            />,
        );

        expect(screen.getByTestId('referral-code')).toHaveTextContent(
            'ABCD2345',
        );
        expect(
            screen.getByText('https://erp.test/register?ref=ABCD2345'),
        ).toBeInTheDocument();

        await act(async () => {
            screen
                .getAllByRole('button', { name: 'referral.mine.copy' })[0]
                .click();
        });

        expect(writeText).toHaveBeenCalledWith('ABCD2345');
        expect(
            screen.getByRole('button', { name: 'referral.mine.copied' }),
        ).toBeInTheDocument();
    });

    it('shows each direct referral by a plain state and each earning by its level', () => {
        render(
            <Referrals
                code="ABCD2345"
                link="https://erp.test/register?ref=ABCD2345"
                summary={summary}
                direct={paginator([referred])}
                earnings={paginator([
                    earning({}),
                    earning({
                        id: '01JOIN',
                        level: 0,
                        is_joining_reward: true,
                        amount: money('50.00'),
                    }),
                ])}
            />,
        );

        expect(screen.getByText('Sohel Supplies')).toBeInTheDocument();
        expect(
            screen.getByText('referral.referred_states.joining'),
        ).toBeInTheDocument();
        expect(screen.getByText('referral.mine.level')).toBeInTheDocument();
        expect(screen.getByText('referral.mine.joining')).toBeInTheDocument();
        expect(screen.getAllByText(/৳325\.00/).length).toBeGreaterThan(0);
    });

    it('says the code waits for activation, and shows empty states', () => {
        render(
            <Referrals
                code={null}
                link={null}
                summary={{
                    ...summary,
                    direct: 0,
                    direct_active: 0,
                    paid: money('0.00'),
                }}
                direct={paginator([])}
                earnings={paginator([])}
            />,
        );

        expect(screen.getByText('referral.mine.not_yet')).toBeInTheDocument();
        expect(screen.queryByTestId('referral-code')).not.toBeInTheDocument();
        expect(
            screen.getByText('referral.mine.no_referred'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('referral.mine.no_earnings'),
        ).toBeInTheDocument();
    });
});

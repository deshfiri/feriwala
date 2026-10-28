// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
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
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: LinkedRef } = await import('./linked-ref');
const { default: ExchangeOriginNotice } = await import('./exchange-origin');

describe('a reference to another record', () => {
    it('is a link where the reader may open it', () => {
        render(
            <LinkedRef
                item={{
                    reference: 'ORD-1',
                    url: '/admin/orders/01ORDER',
                    status_label: 'Confirmed',
                }}
            />,
        );

        expect(screen.getByRole('link', { name: 'ORD-1' })).toHaveAttribute(
            'href',
            '/admin/orders/01ORDER',
        );
        expect(screen.getByText('(Confirmed)')).toBeVisible();
    });

    it('is plain text, never a dead link, where they may not', () => {
        render(<LinkedRef item={{ reference: 'ORD-1', url: null }} />);

        expect(screen.getByText('ORD-1')).toBeVisible();
        expect(screen.queryByRole('link')).toBeNull();
    });
});

describe('where an Exchange Order came from', () => {
    it('names the exchange that sent it and the order it replaces goods of, with links', () => {
        render(
            <ExchangeOriginNotice
                origin={{
                    case: { reference: 'EX-ORD-1', url: '/cases/01CASE' },
                    original_order: {
                        reference: 'ORD-1',
                        url: '/store-orders/01ORDER',
                    },
                }}
            />,
        );

        const note = screen.getByRole('note');

        expect(note).toHaveTextContent('cases.facts.sent_by_case');
        expect(note).toHaveTextContent('cases.facts.exchange_of');
        expect(screen.getByRole('link', { name: 'EX-ORD-1' })).toHaveAttribute(
            'href',
            '/cases/01CASE',
        );
        expect(screen.getByRole('link', { name: 'ORD-1' })).toHaveAttribute(
            'href',
            '/store-orders/01ORDER',
        );
    });

    it('keeps an unopenable end as text', () => {
        render(
            <ExchangeOriginNotice
                origin={{
                    case: { reference: 'EX-ORD-1', url: null },
                    original_order: { reference: 'ORD-1', url: null },
                }}
            />,
        );

        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.getByText('EX-ORD-1')).toBeVisible();
    });

    it('shows nothing for an ordinary order', () => {
        const { container } = render(<ExchangeOriginNotice origin={null} />);

        expect(container).toBeEmptyDOMElement();
    });
});

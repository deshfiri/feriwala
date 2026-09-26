// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = {
    props: {
        translations: {
            public: {
                packages: {
                    empty: 'Packages are not available to preview right now.',
                    days: ':days days',
                },
            },
        },
        locale: { current: 'en', direction: 'ltr', available: ['en', 'bn'] },
    },
};

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string;
            children?: unknown;
        }) => createElement('a', { href, ...rest }, children as never),
        usePage: () => page,
    };
});

const { default: PackagePreview } = await import('./package-preview');

const content = { heading: 'Packages for every stage' };

/**
 * Real, current package data is attached by the controller and passed
 * straight through — this proves the "no packages yet" honest-empty-state
 * contract, and that a real package renders its server-formatted money
 * unmodified (never re-formatted or parsed in the browser, §36.1).
 */
describe('the package preview section', () => {
    it('shows the honest empty state when no packages are attached', () => {
        render(<PackagePreview content={content} packages={[]} />);

        expect(
            screen.getByText(
                'Packages are not available to preview right now.',
            ),
        ).toBeInTheDocument();
    });

    it('renders a real package using the server-formatted money string as-is', () => {
        render(
            <PackagePreview
                content={content}
                packages={[
                    {
                        key: '01ABC',
                        name: 'Growth',
                        short_description: 'For growing businesses',
                        fee: {
                            currency: 'BDT',
                            amount: '2500.00',
                            formatted: '৳2,500.00',
                        },
                        validity_days: 30,
                    },
                ]}
            />,
        );

        expect(screen.getByText('Growth')).toBeInTheDocument();
        expect(screen.getByText('৳2,500.00')).toBeInTheDocument();
        expect(screen.getByText(/30 days/)).toBeInTheDocument();
    });
});

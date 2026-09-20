// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = { props: { translations: {}, account: null as unknown } };

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
        usePage: () => page,
    };
});

const { default: SettingsLayout } = await import('./layout');

/**
 * What the settings sidebar offers (§33.4).
 *
 * A screen reachable only by typing its address is not reachable, so the
 * documents an account holds — what it was asked to pay, and the proof it
 * paid — are both linked here whenever there is an account to hold them.
 */
describe('the settings sidebar', () => {
    it('offers the billing documents to an account, both of them', () => {
        page.props.account = { allowsStaff: false, managesStaff: false };

        render(<SettingsLayout>content</SettingsLayout>);

        const nav = screen.getByRole('navigation');

        for (const item of [
            'common.settings.nav.profile',
            'common.settings.nav.security',
            'common.settings.nav.package',
            'common.settings.nav.invoices',
            'common.settings.nav.receipts',
            'common.settings.nav.appearance',
        ]) {
            expect(
                screen.getByRole('link', { name: item }),
            ).toBeInTheDocument();
        }

        expect(
            screen.queryByRole('link', { name: 'common.settings.nav.staff' }),
        ).not.toBeInTheDocument();

        expect(nav).toBeInTheDocument();
    });

    it('offers no account documents to somebody without an account', () => {
        page.props.account = null;

        render(<SettingsLayout>content</SettingsLayout>);

        for (const item of [
            'common.settings.nav.package',
            'common.settings.nav.invoices',
            'common.settings.nav.receipts',
        ]) {
            expect(
                screen.queryByRole('link', { name: item }),
            ).not.toBeInTheDocument();
        }

        expect(
            screen.getByRole('link', { name: 'common.settings.nav.profile' }),
        ).toBeInTheDocument();
    });

    it('offers staff to a manager whose package includes it', () => {
        page.props.account = { allowsStaff: true, managesStaff: true };

        render(<SettingsLayout>content</SettingsLayout>);

        expect(
            screen.getByRole('link', { name: 'common.settings.nav.staff' }),
        ).toBeInTheDocument();
    });
});

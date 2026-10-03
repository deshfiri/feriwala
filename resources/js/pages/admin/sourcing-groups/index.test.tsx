// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const translations = {
    sourcing: {
        title: 'Sourcing groups',
        description: 'Groups of products.',
        create: 'New group',
        search_placeholder: 'Search',
        all_statuses: 'All statuses',
        empty_title: 'No sourcing groups yet',
        empty_description: 'Create one.',
        open: 'Open',
        status: { active: 'Active', inactive: 'Inactive' },
        columns: {
            group: 'Group',
            products: 'Products',
            mappings: 'Variant mappings',
            status: 'Status',
        },
        form: {
            create_title: 'New sourcing group',
            code: 'Code',
            code_help: 'help',
            name_en: 'Name (English)',
            name_bn: 'Name (Bangla)',
            description: 'Description',
            save: 'Save',
        },
    },
};

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Link: ({ children, href }: { children: unknown; href: string }) =>
            createElement('a', { href }, children as never),
        Form: ({
            children,
        }: {
            children: (state: Record<string, unknown>) => unknown;
        }) =>
            createElement(
                'form',
                null,
                children({ processing: false, errors: {} }) as never,
            ),
        router: { get: vi.fn() },
        usePage: () => ({
            props: { translations, locale: { current: 'en' }, url: '/' },
            url: '/admin/sourcing-groups',
        }),
    };
});

vi.mock('@/actions/App/Http/Controllers/Admin/SourcingGroupController', () => ({
    default: {
        show: { url: ({ group }: { group: string }) => `/groups/${group}` },
        store: { form: () => ({ action: '/groups', method: 'post' }) },
    },
}));

vi.mock('@/hooks/use-table-query', () => ({
    useTableQuery: () => ({
        getFilter: () => undefined,
        setFilter: vi.fn(),
    }),
}));

const { default: SourcingGroupsIndex } = await import('./index');

const paginator = (data: unknown[]) => ({
    data,
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: data.length,
    from: data.length ? 1 : null,
    to: data.length || null,
    links: [],
    path: '/',
    first_page_url: '/',
    last_page_url: '/',
    next_page_url: null,
    prev_page_url: null,
});

describe('the sourcing groups list', () => {
    it('shows each group with its counts and a link to open it', () => {
        render(
            <SourcingGroupsIndex
                groups={
                    paginator([
                        {
                            id: 'g1',
                            code: 'regular-pants',
                            name_en: 'Regular pants',
                            name_bn: 'রেগুলার প্যান্ট',
                            is_active: true,
                            products_count: 3,
                            mappings_count: 5,
                        },
                    ]) as never
                }
                can={{ create: true }}
            />,
        );

        expect(screen.getByText('Regular pants')).toBeInTheDocument();
        expect(screen.getByText('regular-pants')).toBeInTheDocument();
        expect(screen.getByText('Active')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open' })).toHaveAttribute(
            'href',
            '/groups/g1',
        );
    });

    it('offers creating a group only to someone allowed to', () => {
        const { rerender } = render(
            <SourcingGroupsIndex
                groups={paginator([]) as never}
                can={{ create: true }}
            />,
        );

        expect(
            screen.getByRole('button', { name: /New group/ }),
        ).toBeInTheDocument();

        rerender(
            <SourcingGroupsIndex
                groups={paginator([]) as never}
                can={{ create: false }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /New group/ }),
        ).not.toBeInTheDocument();
    });
});

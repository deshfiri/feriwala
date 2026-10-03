// @vitest-environment jsdom

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

const translations = {
    sourcing: {
        form: {
            create_title: 'New sourcing group',
            code: 'Code',
            code_help: 'help',
            name_en: 'Name (English)',
            name_bn: 'Name (Bangla)',
            save: 'Save',
        },
        picker: {
            heading: 'Sourcing group',
            help: 'Decides which orders.',
            search: 'Search groups',
            none: 'No group matches.',
            selected: 'Selected',
            create: 'Create new group',
            create_help: 'Selected at once.',
            empty_group: 'no products yet',
            canonical: 'Fulfils orders for: :product',
            locked: 'This product already belongs to :name.',
        },
    },
};

const submit = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const { useState: useReactState } = await import('react');

    return {
        usePage: () => ({
            props: { translations, locale: { current: 'en' } },
        }),
        useHttp: (_m: string, _u: string, initial: Record<string, string>) => {
            const [data, setDataState] = useReactState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key: string, value: string) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                reset: vi.fn(),
                submit,
            };
        },
    };
});

vi.mock('@/actions/App/Http/Controllers/Admin/SourcingGroupController', () => ({
    default: { quickStore: { url: () => '/admin/sourcing-groups/quick' } },
}));

const { default: SourcingGroupPicker } =
    await import('./sourcing-group-picker');

const groups = [
    {
        id: 'g1',
        code: 'regular-pants',
        name_en: 'Regular pants',
        name_bn: 'রেগুলার প্যান্ট',
        canonical_product: { name: "Men's Regular Pants", sku: 'RP-1' },
        canonical_variants: [],
    },
    {
        id: 'g2',
        code: 'polo-shirts',
        name_en: 'Polo shirts',
        name_bn: 'পোলো শার্ট',
        canonical_product: null,
        canonical_variants: [],
    },
];

function Harness({ canCreate = true }: { canCreate?: boolean }) {
    const [value, setValue] = useState('');

    return (
        <SourcingGroupPicker
            groups={groups}
            value={value}
            onChange={setValue}
            canCreate={canCreate}
        />
    );
}

describe('the sourcing group picker', () => {
    it('filters groups by what staff type and selects one', () => {
        render(<Harness />);

        fireEvent.change(screen.getByLabelText('Search groups'), {
            target: { value: 'polo' },
        });

        expect(screen.queryByText('Regular pants')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Polo shirts'));

        expect(screen.getByText('Selected')).toBeInTheDocument();
    });

    it('offers creating a group only when allowed', () => {
        const { rerender } = render(<Harness canCreate />);

        expect(
            screen.getByRole('button', { name: /Create new group/ }),
        ).toBeInTheDocument();

        rerender(<Harness canCreate={false} />);

        expect(
            screen.queryByRole('button', { name: /Create new group/ }),
        ).not.toBeInTheDocument();
    });

    it('selects a group the moment it is created, without leaving the page', async () => {
        submit.mockResolvedValue({
            id: 'g3',
            code: 'cotton-pants',
            name_en: 'Cotton pants',
            name_bn: 'কটন প্যান্ট',
            canonical_product: null,
            canonical_variants: [],
        });

        render(<Harness />);

        fireEvent.click(
            screen.getByRole('button', { name: /Create new group/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() =>
            expect(screen.getByText('Cotton pants')).toBeInTheDocument(),
        );
        expect(screen.getByText('Selected')).toBeInTheDocument();
    });

    it('locks the choice to the group a product already belongs to', () => {
        render(
            <SourcingGroupPicker
                groups={groups}
                value=""
                onChange={vi.fn()}
                canCreate
                lockedTo="g1"
            />,
        );

        expect(
            screen.getByText('This product already belongs to Regular pants.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Search groups'),
        ).not.toBeInTheDocument();
    });
});

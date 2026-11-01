// @vitest-environment jsdom

import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { puts } from '@/test/fake-use-form';
import type { WebsiteSelection } from '@/types/website';

vi.mock('@inertiajs/react', async () => {
    const { fakeUseForm } = await import('@/test/fake-use-form');

    return {
        useForm: fakeUseForm,
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: WebsiteSelectionDialog } =
    await import('./website-selection-dialog');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

function selection(
    overrides: Partial<WebsiteSelection> = {},
): WebsiteSelection {
    return {
        id: '01SELECTION',
        product: {
            id: '01PRODUCT',
            name: 'Cotton panjabi',
            sku: 'FW-1043',
            image: 'https://cdn.example.com/panjabi.jpg',
        },
        status: 'published',
        status_label: 'Published',
        sync_status: 'synced',
        sync_label: 'Synchronised',
        last_synced_at: null,
        sync_error: null,
        price: money('2500.00'),
        promotional_price: null,
        promotion_starts_at: null,
        promotion_ends_at: null,
        promotion_applies: false,
        promo_title: 'Eid offer',
        marketing_description: 'Soft cotton',
        is_featured: false,
        display_order: 3,
        category: null,
        terms: {
            allows_user_pricing: true,
            minimum: money('2000.00'),
            maximum: money('4000.00'),
            suggested: money('2500.00'),
            max_margin_percent: null,
            margin_ceiling: null,
            locked_fields: [],
        },
        published_at: null,
        ...overrides,
    };
}

function open(row = selection(), onClose = vi.fn()) {
    const view = render(
        <WebsiteSelectionDialog
            key={row.id}
            websiteId="01WEBSITE"
            row={row}
            categories={[]}
            onClose={onClose}
        />,
    );

    return { ...view, onClose };
}

const dialog = () =>
    document.querySelector(
        '[data-test="website-selection-dialog"]',
    ) as HTMLElement;
const price = () => within(dialog()).getByLabelText('website.products.price');
const save = () =>
    // The spinner adds its own label to the name while submitting.
    within(dialog()).getByRole('button', { name: /website\.products\.save/ });

beforeEach(() => {
    puts.length = 0;
});

describe('the product editing dialog', () => {
    it('says which product is being edited, with its image, name and BPC', () => {
        open();

        expect(
            screen.getByRole('dialog', { name: 'Cotton panjabi' }),
        ).toBeInTheDocument();
        expect(
            dialog().querySelector('[data-test="selection-dialog-sku"]'),
        ).toHaveTextContent('partner_products.details.bpc FW-1043');
        expect(
            within(dialog()).getByText('website.products.edit_description'),
        ).toBeInTheDocument();
        expect(dialog().querySelector('img')).toHaveAttribute(
            'src',
            'https://cdn.example.com/panjabi.jpg',
        );
    });

    it('opens with the product’s saved values in labelled fields', () => {
        open();

        expect(price()).toHaveValue('2500.00');
        expect(
            within(dialog()).getByLabelText('website.products.promo_title'),
        ).toHaveValue('Eid offer');
        expect(
            within(dialog()).getByLabelText('website.products.display_order'),
        ).toHaveValue(3);
        expect(
            within(dialog()).getByLabelText(
                'website.products.marketing_description',
            ),
        ).toHaveValue('Soft cotton');
    });

    it('shows another product’s own values, never those just typed for the last', () => {
        const first = open();

        fireEvent.change(price(), { target: { value: '3333.00' } });
        first.unmount();

        open(
            selection({
                id: '01OTHER',
                product: {
                    id: '01P2',
                    name: 'Silk saree',
                    sku: 'FW-2001',
                    image: null,
                },
                price: money('3600.00'),
                promo_title: null,
            }),
        );

        expect(
            screen.getByRole('dialog', { name: 'Silk saree' }),
        ).toBeInTheDocument();
        expect(price()).toHaveValue('3600.00');
        expect(
            within(dialog()).getByLabelText('website.products.promo_title'),
        ).toHaveValue('');
    });

    it('sends the typed fields to the existing update route, and stays open until the server answers', () => {
        const { onClose } = open();

        fireEvent.change(price(), { target: { value: '3100.00' } });
        fireEvent.click(save());

        expect(puts).toHaveLength(1);
        expect(puts[0].url).toContain('01WEBSITE');
        expect(puts[0].url).toContain('01SELECTION');
        expect(puts[0].payload).toMatchObject({
            price: '3100.00',
            promo_title: 'Eid offer',
            display_order: '3',
            promotion_starts_at: null,
            promotion_ends_at: null,
        });
        expect(onClose).not.toHaveBeenCalled();

        act(() => puts[0].succeed());

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('disables Save while submitting and ignores a second submission', () => {
        open();

        fireEvent.click(save());
        expect(save()).toBeDisabled();
        expect(save()).toHaveAttribute('aria-busy', 'true');

        fireEvent.submit(dialog().querySelector('form') as HTMLFormElement);
        fireEvent.click(save());

        expect(puts).toHaveLength(1);

        act(() => puts[0].succeed());
    });

    it('keeps the dialog open with the values typed when the server refuses, and shows the error inside it', () => {
        const { onClose } = open();

        fireEvent.change(price(), { target: { value: '1999.00' } });
        fireEvent.click(save());
        act(() => puts[0].fail({ price: 'Below the allowed minimum.' }));

        expect(onClose).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Below the allowed minimum.'),
        ).toBeInTheDocument();
        expect(price()).toHaveValue('1999.00');
        expect(save()).toBeEnabled();

        // And it can be corrected and sent again.
        fireEvent.change(price(), { target: { value: '2600.00' } });
        fireEvent.click(save());

        expect(puts).toHaveLength(2);
        expect(puts[1].payload).toMatchObject({ price: '2600.00' });
    });

    it('shows a refusal that is about the product, not a field, inside the dialog too', () => {
        open();

        fireEvent.click(save());
        act(() => puts[0].fail({ website: 'This website is closed.' }));

        expect(
            within(dialog()).getByText('This website is closed.'),
        ).toBeInTheDocument();
    });

    it('closes at once when nothing was changed', () => {
        const { onClose } = open();

        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'common.actions.cancel',
            }),
        );

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('asks before throwing away changes, from Cancel, Escape and the close button alike', () => {
        const { onClose } = open();
        const close = () =>
            within(dialog()).getByRole('button', {
                name: 'common.actions.cancel',
            });

        fireEvent.change(price(), { target: { value: '2800.00' } });

        fireEvent.click(close());
        expect(
            screen.getByText('website.products.discard_title'),
        ).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'website.products.keep_editing',
            }),
        );
        expect(
            screen.queryByText('website.products.discard_title'),
        ).not.toBeInTheDocument();
        expect(price()).toHaveValue('2800.00');

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Close' }),
        );
        expect(
            screen.getByText('website.products.discard_title'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'website.products.keep_editing',
            }),
        );

        fireEvent.keyDown(document.activeElement ?? document.body, {
            key: 'Escape',
        });
        expect(
            screen.getByText('website.products.discard_title'),
        ).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', { name: 'website.products.discard' }),
        );
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('cannot be closed while a save is in flight', () => {
        const { onClose } = open();

        fireEvent.click(save());

        fireEvent.keyDown(document.activeElement ?? document.body, {
            key: 'Escape',
        });
        expect(
            within(dialog()).getByRole('button', {
                name: 'common.actions.cancel',
            }),
        ).toBeDisabled();
        expect(onClose).not.toHaveBeenCalled();
        expect(dialog()).toBeInTheDocument();

        act(() => puts[0].succeed());
    });

    it('does not send what the administrator has locked, and disables it', () => {
        open(
            selection({
                terms: {
                    ...selection().terms,
                    allows_user_pricing: false,
                    locked_fields: ['promo_title', 'is_featured'],
                },
            }),
        );

        expect(price()).toBeDisabled();
        expect(
            within(dialog()).getByLabelText('website.products.promo_title'),
        ).toBeDisabled();

        fireEvent.click(save());

        expect(puts[0].payload).not.toHaveProperty('price');
        expect(puts[0].payload).not.toHaveProperty('promo_title');
        expect(puts[0].payload).not.toHaveProperty('is_featured');
        expect(puts[0].payload).toHaveProperty('display_order', '3');
    });

    it('is laid out to fit a phone: bounded height, scrolling body, no fixed widths', () => {
        open();

        // jsdom does no layout, so what can be asserted is the contract in the
        // classes: the dialog never exceeds the viewport, only its body
        // scrolls, fields stack to one column and the actions go full width.
        expect(dialog().className).toContain('max-h-[90dvh]');
        expect(dialog().className).toContain('max-w-[calc(100%-1rem)]');
        expect(dialog().className).toContain('overflow-hidden');
        expect(dialog().className).toContain('sm:max-w-2xl');

        const body = dialog().querySelector('form > div') as HTMLElement;
        expect(body.className).toContain('overflow-y-auto');
        expect(body.querySelector('.grid')?.className).toContain(
            'sm:grid-cols-2',
        );
        expect(body.querySelector('.grid')?.className).not.toMatch(
            /(^|\s)grid-cols-[2-9]/,
        );
        expect(dialog().className).not.toMatch(/(^|\s)w-\[\d+px\]/);

        const footer = dialog().querySelector(
            '[data-slot="dialog-footer"]',
        ) as HTMLElement;
        expect(footer.className).toContain('[&>button]:w-full');
    });
});

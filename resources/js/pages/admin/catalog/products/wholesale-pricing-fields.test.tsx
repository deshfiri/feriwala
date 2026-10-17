// @vitest-environment jsdom

import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import WholesalePricingFields from './wholesale-pricing-fields';

const submit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({ submit }),
}));

vi.mock('@/actions/App/Http/Controllers/Admin/ProductController', () => ({
    default: {
        pricePreview: () => ({ url: '/price-preview', method: 'post' }),
    },
}));

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string, values?: Record<string, unknown>) =>
            values ? `${key} ${JSON.stringify(values)}` : key,
        locale: 'en',
    }),
}));

(
    globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
).IS_REACT_ACT_ENVIRONMENT = true;

const labels = {
    baseCost: 'Base cost',
    wholesale: 'Wholesale price',
};

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

function renderFields(
    initial: Partial<
        Parameters<typeof WholesalePricingFields>[0]['initial']
    > = {},
) {
    return render(
        <WholesalePricingFields
            scope="product"
            initial={{
                method: 'fixed',
                baseCost: '100.00',
                markup: '',
                wholesale: '135.00',
                saved: null,
                ...initial,
            }}
            errors={{}}
            labels={labels}
        />,
    );
}

const chooseMethod = (value: 'fixed' | 'percentage') =>
    fireEvent.change(
        document.querySelector(
            'select[name="wholesale_pricing_method"]',
        ) as HTMLSelectElement,
        { target: { value } },
    );

const settle = () => act(() => vi.advanceTimersByTimeAsync(400));

describe('WholesalePricingFields', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        submit.mockReset();
        submit.mockResolvedValue({
            base_cost: money('100.00'),
            wholesale_price: money('120.00'),
            markup_percentage: '20',
        });
    });

    afterEach(() => {
        cleanup();
        vi.useRealTimers();
    });

    it('is Fixed by default: an editable wholesale price and no markup field', () => {
        renderFields();

        const price = document.querySelector(
            'input[name="wholesale_price"]',
        ) as HTMLInputElement;

        expect(price.value).toBe('135.00');
        expect(price.readOnly).toBe(false);
        expect(
            document.querySelector('input[name="wholesale_markup_percentage"]'),
        ).toBeNull();
        expect(submit).not.toHaveBeenCalled();
    });

    it('shows the markup field and a read-only calculated price for Percentage, with the server’s figure', async () => {
        renderFields();

        chooseMethod('percentage');

        const markup = document.querySelector(
            'input[name="wholesale_markup_percentage"]',
        ) as HTMLInputElement;

        fireEvent.change(markup, { target: { value: '20' } });
        await settle();

        // Typing alone calculates nothing; leaving the field does.
        expect(submit).not.toHaveBeenCalled();

        fireEvent.blur(markup);
        await settle();

        // The price is no longer something to type, and nothing posts it.
        expect(
            document.querySelector('input[name="wholesale_price"]'),
        ).toBeNull();
        expect(screen.getByText('৳120.00')).toBeInTheDocument();
        expect(
            screen.getByText(
                'catalog.pricing.preview {"base":"৳100.00","percent":"20","result":"৳120.00"}',
            ),
        ).toBeInTheDocument();
        expect(submit).toHaveBeenCalledTimes(1);
    });

    it('asks for the base cost before using percentage pricing', () => {
        renderFields({ baseCost: '' });

        chooseMethod('percentage');

        expect(
            screen.getByText('catalog.pricing.base_cost_first'),
        ).toBeInTheDocument();
        expect(submit).not.toHaveBeenCalled();
    });

    it('keeps the last calculated price as the editable one when switched back to Fixed', async () => {
        renderFields();

        chooseMethod('percentage');

        const markup = document.querySelector(
            'input[name="wholesale_markup_percentage"]',
        ) as HTMLInputElement;

        fireEvent.change(markup, { target: { value: '20' } });
        fireEvent.blur(markup);
        await settle();

        chooseMethod('fixed');

        const price = document.querySelector(
            'input[name="wholesale_price"]',
        ) as HTMLInputElement;

        expect(price.value).toBe('120.00');
        expect(price.readOnly).toBe(false);
        expect(
            document.querySelector('input[name="wholesale_markup_percentage"]'),
        ).toBeNull();
    });

    it('recalculates when the base cost changes', async () => {
        renderFields({
            method: 'percentage',
            markup: '20',
            saved: money('120.00'),
        });

        expect(screen.getByText('৳120.00')).toBeInTheDocument();

        submit.mockResolvedValue({
            base_cost: money('500.00'),
            wholesale_price: money('600.00'),
            markup_percentage: '20',
        });
        fireEvent.change(document.querySelector('input[name="base_cost"]')!, {
            target: { value: '500.00' },
        });
        await settle();

        expect(screen.getByText('৳600.00')).toBeInTheDocument();
    });
});

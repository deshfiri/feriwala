// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import Faq from './faq';

const content = {
    heading: 'Frequently asked questions',
    items: [
        {
            question: 'Is Feriwala a public shop?',
            answer: 'No, it is an ERP panel.',
        },
        {
            question: 'How do I become a Supplier?',
            answer: 'Apply separately.',
        },
    ],
};

/**
 * The one interactive section renderer — proving the expand/collapse
 * contract holds, since every other renderer here is a pure prop-to-markup
 * mapping with nothing to click.
 */
describe('the FAQ section', () => {
    it('renders every question and starts with every answer collapsed', () => {
        render(<Faq content={content} />);

        expect(
            screen.getByText('Is Feriwala a public shop?'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('How do I become a Supplier?'),
        ).toBeInTheDocument();

        // Radix Collapsible keeps collapsed content in the DOM but hidden,
        // so this asserts on the trigger's own open state, not on absence.
        expect(
            screen.getByRole('button', { name: /Is Feriwala a public shop/ }),
        ).toHaveAttribute('data-state', 'closed');
    });

    it('expands one answer on click without affecting the others', async () => {
        const user = userEvent.setup();
        render(<Faq content={content} />);

        await user.click(
            screen.getByRole('button', { name: /Is Feriwala a public shop/ }),
        );

        expect(
            screen.getByRole('button', { name: /Is Feriwala a public shop/ }),
        ).toHaveAttribute('data-state', 'open');
        expect(
            screen.getByRole('button', { name: /How do I become a Supplier/ }),
        ).toHaveAttribute('data-state', 'closed');
    });
});

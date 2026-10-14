// @vitest-environment jsdom

import { beforeEach, describe, expect, it } from 'vitest';
import { guessSpec, learnFrom, patternOf, specFor } from './page-shape';

describe('the shape a screen is drawn in while it loads', () => {
    beforeEach(() => localStorage.clear());

    it('treats every record of a collection as one pattern', () => {
        expect(patternOf('/admin/orders/01J8Z3K4M5N6P7Q8R9S0T1V2W3/edit')).toBe(
            '/admin/orders/:id/edit',
        );
        expect(patternOf('/admin/products/42')).toBe('/admin/products/:id');
        expect(patternOf('/admin/products')).toBe('/admin/products');
    });

    it('guesses a shape from the URL for a screen nobody has opened', () => {
        expect(guessSpec('/admin/dashboard').shape).toBe('dashboard');
        expect(guessSpec('/admin/products').shape).toBe('list');
        expect(guessSpec('/admin/products/42/edit').shape).toBe('form');
        expect(guessSpec('/admin/orders/42').shape).toBe('detail');
        expect(guessSpec('/admin/product-deletion-settings').shape).toBe(
            'form',
        );
    });

    it('learns the real columns and rows of a table, and uses them next time', () => {
        document.body.innerHTML = `
            <main id="main-content">
                <div class="max-w-[96rem]">
                    <div><div><h1>Products</h1></div><div><button>New</button></div></div>
                    <table>
                        <thead><tr><th>A</th><th>B</th><th>C</th><th>D</th></tr></thead>
                        <tbody>${'<tr><td>x</td></tr>'.repeat(7)}</tbody>
                    </table>
                </div>
            </main>`;

        learnFrom(document.getElementById('main-content'), '/admin/products');

        expect(specFor('/admin/products')).toMatchObject({
            shape: 'list',
            columns: 4,
            rows: 7,
        });
        // A screen of another pattern is still a guess.
        expect(specFor('/admin/orders').columns).toBe(5);
    });

    it('learns a figure row as a dashboard, and a narrow form as a form', () => {
        document.body.innerHTML = `
            <main id="main-content">
                <dl class="grid"><div data-slot="card"></div><div data-slot="card"></div><div data-slot="card"></div><div data-slot="card"></div></dl>
            </main>`;
        learnFrom(document.getElementById('main-content'), '/admin/dashboard');
        expect(specFor('/admin/dashboard')).toMatchObject({
            shape: 'dashboard',
            stats: 4,
        });

        document.body.innerHTML = `
            <main id="main-content"><div class="max-w-4xl"><form></form><div data-slot="card"></div></div></main>`;
        learnFrom(document.getElementById('main-content'), '/admin/branding');
        expect(specFor('/admin/branding')).toMatchObject({
            shape: 'form',
            narrow: true,
        });
    });
});

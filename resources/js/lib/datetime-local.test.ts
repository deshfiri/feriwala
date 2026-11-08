import { describe, expect, it } from 'vitest';
import { toInstant, toLocalInput } from './datetime-local';

describe('datetime-local helpers', () => {
    it('shows an instant as a datetime-local value, and nothing as empty', () => {
        expect(toLocalInput(null)).toBe('');
        expect(toLocalInput(undefined)).toBe('');
        expect(toLocalInput('2026-11-08T10:30:00+06:00')).toMatch(
            /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/,
        );
    });

    it('turns what was typed back into the same instant, whatever the time zone', () => {
        const instant = '2026-11-08T10:30:00.000Z';

        expect(toInstant(toLocalInput(instant))).toBe(instant);
    });

    it('sends nothing for a field left empty or not a string', () => {
        expect(toInstant('')).toBeNull();
        expect(toInstant(undefined)).toBeNull();
        expect(toInstant(42)).toBeNull();
    });
});

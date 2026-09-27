// @vitest-environment jsdom

import { afterEach, describe, expect, it } from 'vitest';
import { syncDocumentLocale } from './sync-document-locale';

afterEach(() => {
    document.documentElement.lang = '';
    document.documentElement.dir = '';
});

describe('syncDocumentLocale', () => {
    it('sets <html lang> and dir from the shared locale prop', () => {
        syncDocumentLocale({
            locale: { current: 'bn', direction: 'ltr', available: [] },
        });

        expect(document.documentElement.lang).toBe('bn');
        expect(document.documentElement.dir).toBe('ltr');
    });

    it('switches back when the visit returns to English', () => {
        syncDocumentLocale({
            locale: { current: 'bn', direction: 'ltr', available: [] },
        });
        syncDocumentLocale({
            locale: { current: 'en', direction: 'ltr', available: [] },
        });

        expect(document.documentElement.lang).toBe('en');
    });

    it('leaves the document alone when a page shares no locale prop', () => {
        document.documentElement.lang = 'en';

        syncDocumentLocale({});

        expect(document.documentElement.lang).toBe('en');
    });
});

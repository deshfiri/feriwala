// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { EvidenceFile } from '@/types/cases';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { translations: {}, locale: { current: 'en' } } }),
}));

const { default: EvidenceGallery } = await import('./evidence-gallery');

const file = (overrides: Partial<EvidenceFile> = {}): EvidenceFile => ({
    id: '01EVID',
    name: 'seam.png',
    purpose: 'request',
    purpose_label: 'With the request',
    uploaded_by: 'partner',
    note: 'The seam.',
    mime_type: 'image/png',
    is_image: true,
    size_bytes: 120,
    draft: false,
    uploaded_at: '2026-10-20T09:00:00+06:00',
    preview_url: 'https://example.test/preview',
    download_url: 'https://example.test/download',
    ...overrides,
});

describe('evidence on a case', () => {
    it('opens and downloads a file that is there', () => {
        render(<EvidenceGallery files={[file()]} />);

        expect(screen.getByText('seam.png')).toBeVisible();
        expect(
            screen.getByRole('link', { name: /cases\.evidence\.download/ }),
        ).toHaveAttribute('href', 'https://example.test/download');
    });

    it('says a file was removed with its account, and offers nothing to open, no name and no note', () => {
        render(
            <EvidenceGallery
                files={[
                    file({
                        erased: true,
                        name: '[redacted]',
                        note: '[redacted]',
                        preview_url: null,
                        download_url: null,
                    }),
                ]}
            />,
        );

        expect(screen.getByText('cases.evidence.erased')).toBeVisible();
        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.queryByRole('img')).toBeNull();
        expect(screen.queryByText('[redacted]')).toBeNull();
    });
});

// @vitest-environment jsdom

import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EvidenceFile } from '@/types/cases';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { translations: {} } }),
}));

const { default: EvidenceDraftUploader } =
    await import('./evidence-draft-uploader');

const limits = {
    max_bytes: 2 * 1024 * 1024,
    max_per_case: 2,
    mime_types: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
};

const saved: EvidenceFile = {
    id: '01EVID',
    name: 'parcel.png',
    purpose: 'request',
    purpose_label: 'Request',
    uploaded_by: 'partner',
    note: null,
    mime_type: 'image/png',
    is_image: true,
    size_bytes: 120,
    draft: true,
    uploaded_at: '2026-10-27T10:00:00+06:00',
    preview_url: 'https://example.test/preview',
    download_url: 'https://example.test/download',
};

/** A stand-in for the browser's upload, driven by hand so every state can be seen. */
class FakeRequest {
    static last: FakeRequest;
    upload: { onprogress: ((event: unknown) => void) | null } = {
        onprogress: null,
    };
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    status = 0;
    responseText = '';
    open = vi.fn();
    setRequestHeader = vi.fn();
    send = vi.fn(() => {
        FakeRequest.last = this;
    });
}

const renderUploader = (initial: EvidenceFile[] = []) =>
    render(
        <EvidenceDraftUploader
            orderId="01ORDER"
            uploadUrl="/cases/evidence"
            deleteUrl={(id) => `/cases/evidence/${id}`}
            limits={limits}
            initial={initial}
        />,
    );

const file = (name: string, type: string, size = 100) =>
    new File([new Uint8Array(size)], name, { type });

describe('attaching evidence to a request', () => {
    beforeEach(() => {
        vi.stubGlobal('XMLHttpRequest', FakeRequest);
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response(null, { status: 200 })),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('refuses a file type that is not accepted before anything is sent', async () => {
        const user = userEvent.setup({ applyAccept: false });
        renderUploader();

        await user.upload(
            screen.getByLabelText('cases.evidence.choose'),
            file('script.txt', 'text/plain'),
        );

        expect(screen.getByText('cases.evidence.errors.type')).toBeVisible();
        expect(document.querySelector('input[name="evidence[]"]')).toBeNull();
    });

    it('refuses a file that is too large, and says the limit', async () => {
        const user = userEvent.setup();
        renderUploader();

        await user.upload(
            screen.getByLabelText('cases.evidence.choose'),
            file('big.png', 'image/png', limits.max_bytes + 1),
        );

        expect(screen.getByText('cases.evidence.errors.size')).toBeVisible();
    });

    it('shows the upload in progress, then carries only its id in the form', async () => {
        const user = userEvent.setup();
        renderUploader();

        await user.upload(
            screen.getByLabelText('cases.evidence.choose'),
            file('parcel.png', 'image/png'),
        );

        const bar = screen.getByRole('progressbar', {
            name: 'cases.evidence.uploading',
        });
        expect(bar).toHaveAttribute('aria-valuenow', '0');

        act(() =>
            FakeRequest.last.upload.onprogress?.({
                lengthComputable: true,
                loaded: 50,
                total: 100,
            }),
        );
        expect(screen.getByRole('progressbar')).toHaveAttribute(
            'aria-valuenow',
            '50',
        );

        act(() => {
            FakeRequest.last.status = 201;
            FakeRequest.last.responseText = JSON.stringify(saved);
            FakeRequest.last.onload?.();
        });

        expect(screen.queryByRole('progressbar')).toBeNull();
        expect(screen.getByText('parcel.png')).toBeVisible();
        // What is submitted with the request is which drafts — never a path.
        expect(document.querySelector('input[name="evidence[]"]')).toHaveValue(
            '01EVID',
        );
    });

    it("shows the server's own reason when it refuses a file", async () => {
        const user = userEvent.setup();
        renderUploader();

        await user.upload(
            screen.getByLabelText('cases.evidence.choose'),
            file('parcel.png', 'image/png'),
        );

        act(() => {
            FakeRequest.last.status = 422;
            FakeRequest.last.responseText = JSON.stringify({
                message: 'That does not look like an image.',
            });
            FakeRequest.last.onload?.();
        });

        expect(
            screen.getByText('That does not look like an image.'),
        ).toBeVisible();
        expect(document.querySelector('input[name="evidence[]"]')).toBeNull();
    });

    it('takes a draft back, and stops offering more once the limit is reached', async () => {
        const user = userEvent.setup();
        renderUploader([
            saved,
            { ...saved, id: '01EVID2', name: 'second.png' },
        ]);

        expect(screen.getByLabelText('cases.evidence.choose')).toBeDisabled();
        expect(
            document.querySelectorAll('input[name="evidence[]"]'),
        ).toHaveLength(2);

        await user.click(
            screen.getAllByRole('button', { name: 'cases.evidence.remove' })[0],
        );

        expect(fetch).toHaveBeenCalledWith(
            '/cases/evidence/01EVID',
            expect.objectContaining({ method: 'DELETE' }),
        );
        expect(
            document.querySelectorAll('input[name="evidence[]"]'),
        ).toHaveLength(1);
        expect(screen.getByLabelText('cases.evidence.choose')).toBeEnabled();
    });
});

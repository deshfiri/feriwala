// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const translations = {
    supplier: {
        kyc: {
            title: 'Application & KYC',
            description: 'Upload the documents we need.',
            round: 'Round :round',
            documents: 'Documents',
            no_documents: 'No documents uploaded yet.',
            document_type: 'Document type',
            file: 'File',
            upload: 'Upload document',
            submit: 'Submit for review',
            submit_help: 'Once submitted, your documents cannot be changed.',
            locked: 'This submission is being reviewed and is read-only.',
            feedback: 'Reviewer feedback',
            history: 'Earlier rounds',
            accepted: 'JPG, PNG or PDF, up to 5 MB.',
            types: { trade_licence: 'Trade licence', nid_front: 'NID (front)' },
        },
    },
};

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Form: ({
            children,
        }: {
            children: (state: Record<string, unknown>) => unknown;
        }) =>
            createElement(
                'form',
                null,
                children({
                    processing: false,
                    errors: {},
                    progress: null,
                }) as never,
            ),
        usePage: () => ({ props: { translations, locale: { current: 'en' } } }),
    };
});

const { default: SupplierKyc } = await import('./index');

type Document = {
    id: string;
    type: string;
    original_name: string;
    size_bytes: number;
    mime_type: string;
};

type Round = {
    id: string;
    round: number;
    status: string;
    status_label: string;
    is_editable: boolean;
    decision_note: string | null;
    submitted_at: string | null;
    documents: Document[];
};

const round = (overrides: Partial<Round> = {}): Round => ({
    id: 'round-1',
    round: 1,
    status: 'draft',
    status_label: 'Draft',
    is_editable: true,
    decision_note: null,
    submitted_at: null,
    documents: [],
    ...overrides,
});

const props = (r: Round) => ({
    supplier_status: 'kyc_pending',
    round: r,
    history: [],
    document_types: ['trade_licence', 'nid_front'],
});

/**
 * The Supplier's KYC screen (D25, P13-7). A submitted round is read-only until
 * a reviewer asks for a correction, and an empty round cannot be submitted.
 */
describe('the supplier KYC screen', () => {
    it('lets an applicant upload, but not submit an empty round', () => {
        render(<SupplierKyc {...props(round())} />);

        expect(
            screen.getByText('No documents uploaded yet.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Upload document' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Submit for review' }),
        ).toBeDisabled();
    });

    it('offers the document types by their translated names', () => {
        render(<SupplierKyc {...props(round())} />);

        expect(
            screen.getByRole('option', { name: 'Trade licence' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'NID (front)' }),
        ).toBeInTheDocument();
    });

    it('lets a round with a document be submitted', () => {
        render(
            <SupplierKyc
                {...props(
                    round({
                        documents: [
                            {
                                id: 'd1',
                                type: 'trade_licence',
                                original_name: 'licence.pdf',
                                size_bytes: 2048,
                                mime_type: 'application/pdf',
                            },
                        ],
                    }),
                )}
            />,
        );

        expect(screen.getByText(/licence\.pdf/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Submit for review' }),
        ).toBeEnabled();
    });

    it('is read-only once submitted, with no upload or submit control', () => {
        render(
            <SupplierKyc
                {...props(
                    round({
                        status: 'under_review',
                        status_label: 'Under review',
                        is_editable: false,
                    }),
                )}
            />,
        );

        expect(
            screen.getByText(
                'This submission is being reviewed and is read-only.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Upload document' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Submit for review' }),
        ).not.toBeInTheDocument();
    });

    it("shows the reviewer's feedback and reopens the form for a correction", () => {
        render(
            <SupplierKyc
                {...props(
                    round({
                        status: 'correction_required',
                        status_label: 'Correction required',
                        decision_note: 'The licence scan is blurry.',
                    }),
                )}
            />,
        );

        expect(
            screen.getByText('The licence scan is blurry.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Upload document' }),
        ).toBeInTheDocument();
    });
});

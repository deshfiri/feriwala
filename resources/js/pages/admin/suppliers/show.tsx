import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Eye, FileText, Upload } from 'lucide-react';
import { useState } from 'react';
import StaffKycUploadDialog from '@/components/kyc/staff-kyc-upload-dialog';
import * as kycUpload from '@/routes/admin/suppliers/kyc-upload';
import ContactVerificationCard, {
    type ContactState,
} from '@/components/contact-verification-card';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import ManagedSupplierController from '@/actions/App/Http/Controllers/Admin/ManagedSupplierController';
import { index } from '@/routes/admin/suppliers';
import type { KycBatchForReview, SupplierProductsState } from '@/types';
import AssignedProductsSection from './assigned-products-section';
import BatchReview from '@/components/kyc/batch-review';
import NativeSelect from '@/components/forms/native-select';
import { evidenceReview, evidenceValue } from '@/routes/admin/suppliers/kyc';
import { store as assignBatch } from '@/routes/admin/suppliers/kyc/batch';
import { show as documentShow } from '@/routes/admin/suppliers/kyc/documents';
import { store as correction } from '@/routes/admin/suppliers/kyc/correction';
import { store as approval } from '@/routes/admin/suppliers/approval';
import { store as reactivation } from '@/routes/admin/suppliers/reactivation';
import { store as rejection } from '@/routes/admin/suppliers/rejection';
import { store as suspension } from '@/routes/admin/suppliers/suspension';

type Props = {
    /**
     * A signed, expiring link that opens this Supplier's own portal as the
     * Supplier, or null when this administrator may not.
     */
    dashboard_url: string | null;
    supplier: {
        id: string;
        reference: string;
        business_name: string;
        contact_person_name: string;
        business_address: string;
        email: string;
        mobile: string;
        trade_licence_number: string | null;
        tax_identification_number: string | null;
        status: string;
        status_label: string;
        status_tone: StatusTone;
        can_decide: boolean;
        can_verify_contact: boolean;
        can_manage_setup: boolean;
        can_upload_kyc_document: boolean;
        can_suspend: boolean;
        can_reactivate: boolean;
    };
    round: {
        id: string;
        status_label: string;
        awaits_review: boolean;
        can_request_correction: boolean;
        documents: {
            id: string;
            type: string;
            label: string;
            original_name: string;
            size_bytes: number;
            mime_type: string;
        }[];
        fields: { label: string; value: string }[];
        missing_required: string[];
    } | null;
    /** A round on a batch version (D30), reviewed group by group; null for the flat list. */
    batch?: KycBatchForReview | null;
    /** The batch templates a round may be opened against; empty without permission. */
    kyc_batches?: { id: string; name: string; is_default: boolean }[];
    /** The round is empty, so it may still be moved to another template. */
    can_assign_batch?: boolean;
    /** Email/mobile standing, including what staff confirmed by hand. */
    contact: ContactState;
    /** Staff-only: Products this Supplier has; null without product_supplier.view. */
    assigned_products: SupplierProductsState | null;
    status_history: {
        previous_status: string | null;
        new_status: string;
        changed_by: string | null;
        changed_at: string;
        reason: string | null;
        public_note: string | null;
    }[];
};

const RAW_ROOT =
    'border-input focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm';

/**
 * One Supplier application, its KYC evidence, and the decisions staff may
 * take (D25). Each control is drawn only when the server says the viewer may
 * use it; the server refuses the request regardless.
 */
export default function AdminSupplierShow({
    dashboard_url: dashboardUrl,
    supplier,
    round,
    batch = null,
    kyc_batches = [],
    can_assign_batch = false,
    contact,
    assigned_products,
    status_history,
}: Props) {
    const { t, locale } = useTranslation();
    const [uploading, setUploading] = useState(false);

    const formatSize = (bytes: number) =>
        bytes < 1024
            ? `${bytes} B`
            : `${Math.round(bytes / 1024).toLocaleString(locale)} KB`;

    const decisionForm = (
        route: { form: (id: string) => object },
        label: string,
        fields: { name: string; label: string; required?: boolean }[],
        variant: 'default' | 'destructive' | 'outline' = 'default',
    ) => (
        <Form
            {...(route.form(supplier.id) as object)}
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({
                processing,
                errors,
            }: {
                processing: boolean;
                errors: Record<string, string>;
            }) => (
                <>
                    {fields.map((field) => (
                        <FormField
                            key={field.name}
                            label={field.label}
                            error={errors[field.name]}
                            required={field.required}
                        >
                            {(control) =>
                                field.name === 'reason' ? (
                                    <Input
                                        {...control}
                                        name={field.name}
                                        maxLength={1000}
                                        required={field.required}
                                    />
                                ) : (
                                    <TextArea
                                        {...control}
                                        name={field.name}
                                        rows={2}
                                        maxLength={1000}
                                        className={RAW_ROOT}
                                        required={field.required}
                                    />
                                )
                            }
                        </FormField>
                    ))}
                    <SubmitButton
                        processing={processing}
                        variant={variant}
                        className="w-full"
                    >
                        {label}
                    </SubmitButton>
                </>
            )}
        </Form>
    );

    return (
        <>
            <Head title={supplier.business_name} />

            <PageContainer>
                <PageHeader
                    title={supplier.business_name}
                    description={supplier.reference}
                    actions={
                        <>
                            <StatusPill
                                tone={supplier.status_tone}
                                label={supplier.status_label}
                            />
                            {dashboardUrl !== null && (
                                <Button size="sm" asChild>
                                    <a
                                        href={dashboardUrl}
                                        target="_blank"
                                        rel="noopener"
                                        title={t(
                                            'account.dashboard_access.open_hint',
                                        )}
                                    >
                                        <ExternalLink
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('account.dashboard_access.open')}
                                    </a>
                                </Button>
                            )}
                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('supplier.admin.suppliers.back')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <SectionCard
                            title={t('supplier.admin.suppliers.details')}
                        >
                            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                {[
                                    [
                                        t(
                                            'supplier.fields.contact_person_name',
                                        ),
                                        supplier.contact_person_name,
                                    ],
                                    [
                                        t('supplier.fields.email'),
                                        supplier.email,
                                    ],
                                    [
                                        t('supplier.fields.mobile'),
                                        supplier.mobile,
                                    ],
                                    [
                                        t('supplier.fields.business_address'),
                                        supplier.business_address,
                                    ],
                                    [
                                        t(
                                            'supplier.fields.trade_licence_number',
                                        ),
                                        supplier.trade_licence_number,
                                    ],
                                    [
                                        t(
                                            'supplier.fields.tax_identification_number',
                                        ),
                                        supplier.tax_identification_number,
                                    ],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-muted-foreground text-xs">
                                            {label}
                                        </dt>
                                        <dd className="font-medium break-words">
                                            {value ?? '—'}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </SectionCard>

                        <ContactVerificationCard
                            contact={contact}
                            canVerify={supplier.can_verify_contact}
                            canManageSetup={supplier.can_manage_setup}
                            verifyForm={ManagedSupplierController.verifyContact.form(
                                supplier.id,
                            )}
                            sendLinkForm={ManagedSupplierController.sendSetupLink.form(
                                supplier.id,
                            )}
                            revokeLinkForm={ManagedSupplierController.revokeSetupLink.form(
                                supplier.id,
                            )}
                        />

                        {batch && (
                            <BatchReview
                                batch={batch}
                                verdictUrl={evidenceReview.url(supplier.id)}
                                documentUrl={(id) =>
                                    documentShow.url([supplier.id, id])
                                }
                                revealUrl={(requirement, slot) =>
                                    evidenceValue.url(supplier.id, {
                                        query: { requirement, slot },
                                    })
                                }
                            />
                        )}

                        {!batch && (
                            <SectionCard
                                title={t('supplier.admin.suppliers.evidence')}
                            >
                                {round !== null && round.fields.length > 0 && (
                                    <dl className="border-border mb-4 grid gap-3 border-b pb-4 text-sm sm:grid-cols-2">
                                        {round.fields.map((field) => (
                                            <div key={field.label}>
                                                <dt className="text-muted-foreground text-xs">
                                                    {field.label}
                                                </dt>
                                                <dd className="font-medium break-words">
                                                    {field.value}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}

                                {round !== null &&
                                    round.missing_required.length > 0 && (
                                        <p
                                            role="alert"
                                            className="text-danger mb-4 text-sm font-medium"
                                        >
                                            {t(
                                                'supplier.admin.suppliers.missing_required',
                                                {
                                                    items: round.missing_required.join(
                                                        ', ',
                                                    ),
                                                },
                                            )}
                                        </p>
                                    )}

                                {round === null ||
                                (round.documents.length === 0 &&
                                    round.fields.length === 0) ? (
                                    <p className="text-muted-foreground text-sm">
                                        {t(
                                            'supplier.admin.suppliers.no_evidence',
                                        )}
                                    </p>
                                ) : (
                                    <ul className="divide-border divide-y">
                                        {round.documents.map((document) => (
                                            <li
                                                key={document.id}
                                                className="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                                            >
                                                <FileText
                                                    className="text-muted-foreground size-4 shrink-0"
                                                    aria-hidden="true"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">
                                                        {document.label}
                                                    </p>
                                                    <p className="text-muted-foreground truncate text-xs">
                                                        {document.original_name}{' '}
                                                        ·{' '}
                                                        {formatSize(
                                                            document.size_bytes,
                                                        )}
                                                    </p>
                                                </div>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={documentShow.url([
                                                            supplier.id,
                                                            document.id,
                                                        ])}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        <Eye
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                        {t(
                                                            'supplier.admin.suppliers.view_document',
                                                        )}
                                                    </a>
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </SectionCard>
                        )}

                        {assigned_products && (
                            <AssignedProductsSection
                                assigned={assigned_products}
                            />
                        )}

                        <SectionCard
                            title={t('supplier.admin.suppliers.history')}
                        >
                            <ol className="divide-border divide-y text-sm">
                                {status_history.map((change, position) => (
                                    <li
                                        key={position}
                                        className="space-y-0.5 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <span className="font-medium">
                                                {change.new_status}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {change.changed_by ?? '—'} ·{' '}
                                                {new Date(
                                                    change.changed_at,
                                                ).toLocaleString(locale)}
                                            </span>
                                        </div>
                                        {change.reason && (
                                            <p>{change.reason}</p>
                                        )}
                                        {change.public_note && (
                                            <p className="text-muted-foreground">
                                                {change.public_note}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </SectionCard>
                    </div>

                    <aside className="space-y-6 lg:col-span-1">
                        {can_assign_batch && kyc_batches.length > 0 && (
                            <SectionCard
                                title={t('kyc.assign.title')}
                                description={t('kyc.assign.supplier_help')}
                            >
                                <Form
                                    {...assignBatch.form(supplier.id)}
                                    options={{ preserveScroll: true }}
                                    className="space-y-3"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <FormField
                                                label={t('kyc.assign.label')}
                                                error={errors.batch}
                                            >
                                                {(control) => (
                                                    <NativeSelect
                                                        {...control}
                                                        name="batch"
                                                        defaultValue={
                                                            batch
                                                                ? ''
                                                                : (kyc_batches.find(
                                                                      (one) =>
                                                                          one.is_default,
                                                                  )?.id ?? '')
                                                        }
                                                        required
                                                    >
                                                        <option
                                                            value=""
                                                            disabled
                                                        >
                                                            {t(
                                                                'kyc.assign.choose',
                                                            )}
                                                        </option>
                                                        {kyc_batches.map(
                                                            (option) => (
                                                                <option
                                                                    key={
                                                                        option.id
                                                                    }
                                                                    value={
                                                                        option.id
                                                                    }
                                                                >
                                                                    {
                                                                        option.name
                                                                    }
                                                                    {option.is_default
                                                                        ? ` (${t('kyc.builder.default_badge')})`
                                                                        : ''}
                                                                </option>
                                                            ),
                                                        )}
                                                    </NativeSelect>
                                                )}
                                            </FormField>
                                            <SubmitButton
                                                processing={processing}
                                                variant="outline"
                                            >
                                                {t('kyc.assign.submit')}
                                            </SubmitButton>
                                        </>
                                    )}
                                </Form>
                            </SectionCard>
                        )}

                        {supplier.can_upload_kyc_document && (
                            <SectionCard
                                title={t('kyc.upload_on_behalf.action')}
                                description={t(
                                    'kyc.upload_on_behalf.description',
                                )}
                            >
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setUploading(true)}
                                >
                                    <Upload
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {t('kyc.upload_on_behalf.action')}
                                </Button>
                            </SectionCard>
                        )}

                        <SectionCard
                            title={t('supplier.admin.suppliers.decision')}
                        >
                            {round?.awaits_review && supplier.can_decide ? (
                                <div className="space-y-6">
                                    {decisionForm(
                                        approval,
                                        t('supplier.admin.suppliers.approve'),
                                        [
                                            {
                                                name: 'note',
                                                label: t(
                                                    'supplier.admin.suppliers.note',
                                                ),
                                            },
                                        ],
                                    )}
                                    {round.can_request_correction &&
                                        decisionForm(
                                            correction,
                                            t(
                                                'supplier.admin.suppliers.request_correction',
                                            ),
                                            [
                                                {
                                                    name: 'feedback',
                                                    label: t(
                                                        'supplier.admin.suppliers.feedback',
                                                    ),
                                                    // On a batch the rejected slots and
                                                    // their notes are added, so it may be empty.
                                                    required: !batch,
                                                },
                                            ],
                                            'outline',
                                        )}
                                    {decisionForm(
                                        rejection,
                                        t('supplier.admin.suppliers.reject'),
                                        [
                                            {
                                                name: 'reason',
                                                label: t(
                                                    'supplier.admin.suppliers.reason',
                                                ),
                                                required: true,
                                            },
                                            {
                                                name: 'note',
                                                label: t(
                                                    'supplier.admin.suppliers.note',
                                                ),
                                            },
                                        ],
                                        'destructive',
                                    )}
                                </div>
                            ) : !supplier.can_suspend &&
                              !supplier.can_reactivate ? (
                                <p className="text-muted-foreground text-sm">
                                    {supplier.can_decide
                                        ? t(
                                              'supplier.admin.suppliers.nothing_to_decide',
                                          )
                                        : t(
                                              'supplier.admin.suppliers.not_permitted',
                                          )}
                                </p>
                            ) : null}

                            {supplier.can_suspend &&
                                decisionForm(
                                    suspension,
                                    t('supplier.admin.suppliers.suspend'),
                                    [
                                        {
                                            name: 'reason',
                                            label: t(
                                                'supplier.admin.suppliers.reason',
                                            ),
                                            required: true,
                                        },
                                        {
                                            name: 'note',
                                            label: t(
                                                'supplier.admin.suppliers.note',
                                            ),
                                        },
                                    ],
                                    'destructive',
                                )}

                            {supplier.can_reactivate &&
                                decisionForm(
                                    reactivation,
                                    t('supplier.admin.suppliers.reactivate'),
                                    [
                                        {
                                            name: 'reason',
                                            label: t(
                                                'supplier.admin.suppliers.reason',
                                            ),
                                            required: true,
                                        },
                                        {
                                            name: 'note',
                                            label: t(
                                                'supplier.admin.suppliers.note',
                                            ),
                                        },
                                    ],
                                )}
                        </SectionCard>
                    </aside>
                </div>
            </PageContainer>

            <StaffKycUploadDialog
                targetsUrl={kycUpload.targets.url(supplier.id)}
                storeForm={kycUpload.store.form(supplier.id)}
                open={uploading}
                onOpenChange={setUploading}
            />
        </>
    );
}

AdminSupplierShow.layout = {
    breadcrumbs: [{ title: 'nav.suppliers', href: index() }],
};

import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Eye, FileText } from 'lucide-react';
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
import { index } from '@/routes/admin/suppliers';
import { show as documentShow } from '@/routes/admin/suppliers/kyc/documents';
import { store as correction } from '@/routes/admin/suppliers/kyc/correction';
import { store as approval } from '@/routes/admin/suppliers/approval';
import { store as reactivation } from '@/routes/admin/suppliers/reactivation';
import { store as rejection } from '@/routes/admin/suppliers/rejection';
import { store as suspension } from '@/routes/admin/suppliers/suspension';

type Props = {
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
        can_suspend: boolean;
        can_reactivate: boolean;
    };
    round: {
        id: string;
        round: number;
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
    supplier,
    round,
    status_history,
}: Props) {
    const { t, locale } = useTranslation();

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
                                    {t('supplier.admin.suppliers.no_evidence')}
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
                                                    {document.original_name} ·{' '}
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
                                                    required: true,
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
        </>
    );
}

AdminSupplierShow.layout = {
    breadcrumbs: [{ title: 'nav.suppliers', href: index() }],
};

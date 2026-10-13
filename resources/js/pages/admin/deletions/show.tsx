import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import SubmitButton from '@/components/forms/submit-button';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { approve, index, reject } from '@/routes/admin/deletions';
import type { DeletionQueueRow, ErasureImpact } from '@/types';

type Request = DeletionQueueRow & {
    reason: string;
    decision_note: string | null;
};

/**
 * Deciding one request to erase an account (§5.3).
 *
 * The screen says plainly what erasing does — what is deleted, what is kept with
 * the personal details replaced, which files go — before it asks for anything.
 * Erasing then needs a reason, the account's own email or reference typed back,
 * and a confirmed password, and cannot be taken back. Refusing needs a note,
 * which is written for the person who asked.
 */
export default function AdminDeletionsShow({
    request,
    confirmation,
    tombstone,
    can_decide: canDecide,
    refusal,
    impact,
}: {
    request: Request;
    confirmation: string;
    tombstone: string;
    can_decide: boolean;
    refusal: string | null;
    impact: ErasureImpact | null;
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('deletion.admin.review_title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={request.name ?? t('deletion.admin.review_title')}
                    description={t(`deletion.kind.${request.kind}`)}
                    actions={
                        <StatusPill
                            tone={
                                request.status === 'pending'
                                    ? 'warning'
                                    : 'neutral'
                            }
                            label={request.status_label}
                        />
                    }
                />

                <SectionCard title={t('deletion.admin.request')}>
                    <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('deletion.admin.columns.requester')}
                            </dt>
                            <dd className="font-medium">{request.email}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('deletion.admin.columns.requested')}
                            </dt>
                            <dd className="font-medium">
                                {new Date(
                                    request.requested_at,
                                ).toLocaleDateString(locale, {
                                    day: 'numeric',
                                    month: 'short',
                                    year: 'numeric',
                                })}
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-muted-foreground text-xs">
                                {t('deletion.your_reason')}
                            </dt>
                            <dd>{request.reason}</dd>
                        </div>
                        {request.decision_note && (
                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground text-xs">
                                    {t('deletion.admin.note')}
                                </dt>
                                <dd>{request.decision_note}</dd>
                            </div>
                        )}
                    </dl>
                </SectionCard>

                {refusal && (
                    <Notice
                        tone="warning"
                        title={t('deletion.admin.cannot_decide')}
                    >
                        {refusal}
                    </Notice>
                )}

                {impact && (
                    <SectionCard
                        title={t('deletion.admin.impact_title')}
                        description={t('deletion.admin.impact_description', {
                            tombstone,
                        })}
                    >
                        <div className="space-y-5 text-sm">
                            <ImpactList
                                title={t('deletion.admin.deleted')}
                                help={t('deletion.admin.deleted_help')}
                                counts={impact.deleted}
                            />
                            <ImpactList
                                title={t('deletion.admin.anonymized')}
                                help={t('deletion.admin.anonymized_help')}
                                counts={impact.anonymized}
                            />
                            <p>
                                <span className="font-medium">
                                    {t('deletion.admin.files')}
                                </span>{' '}
                                {impact.files}
                            </p>
                            <Notice tone="info" inset>
                                {t('deletion.admin.kept')}
                            </Notice>
                            {impact.notes.map((note) => (
                                <p
                                    key={note}
                                    className="text-muted-foreground text-xs"
                                >
                                    {note}
                                </p>
                            ))}
                        </div>
                    </SectionCard>
                )}

                {canDecide && (
                    <>
                        <SectionCard
                            tone="destructive"
                            title={t('deletion.admin.erase_title')}
                            description={t('deletion.admin.erase_description')}
                        >
                            <Form
                                {...approve.form(request.id)}
                                options={{ preserveScroll: true }}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="erase-reason">
                                                {t('deletion.admin.reason')}
                                            </Label>
                                            <textarea
                                                id="erase-reason"
                                                name="reason"
                                                rows={2}
                                                required
                                                maxLength={1000}
                                                className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                            />
                                            <InputError
                                                message={errors.reason}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="erase-confirmation">
                                                {t(
                                                    'deletion.admin.type_to_confirm',
                                                    {
                                                        value: confirmation,
                                                    },
                                                )}
                                            </Label>
                                            <Input
                                                id="erase-confirmation"
                                                name="confirmation"
                                                autoComplete="off"
                                                required
                                                placeholder={confirmation}
                                            />
                                            <InputError
                                                message={errors.confirmation}
                                            />
                                        </div>

                                        <SubmitButton
                                            processing={processing}
                                            variant="destructive"
                                        >
                                            {t('deletion.admin.erase')}
                                        </SubmitButton>
                                    </>
                                )}
                            </Form>
                        </SectionCard>

                        <SectionCard title={t('deletion.admin.reject_title')}>
                            <Form
                                {...reject.form(request.id)}
                                options={{ preserveScroll: true }}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="reject-note">
                                                {t(
                                                    'deletion.admin.reject_note',
                                                )}
                                            </Label>
                                            <textarea
                                                id="reject-note"
                                                name="note"
                                                rows={2}
                                                required
                                                maxLength={1000}
                                                className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'deletion.admin.reject_help',
                                                )}
                                            </p>
                                            <InputError message={errors.note} />
                                        </div>

                                        <SubmitButton
                                            processing={processing}
                                            variant="outline"
                                        >
                                            {t('deletion.admin.reject')}
                                        </SubmitButton>
                                    </>
                                )}
                            </Form>
                        </SectionCard>
                    </>
                )}

                <Button variant="ghost" size="sm" asChild>
                    <Link href={index()}>{t('deletion.admin.back')}</Link>
                </Button>
            </PageContainer>
        </>
    );
}

function ImpactList({
    title,
    help,
    counts,
}: {
    title: string;
    help: string;
    counts: Record<string, number>;
}) {
    const entries = Object.entries(counts);

    return (
        <div className="space-y-1.5">
            <p className="font-medium">{title}</p>
            <p className="text-muted-foreground text-xs">{help}</p>
            {entries.length === 0 ? (
                <p className="text-muted-foreground">—</p>
            ) : (
                <ul className="grid gap-x-6 gap-y-0.5 sm:grid-cols-2">
                    {entries.map(([table, count]) => (
                        <li
                            key={table}
                            className="flex justify-between gap-3 font-mono text-xs"
                        >
                            <span className="truncate">{table}</span>
                            <span className="tabular-nums">{count}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

AdminDeletionsShow.layout = {
    breadcrumbs: [
        { title: 'nav.deletion_requests', href: index() },
        { title: 'deletion.admin.review_title', href: index() },
    ],
};

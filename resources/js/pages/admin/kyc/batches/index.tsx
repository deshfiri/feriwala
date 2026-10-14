import { Form, Head, Link, router } from '@inertiajs/react';
import { Copy, Eye, Pencil, Plus, Power, Star } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/forms/native-select';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import StructureOutline from '@/components/kyc/structure-outline';
import { useTranslation } from '@/hooks/use-translation';
import {
    active,
    clone,
    defaultMethod,
    edit,
    preview,
    store,
} from '@/routes/admin/kyc/batch-builder';
import type { KycBatchTemplateRow, SelectOption } from '@/types';

/**
 * KYC batch templates (D30): every reusable verification form an administrator
 * has built, for Partners and for Suppliers.
 *
 * A template is switched on only after it has been published, and exactly one per
 * audience is the default a new round opens with when staff choose none. The list
 * says in words — never colour alone — which is on, which is the default and which
 * has changes that applicants cannot see yet.
 */
export default function KycBatchTemplates({
    batches,
    audiences,
    can,
}: {
    batches: KycBatchTemplateRow[];
    audiences: SelectOption[];
    /** What this viewer may do; a control they would be refused is not drawn. */
    can: { create: boolean; activate: boolean };
}) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    const act = (id: string, run: () => void) => {
        setBusy(id);
        run();
    };

    const label = (audience: string) =>
        audiences.find((option) => option.value === audience)?.label ??
        audience;

    return (
        <>
            <Head title={t('kyc.builder.list_title')} />

            <PageContainer>
                <PageHeader
                    title={t('kyc.builder.list_title')}
                    description={t('kyc.builder.list_help')}
                    actions={
                        can.create && (
                            <Button onClick={() => setCreating(true)}>
                                <Plus className="size-4" aria-hidden="true" />
                                {t('kyc.builder.new_batch')}
                            </Button>
                        )
                    }
                />

                {batches.length === 0 ? (
                    <EmptyState
                        title={t('kyc.builder.empty_title')}
                        description={t('kyc.builder.empty_description')}
                    />
                ) : (
                    <ul className="space-y-3">
                        {batches.map((batch) => (
                            <li
                                key={batch.id}
                                className="bg-card flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl border p-4 shadow-xs"
                            >
                                <div className="min-w-0 flex-1 space-y-1.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-base font-semibold">
                                            <Link
                                                href={edit(batch.id)}
                                                className="hover:underline"
                                            >
                                                {batch.name}
                                            </Link>
                                        </h2>
                                        <StatusPill
                                            tone={
                                                batch.is_active
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                            label={t(
                                                batch.is_active
                                                    ? 'kyc.builder.status_active'
                                                    : 'kyc.builder.status_off',
                                            )}
                                        />
                                        {batch.is_default && (
                                            <StatusPill
                                                tone="info"
                                                icon={Star}
                                                label={t(
                                                    'kyc.builder.default_badge',
                                                )}
                                            />
                                        )}
                                        {batch.has_unpublished_changes &&
                                            batch.published_version > 0 && (
                                                <StatusPill
                                                    tone="warning"
                                                    label={t(
                                                        'kyc.builder.unpublished_changes',
                                                    )}
                                                />
                                            )}
                                    </div>

                                    <p className="text-muted-foreground text-sm">
                                        {label(batch.audience)} ·{' '}
                                        {t('kyc.builder.groups_count', {
                                            count: batch.groups_count,
                                        })}{' '}
                                        ·{' '}
                                        {batch.published_version > 0
                                            ? t('kyc.builder.version', {
                                                  version:
                                                      batch.published_version,
                                              })
                                            : t('kyc.builder.not_published')}
                                    </p>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={edit(batch.id)}>
                                            <Pencil
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t('kyc.builder.edit')}
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={preview(batch.id)}>
                                            <Eye
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t('kyc.builder.preview')}
                                        </Link>
                                    </Button>
                                    {can.create && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={busy === batch.id}
                                            onClick={() =>
                                                act(batch.id, () =>
                                                    router.post(
                                                        clone.url(batch.id),
                                                        {},
                                                        {
                                                            onFinish: () =>
                                                                setBusy(null),
                                                        },
                                                    ),
                                                )
                                            }
                                        >
                                            <Copy
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t('kyc.builder.clone')}
                                        </Button>
                                    )}
                                    {can.activate && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={busy === batch.id}
                                            onClick={() =>
                                                act(batch.id, () =>
                                                    router.patch(
                                                        active.url(batch.id),
                                                        {
                                                            is_active:
                                                                !batch.is_active,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                            onFinish: () =>
                                                                setBusy(null),
                                                        },
                                                    ),
                                                )
                                            }
                                        >
                                            <Power
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t(
                                                batch.is_active
                                                    ? 'kyc.builder.deactivate'
                                                    : 'kyc.builder.activate',
                                            )}
                                        </Button>
                                    )}
                                    {can.activate && batch.is_active && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={busy === batch.id}
                                            onClick={() =>
                                                act(batch.id, () =>
                                                    router.patch(
                                                        defaultMethod.url(
                                                            batch.id,
                                                        ),
                                                        {
                                                            is_default:
                                                                !batch.is_default,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                            onFinish: () =>
                                                                setBusy(null),
                                                        },
                                                    ),
                                                )
                                            }
                                        >
                                            <Star
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t(
                                                batch.is_default
                                                    ? 'kyc.builder.remove_default'
                                                    : 'kyc.builder.make_default',
                                            )}
                                        </Button>
                                    )}
                                </div>

                                <div className="bg-surface-subtle w-full rounded-lg border p-4">
                                    <p className="text-muted-foreground mb-3 text-xs font-medium tracking-wide uppercase">
                                        {t('kyc.builder.outline_title')}
                                    </p>
                                    <StructureOutline
                                        outline={batch.outline}
                                        selfie={batch.selfie_mode}
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </PageContainer>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('kyc.builder.create_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('kyc.builder.create_help')}
                        </DialogDescription>
                    </DialogHeader>

                    <Form {...store.form()} className="space-y-4">
                        {({ processing, errors }) => (
                            <>
                                <div className="space-y-1.5">
                                    <Label htmlFor="new-batch-name">
                                        {t('kyc.builder.name')}
                                    </Label>
                                    <Input
                                        id="new-batch-name"
                                        name="name"
                                        required
                                        maxLength={120}
                                        autoFocus
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="new-batch-audience">
                                        {t('kyc.builder.audience')}
                                    </Label>
                                    <NativeSelect
                                        id="new-batch-audience"
                                        name="audience"
                                        defaultValue="account"
                                    >
                                        {audiences.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={errors.audience} />
                                </div>

                                <input
                                    type="hidden"
                                    name="selfie_mode"
                                    value="disabled"
                                />
                                <input
                                    type="hidden"
                                    name="selfie_allows_upload"
                                    value="0"
                                />

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setCreating(false)}
                                    >
                                        {t('kyc.builder.cancel')}
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('kyc.builder.create')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

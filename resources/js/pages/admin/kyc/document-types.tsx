import { Head, router } from '@inertiajs/react';
import {
    Archive,
    ChevronDown,
    ChevronUp,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import KycDocumentTypeController from '@/actions/App/Http/Controllers/Admin/KycDocumentTypeController';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { KycDocumentTypeRow, SelectOption } from '@/types';
import RequirementDialog from './requirement-dialog';

type Props = {
    types: KycDocumentTypeRow[];
    /** Packages a scope rule may name. Empty until P1-32 (Package CRUD). */
    packages: SelectOption[];
    /** The countries Feriwala serves (config/countries.php). */
    countries: SelectOption[];
    can: { create: boolean };
};

/**
 * The KYC requirement catalogue (§7.2).
 *
 * An ordered list rather than a `DataTable`, deliberately. The order here is
 * the order applicants see, and it is set by hand — a paginated, sortable table
 * would let a reviewer sort by name and then reorder rows they are no longer
 * looking at in sequence. The catalogue is also short by nature: a form with
 * fifty requirements is a problem to fix, not to paginate.
 *
 * Three states are shown apart because they mean different things: **active**
 * is on the form now, **paused** is off but can return, **archived** is retired
 * for good and cannot be edited — it is part of the record of what past rounds
 * were asked for.
 */
export default function KycDocumentTypes({
    types,
    packages,
    countries,
    can,
}: Props) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<KycDocumentTypeRow | null>(null);
    const [creating, setCreating] = useState(false);

    const live = types.filter((type) => !type.is_archived);
    const archived = types.filter((type) => type.is_archived);

    // The catalogue is platform configuration; without the permission there is
    // nothing here to show rather than an empty version of the page.
    if (!can.create && types.length === 0) {
        return (
            <>
                <Head title={t('kyc.document_types.title')} />
                <PageContainer>
                    <PermissionDeniedState
                        title={t('kyc.document_types.forbidden_title')}
                        description={t(
                            'kyc.document_types.forbidden_description',
                        )}
                    />
                </PageContainer>
            </>
        );
    }

    const move = (index: number, direction: -1 | 1) => {
        const next = [...live];
        const target = index + direction;

        if (target < 0 || target >= next.length) {
            return;
        }

        [next[index], next[target]] = [next[target], next[index]];

        router.post(
            KycDocumentTypeController.reorder.url(),
            { order: next.map((type) => type.id) },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={t('kyc.document_types.title')} />

            <PageContainer>
                <PageHeader
                    title={t('kyc.document_types.title')}
                    description={t('kyc.document_types.description')}
                    actions={
                        can.create ? (
                            <Button size="sm" onClick={() => setCreating(true)}>
                                <Plus className="size-4" />
                                {t('kyc.document_types.add')}
                            </Button>
                        ) : undefined
                    }
                />

                {live.length === 0 ? (
                    <EmptyState
                        title={t('kyc.document_types.empty_title')}
                        description={t('kyc.document_types.empty_description')}
                        action={
                            can.create ? (
                                <Button
                                    size="sm"
                                    onClick={() => setCreating(true)}
                                >
                                    <Plus className="size-4" />
                                    {t('kyc.document_types.add')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <ul className="bg-card divide-border divide-y rounded-xl border">
                        {live.map((type, index) => (
                            <TypeRow
                                key={type.id}
                                type={type}
                                onEdit={() => setEditing(type)}
                                onMoveUp={
                                    index > 0
                                        ? () => move(index, -1)
                                        : undefined
                                }
                                onMoveDown={
                                    index < live.length - 1
                                        ? () => move(index, 1)
                                        : undefined
                                }
                            />
                        ))}
                    </ul>
                )}

                {archived.length > 0 && (
                    <section className="space-y-3">
                        <div>
                            <h2 className="font-medium">
                                {t('kyc.document_types.archived_heading')}
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                {t('kyc.document_types.archived_description')}
                            </p>
                        </div>

                        <ul className="bg-card divide-border divide-y rounded-xl border">
                            {archived.map((type) => (
                                <TypeRow key={type.id} type={type} />
                            ))}
                        </ul>
                    </section>
                )}
            </PageContainer>

            <RequirementDialog
                open={creating}
                onOpenChange={setCreating}
                type={null}
                packages={packages}
                countries={countries}
            />

            <RequirementDialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                type={editing}
                packages={packages}
                countries={countries}
            />
        </>
    );
}

function TypeRow({
    type,
    onEdit,
    onMoveUp,
    onMoveDown,
}: {
    type: KycDocumentTypeRow;
    onEdit?: () => void;
    onMoveUp?: () => void;
    onMoveDown?: () => void;
}) {
    const { t } = useTranslation();

    return (
        <li className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex min-w-0 gap-2">
                {onMoveUp || onMoveDown ? (
                    <div className="flex shrink-0 flex-col">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-6"
                            disabled={!onMoveUp}
                            onClick={onMoveUp}
                            aria-label={t('kyc.document_types.actions.move_up')}
                        >
                            <ChevronUp className="size-3.5" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-6"
                            disabled={!onMoveDown}
                            onClick={onMoveDown}
                            aria-label={t(
                                'kyc.document_types.actions.move_down',
                            )}
                        >
                            <ChevronDown className="size-3.5" />
                        </Button>
                    </div>
                ) : null}

                <div className="min-w-0 space-y-1">
                    <p className="font-medium">
                        {type.name}
                        <span className="text-muted-foreground font-normal">
                            {' · '}
                            {type.key}
                        </span>
                    </p>

                    {type.instructions && (
                        <p className="text-muted-foreground text-sm">
                            {type.instructions}
                        </p>
                    )}

                    {type.requires_file && (
                        <p className="text-muted-foreground text-xs">
                            {t('kyc.document_types.meta.formats', {
                                formats: type.accepted_mime_types.join(', '),
                            })}{' '}
                            ·{' '}
                            {t('kyc.document_types.meta.max_size', {
                                size: type.max_size_kb,
                            })}
                        </p>
                    )}

                    <ScopeSummary scopes={type.scopes} />
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                <StatusPill
                    tone={type.is_required ? 'warning' : 'neutral'}
                    label={
                        type.is_required
                            ? t('kyc.document_types.state.required')
                            : t('kyc.document_types.state.optional')
                    }
                />

                <StatusPill
                    tone={
                        type.is_archived
                            ? 'neutral'
                            : type.is_active
                              ? 'success'
                              : 'warning'
                    }
                    label={
                        type.is_archived
                            ? t('kyc.document_types.state.archived')
                            : type.is_active
                              ? t('kyc.document_types.state.active')
                              : t('kyc.document_types.state.paused')
                    }
                />

                {type.can.update && onEdit && (
                    <Button variant="ghost" size="sm" onClick={onEdit}>
                        <Pencil className="size-4" />
                        {t('kyc.document_types.actions.edit')}
                    </Button>
                )}

                {type.can.update && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.patch(
                                KycDocumentTypeController.setActive.url(
                                    type.id,
                                ),
                                { is_active: !type.is_active },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {type.is_active
                            ? t('kyc.document_types.actions.pause')
                            : t('kyc.document_types.actions.resume')}
                    </Button>
                )}

                {!type.is_archived && type.can.update && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.post(
                                KycDocumentTypeController.archive.url(type.id),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Archive className="size-4" />
                        {t('kyc.document_types.actions.archive')}
                    </Button>
                )}

                {/*
                 * Deletion is offered only for a type nothing has referenced.
                 * Offering it and refusing on submit would teach an
                 * administrator that the button sometimes lies — so where it is
                 * unavailable, the reason is shown instead.
                 */}
                {type.can.delete ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.delete(
                                KycDocumentTypeController.destroy.url(type.id),
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Trash2 className="size-4" />
                        {t('kyc.document_types.actions.delete')}
                    </Button>
                ) : type.used_by_rounds > 0 ? (
                    <span className="text-muted-foreground text-xs">
                        {t('kyc.document_types.meta.cannot_delete')}
                    </span>
                ) : null}
            </div>
        </li>
    );
}

function ScopeSummary({ scopes }: { scopes: KycDocumentTypeRow['scopes'] }) {
    const { t } = useTranslation();

    if (scopes.length === 0) {
        return (
            <p className="text-muted-foreground text-xs">
                {t('kyc.document_types.scopes.everyone')}
            </p>
        );
    }

    return (
        <ul className="text-muted-foreground space-y-0.5 text-xs">
            {scopes.map((scope, index) => (
                <li key={index}>
                    {scope.package && scope.country
                        ? t('kyc.document_types.scopes.package_and_country', {
                              package: scope.package,
                              country: scope.country,
                          })
                        : scope.country
                          ? t('kyc.document_types.scopes.country_only', {
                                country: scope.country,
                            })
                          : t('kyc.document_types.scopes.package_only', {
                                package: scope.package ?? '',
                            })}
                    {scope.is_required !== null &&
                        ` — ${
                            scope.is_required
                                ? t('kyc.document_types.state.required')
                                : t('kyc.document_types.state.optional')
                        }`}
                </li>
            ))}
        </ul>
    );
}

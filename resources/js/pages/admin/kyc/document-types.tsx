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
import KycBatchController from '@/actions/App/Http/Controllers/Admin/KycBatchController';
import KycDocumentTypeController from '@/actions/App/Http/Controllers/Admin/KycDocumentTypeController';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { KycBatchRow, KycDocumentTypeRow, SelectOption } from '@/types';
import BatchDialog from './batch-dialog';
import RequirementDialog from './requirement-dialog';

type Props = {
    types: KycDocumentTypeRow[];
    batches: KycBatchRow[];
    /** Packages a batch rule may name. */
    packages: SelectOption[];
    /** The countries Feriwala serves (config/countries.php). */
    countries: SelectOption[];
    can: { create: boolean };
};

/**
 * The verification catalogue (§7.2): batches, and the criteria inside them.
 *
 * An administrator builds as many batches as they like — Identity, Business,
 * Address — and decides what each holds and who it applies to. An applicant is
 * asked for every batch that applies to them, on one form, in one submission.
 * There is nothing built in: every batch and every criterion is created here.
 *
 * An ordered list rather than a `DataTable`, deliberately. The order here is the
 * order applicants see, and it is set by hand — a paginated, sortable table
 * would let a reviewer sort by name and then reorder rows they are no longer
 * looking at in sequence. The catalogue is also short by nature: a form with
 * fifty criteria is a problem to fix, not to paginate.
 *
 * Three states are shown apart because they mean different things: **active** is
 * on the form now, **paused** is off but can return, **archived** is retired for
 * good and cannot be edited — it is part of the record of what past submissions
 * were asked for.
 */
export default function KycDocumentTypes({
    types,
    batches,
    packages,
    countries,
    can,
}: Props) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<KycDocumentTypeRow | null>(null);
    const [creating, setCreating] = useState<{ batch: string | null } | null>(
        null,
    );
    const [editingBatch, setEditingBatch] = useState<KycBatchRow | null>(null);
    const [creatingBatch, setCreatingBatch] = useState(false);

    const liveBatches = batches.filter((batch) => !batch.is_archived);
    const archivedBatches = batches.filter((batch) => batch.is_archived);
    const live = types.filter((type) => !type.is_archived);
    const archived = types.filter((type) => type.is_archived);

    const liveIn = (batchId: string | null) =>
        live.filter((type) => (type.batch ?? null) === batchId);
    const ungrouped = liveIn(null);

    // The catalogue is platform configuration; without the permission there is
    // nothing here to show rather than an empty version of the page.
    if (!can.create && types.length === 0 && batches.length === 0) {
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

    /** Moves one criterion within its own batch, then saves the whole order. */
    const moveType = (batchId: string | null, index: number, delta: -1 | 1) => {
        const order = [...liveBatches.map((batch) => batch.id), null].flatMap(
            (id) => {
                const group = [...liveIn(id)];

                if (id === batchId) {
                    const target = index + delta;

                    if (target < 0 || target >= group.length) {
                        return group;
                    }

                    [group[index], group[target]] = [
                        group[target],
                        group[index],
                    ];
                }

                return group;
            },
        );

        router.post(
            KycDocumentTypeController.reorder.url(),
            { order: order.map((type) => type.id) },
            { preserveScroll: true },
        );
    };

    const moveBatch = (index: number, delta: -1 | 1) => {
        const next = [...liveBatches];
        const target = index + delta;

        if (target < 0 || target >= next.length) {
            return;
        }

        [next[index], next[target]] = [next[target], next[index]];

        router.post(
            KycBatchController.reorder.url(),
            { order: next.map((batch) => batch.id) },
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
                            <>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setCreating({ batch: null })}
                                    disabled={liveBatches.length === 0}
                                >
                                    <Plus className="size-4" />
                                    {t('kyc.document_types.add')}
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => setCreatingBatch(true)}
                                >
                                    <Plus className="size-4" />
                                    {t('kyc.batches.add')}
                                </Button>
                            </>
                        ) : undefined
                    }
                />

                {liveBatches.length === 0 && ungrouped.length === 0 ? (
                    <EmptyState
                        title={t('kyc.batches.empty_title')}
                        description={t('kyc.batches.empty_description')}
                        action={
                            can.create ? (
                                <Button
                                    size="sm"
                                    onClick={() => setCreatingBatch(true)}
                                >
                                    <Plus className="size-4" />
                                    {t('kyc.batches.add')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="space-y-6">
                        {liveBatches.map((batch, index) => (
                            <BatchSection
                                key={batch.id}
                                batch={batch}
                                criteria={liveIn(batch.id)}
                                packages={packages}
                                canCreate={can.create}
                                onAddCriterion={() =>
                                    setCreating({ batch: batch.id })
                                }
                                onEditBatch={() => setEditingBatch(batch)}
                                onEditCriterion={setEditing}
                                onMoveBatchUp={
                                    index > 0
                                        ? () => moveBatch(index, -1)
                                        : undefined
                                }
                                onMoveBatchDown={
                                    index < liveBatches.length - 1
                                        ? () => moveBatch(index, 1)
                                        : undefined
                                }
                                onMoveCriterion={(position, delta) =>
                                    moveType(batch.id, position, delta)
                                }
                            />
                        ))}

                        {ungrouped.length > 0 && (
                            <section className="space-y-3">
                                <div>
                                    <h2 className="font-medium">
                                        {t('kyc.batches.ungrouped_heading')}
                                    </h2>
                                    <p className="text-muted-foreground text-sm">
                                        {t('kyc.batches.ungrouped_description')}
                                    </p>
                                </div>

                                <ul className="bg-card divide-border divide-y rounded-xl border">
                                    {ungrouped.map((type, index) => (
                                        <TypeRow
                                            key={type.id}
                                            type={type}
                                            onEdit={() => setEditing(type)}
                                            onMoveUp={
                                                index > 0
                                                    ? () =>
                                                          moveType(
                                                              null,
                                                              index,
                                                              -1,
                                                          )
                                                    : undefined
                                            }
                                            onMoveDown={
                                                index < ungrouped.length - 1
                                                    ? () =>
                                                          moveType(
                                                              null,
                                                              index,
                                                              1,
                                                          )
                                                    : undefined
                                            }
                                        />
                                    ))}
                                </ul>
                            </section>
                        )}
                    </div>
                )}

                {(archivedBatches.length > 0 || archived.length > 0) && (
                    <section className="space-y-3">
                        <div>
                            <h2 className="font-medium">
                                {t('kyc.document_types.archived_heading')}
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                {t('kyc.document_types.archived_description')}
                            </p>
                        </div>

                        {archivedBatches.length > 0 && (
                            <ul className="bg-card divide-border divide-y rounded-xl border">
                                {archivedBatches.map((batch) => (
                                    <li
                                        key={batch.id}
                                        className="flex items-center justify-between gap-3 p-4"
                                    >
                                        <p className="font-medium">
                                            {batch.name}
                                            <span className="text-muted-foreground font-normal">
                                                {' · '}
                                                {batch.key}
                                            </span>
                                        </p>
                                        <StatusPill
                                            tone="neutral"
                                            label={t(
                                                'kyc.document_types.state.archived',
                                            )}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}

                        {archived.length > 0 && (
                            <ul className="bg-card divide-border divide-y rounded-xl border">
                                {archived.map((type) => (
                                    <TypeRow key={type.id} type={type} />
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </PageContainer>

            <RequirementDialog
                open={creating !== null}
                onOpenChange={(open) => !open && setCreating(null)}
                type={null}
                batches={liveBatches}
                defaultBatch={creating?.batch ?? null}
                packages={packages}
                countries={countries}
            />

            <RequirementDialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                type={editing}
                batches={liveBatches}
                defaultBatch={editing?.batch ?? null}
                packages={packages}
                countries={countries}
            />

            <BatchDialog
                open={creatingBatch}
                onOpenChange={setCreatingBatch}
                batch={null}
                packages={packages}
                countries={countries}
            />

            <BatchDialog
                open={editingBatch !== null}
                onOpenChange={(open) => !open && setEditingBatch(null)}
                batch={editingBatch}
                packages={packages}
                countries={countries}
            />
        </>
    );
}

/**
 * One batch: what it is, who it applies to, and the criteria inside it. Its own
 * controls (pause, archive, delete) sit in the header; the criteria carry theirs.
 */
function BatchSection({
    batch,
    criteria,
    packages,
    canCreate,
    onAddCriterion,
    onEditBatch,
    onEditCriterion,
    onMoveBatchUp,
    onMoveBatchDown,
    onMoveCriterion,
}: {
    batch: KycBatchRow;
    criteria: KycDocumentTypeRow[];
    packages: SelectOption[];
    canCreate: boolean;
    onAddCriterion: () => void;
    onEditBatch: () => void;
    onEditCriterion: (type: KycDocumentTypeRow) => void;
    onMoveBatchUp?: () => void;
    onMoveBatchDown?: () => void;
    onMoveCriterion: (index: number, delta: -1 | 1) => void;
}) {
    const { t } = useTranslation();

    return (
        <section className="bg-card rounded-xl border">
            <header className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 gap-2">
                    {onMoveBatchUp || onMoveBatchDown ? (
                        <div className="flex shrink-0 flex-col">
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-6"
                                disabled={!onMoveBatchUp}
                                onClick={onMoveBatchUp}
                                aria-label={t('kyc.batches.actions.move_up')}
                            >
                                <ChevronUp className="size-3.5" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-6"
                                disabled={!onMoveBatchDown}
                                onClick={onMoveBatchDown}
                                aria-label={t('kyc.batches.actions.move_down')}
                            >
                                <ChevronDown className="size-3.5" />
                            </Button>
                        </div>
                    ) : null}

                    <div className="min-w-0 space-y-1">
                        <h2 className="font-semibold">
                            {batch.name}
                            <span className="text-muted-foreground font-normal">
                                {' · '}
                                {batch.key}
                            </span>
                        </h2>

                        {batch.description && (
                            <p className="text-muted-foreground text-sm">
                                {batch.description}
                            </p>
                        )}

                        <p className="text-muted-foreground text-xs">
                            {t(`kyc.document_types.audience.${batch.audience}`)}
                            {' · '}
                            {t('kyc.batches.criteria_count', {
                                count: batch.criteria_count,
                            })}
                        </p>

                        <RuleSummary rules={batch.rules} packages={packages} />
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusPill
                        tone={batch.is_active ? 'success' : 'warning'}
                        label={
                            batch.is_active
                                ? t('kyc.document_types.state.active')
                                : t('kyc.document_types.state.paused')
                        }
                    />

                    {canCreate && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={onAddCriterion}
                        >
                            <Plus className="size-4" />
                            {t('kyc.batches.actions.add_criterion')}
                        </Button>
                    )}

                    {batch.can.update && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={onEditBatch}
                            >
                                <Pencil className="size-4" />
                                {t('kyc.document_types.actions.edit')}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.patch(
                                        KycBatchController.setActive.url(
                                            batch.id,
                                        ),
                                        { is_active: !batch.is_active },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {batch.is_active
                                    ? t('kyc.document_types.actions.pause')
                                    : t('kyc.document_types.actions.resume')}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        KycBatchController.archive.url(
                                            batch.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Archive className="size-4" />
                                {t('kyc.document_types.actions.archive')}
                            </Button>
                        </>
                    )}

                    {/* Offered only for a batch nothing has used and that holds
                        nothing — otherwise the control would sometimes lie. */}
                    {batch.can.delete && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.delete(
                                    KycBatchController.destroy.url(batch.id),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Trash2 className="size-4" />
                            {t('kyc.document_types.actions.delete')}
                        </Button>
                    )}
                </div>
            </header>

            {criteria.length === 0 ? (
                <p className="text-muted-foreground p-4 text-sm">
                    {t('kyc.batches.no_criteria')}
                </p>
            ) : (
                <ul className="divide-border divide-y">
                    {criteria.map((type, index) => (
                        <TypeRow
                            key={type.id}
                            type={type}
                            onEdit={() => onEditCriterion(type)}
                            onMoveUp={
                                index > 0
                                    ? () => onMoveCriterion(index, -1)
                                    : undefined
                            }
                            onMoveDown={
                                index < criteria.length - 1
                                    ? () => onMoveCriterion(index, 1)
                                    : undefined
                            }
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

/** Who a batch applies to, in words. No rules means everyone. */
function RuleSummary({
    rules,
    packages,
}: {
    rules: KycBatchRow['rules'];
    packages: SelectOption[];
}) {
    const { t } = useTranslation();

    if (rules.length === 0) {
        return (
            <p className="text-muted-foreground text-xs">
                {t('kyc.batches.rules.everyone')}
            </p>
        );
    }

    const packageName = (id: string | null) =>
        packages.find((option) => option.value === id)?.label ?? id ?? '';

    return (
        <ul className="text-muted-foreground space-y-0.5 text-xs">
            {rules.map((rule, index) => (
                <li key={index}>
                    {rule.package && rule.country
                        ? t('kyc.document_types.scopes.package_and_country', {
                              package: packageName(rule.package),
                              country: rule.country,
                          })
                        : rule.country
                          ? t('kyc.document_types.scopes.country_only', {
                                country: rule.country,
                            })
                          : t('kyc.document_types.scopes.package_only', {
                                package: packageName(rule.package),
                            })}
                </li>
            ))}
        </ul>
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

                    {type.batch === null && (
                        <p className="text-muted-foreground text-xs">
                            {t(`kyc.document_types.audience.${type.audience}`)}
                        </p>
                    )}

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

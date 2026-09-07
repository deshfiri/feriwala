import { Head, router } from '@inertiajs/react';
import { Archive, Package as PackageIcon, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import PackageController from '@/actions/App/Http/Controllers/Admin/PackageController';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { PackageFeatureDefinition, PackageRow } from '@/types';
import PackageDialog from './package-dialog';

type Props = {
    packages: PackageRow[];
    features: PackageFeatureDefinition[];
    charge_types: string[];
    frequencies: string[];
    can: { create: boolean };
};

/**
 * The package catalogue (§8.1).
 *
 * Archived plans stay listed rather than disappearing. A subscription, a
 * payment and an invoice all name the package they were for, so "which plan was
 * this account on in March" has to stay answerable — a catalogue that quietly
 * drops retired plans is one where it is not.
 *
 * Where a package cannot be archived, **the reason is on the row** rather than
 * behind the button. A guard an administrator only meets on submit is one they
 * meet after writing a change they now have to undo.
 */
export default function AdminPackages({
    packages,
    features,
    charge_types: chargeTypes,
    frequencies,
    can,
}: Props) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<PackageRow | null>(null);
    const [creating, setCreating] = useState(false);

    const live = packages.filter((row) => !row.is_archived);
    const archived = packages.filter((row) => row.is_archived);

    if (!can.create && packages.length === 0) {
        return (
            <>
                <Head title={t('package.title')} />
                <PageContainer>
                    <PermissionDeniedState
                        title={t('package.forbidden_title')}
                        description={t('package.forbidden_description')}
                    />
                </PageContainer>
            </>
        );
    }

    return (
        <>
            <Head title={t('package.title')} />

            <PageContainer>
                <PageHeader
                    title={t('package.title')}
                    description={t('package.description')}
                    actions={
                        can.create ? (
                            <Button size="sm" onClick={() => setCreating(true)}>
                                <Plus className="size-4" />
                                {t('package.add')}
                            </Button>
                        ) : undefined
                    }
                />

                {live.length === 0 ? (
                    <EmptyState
                        icon={PackageIcon}
                        title={t('package.empty_title')}
                        description={t('package.empty_description')}
                        action={
                            can.create ? (
                                <Button
                                    size="sm"
                                    onClick={() => setCreating(true)}
                                >
                                    <Plus className="size-4" />
                                    {t('package.add')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <ul className="bg-card divide-border divide-y rounded-xl border">
                        {live.map((row) => (
                            <PackageCard
                                key={row.id}
                                row={row}
                                onEdit={() => setEditing(row)}
                            />
                        ))}
                    </ul>
                )}

                {archived.length > 0 && (
                    <section className="space-y-3">
                        <div>
                            <h2 className="font-medium">
                                {t('package.archived_heading')}
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                {t('package.archived_description')}
                            </p>
                        </div>

                        <ul className="bg-card divide-border divide-y rounded-xl border">
                            {archived.map((row) => (
                                <PackageCard key={row.id} row={row} />
                            ))}
                        </ul>
                    </section>
                )}
            </PageContainer>

            <PackageDialog
                open={creating}
                onOpenChange={setCreating}
                row={null}
                features={features}
                chargeTypes={chargeTypes}
                frequencies={frequencies}
            />

            <PackageDialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                row={editing}
                features={features}
                chargeTypes={chargeTypes}
                frequencies={frequencies}
            />
        </>
    );
}

function PackageCard({
    row,
    onEdit,
}: {
    row: PackageRow;
    onEdit?: () => void;
}) {
    const { t } = useTranslation();

    const blockers = [
        ...(row.blockers.kyc_requirements?.length
            ? [
                  t('package.blockers.kyc', {
                      names: row.blockers.kyc_requirements.join(', '),
                  }),
              ]
            : []),
        ...(row.blockers.subscriptions?.length
            ? [
                  t('package.blockers.subscriptions', {
                      count: row.blockers.subscriptions[0],
                  }),
              ]
            : []),
    ];

    return (
        <li className="flex flex-col gap-3 p-4 lg:flex-row lg:items-start lg:justify-between">
            <div className="min-w-0 space-y-1">
                <p className="font-medium">
                    {row.name}
                    <span className="text-muted-foreground font-normal">
                        {' · '}
                        {row.id}
                    </span>
                </p>

                {row.short_description && (
                    <p className="text-muted-foreground text-sm">
                        {row.short_description}
                    </p>
                )}

                <p className="text-muted-foreground flex flex-wrap gap-x-3 text-xs">
                    <span>
                        {t('package.meta.fee')}:{' '}
                        {/* Rendered, never computed, in the browser (§36.1). */}
                        <MoneyAmount amount={row.fee} direction="credit" />
                    </span>
                    <span>
                        {row.validity_days
                            ? t('package.meta.validity', {
                                  days: row.validity_days,
                              })
                            : t('package.meta.no_expiry')}
                    </span>
                    <span>
                        {t('package.meta.subscriptions', {
                            count: row.subscriptions_count,
                        })}
                    </span>
                </p>

                {blockers.length > 0 && (
                    <div className="text-muted-foreground space-y-0.5 text-xs">
                        <p className="font-medium">
                            {t('package.blockers.heading')}
                        </p>
                        {blockers.map((line, index) => (
                            <p key={index}>{line}</p>
                        ))}
                        <p>{t('package.blockers.help')}</p>
                    </div>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                <StatusPill
                    tone={
                        row.is_archived
                            ? 'neutral'
                            : row.is_active
                              ? 'success'
                              : 'warning'
                    }
                    label={
                        row.is_archived
                            ? t('package.state.archived')
                            : row.is_active
                              ? t('package.state.active')
                              : t('package.state.paused')
                    }
                />

                {!row.is_archived && (
                    <StatusPill
                        tone={row.is_public ? 'info' : 'neutral'}
                        label={
                            row.is_public
                                ? t('package.state.public')
                                : t('package.state.private')
                        }
                    />
                )}

                {row.can.update && onEdit && (
                    <Button variant="ghost" size="sm" onClick={onEdit}>
                        <Pencil className="size-4" />
                        {t('package.actions.edit')}
                    </Button>
                )}

                {row.can.update && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.patch(
                                PackageController.setActive.url(row.id),
                                { is_active: !row.is_active },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {row.is_active
                            ? t('package.actions.pause')
                            : t('package.actions.resume')}
                    </Button>
                )}

                {/*
                 * Offered only when nothing stands in the way. The reason sits
                 * on the row above, so the absence is explained rather than
                 * mysterious.
                 */}
                {row.can.archive && blockers.length === 0 && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.post(
                                PackageController.archive.url(row.id),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Archive className="size-4" />
                        {t('package.actions.archive')}
                    </Button>
                )}
            </div>
        </li>
    );
}

import { Head, router } from '@inertiajs/react';
import { Archive, GripVertical } from 'lucide-react';
import Heading from '@/components/heading';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import KycDocumentTypeController from '@/actions/App/Http/Controllers/Admin/KycDocumentTypeController';

type Scope = {
    package: string | null;
    country: string | null;
    is_required: boolean | null;
};

type DocumentType = {
    id: string;
    key: string;
    name: string;
    instructions: string | null;
    is_required: boolean;
    is_active: boolean;
    is_archived: boolean;
    requires_file: boolean;
    requires_value: boolean;
    value_label: string | null;
    accepted_mime_types: string[];
    max_size_kb: number;
    sort_order: number;
    used_by_rounds: number;
    scopes: Scope[];
    can: { update: boolean; delete: boolean };
};

type Props = {
    types: DocumentType[];
    can: { create: boolean };
};

/**
 * The KYC requirement catalogue (§7.2).
 *
 * Three states are shown apart, because they mean different things: **active**
 * is on the form now, **paused** is off but can come back, **archived** is
 * retired for good and cannot be edited — it is part of the record of what past
 * rounds were asked for.
 */
export default function KycDocumentTypes({ types }: Props) {
    const { t } = useTranslation();

    const live = types.filter((type) => !type.is_archived);
    const archived = types.filter((type) => type.is_archived);

    return (
        <>
            <Head title={t('Verification requirements')} />

            <div className="space-y-6 p-4">
                <Heading
                    title={t('Verification requirements')}
                    description={t(
                        'What applicants are asked for, and who is asked for it.',
                    )}
                />

                {live.length === 0 ? (
                    <EmptyState
                        title={t('No requirements configured')}
                        description={t(
                            'Applicants are asked for nothing until you add a document type.',
                        )}
                    />
                ) : (
                    <ul className="divide-border divide-y rounded-lg border">
                        {live.map((type) => (
                            <TypeRow key={type.id} type={type} />
                        ))}
                    </ul>
                )}

                {archived.length > 0 ? (
                    <div className="space-y-3">
                        <Heading
                            variant="small"
                            title={t('Archived')}
                            description={t(
                                'Retired requirements. Kept because past rounds were judged against them.',
                            )}
                        />
                        <ul className="divide-border divide-y rounded-lg border opacity-70">
                            {archived.map((type) => (
                                <TypeRow key={type.id} type={type} />
                            ))}
                        </ul>
                    </div>
                ) : null}
            </div>
        </>
    );
}

function TypeRow({ type }: { type: DocumentType }) {
    const { t } = useTranslation();

    return (
        <li className="flex flex-wrap items-start justify-between gap-3 p-4">
            <div className="flex min-w-0 gap-3">
                <GripVertical
                    aria-hidden="true"
                    className="text-muted-foreground mt-1 size-4 shrink-0"
                />

                <div className="min-w-0 space-y-1">
                    <p className="font-medium">
                        {type.name}
                        <span className="text-muted-foreground">
                            {' '}
                            · {type.key}
                        </span>
                    </p>

                    {type.instructions ? (
                        <p className="text-muted-foreground text-sm">
                            {type.instructions}
                        </p>
                    ) : null}

                    <p className="text-muted-foreground text-xs">
                        {type.accepted_mime_types.join(', ')} ·{' '}
                        {t('up to :size KB', { size: type.max_size_kb })}
                    </p>

                    <ScopeSummary scopes={type.scopes} />
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <StatusPill
                    tone={type.is_required ? 'warning' : 'neutral'}
                    label={type.is_required ? t('Required') : t('Optional')}
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
                            ? t('Archived')
                            : type.is_active
                              ? t('Active')
                              : t('Paused')
                    }
                />

                {type.can.update ? (
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
                        {type.is_active ? t('Pause') : t('Resume')}
                    </Button>
                ) : null}

                {!type.is_archived ? (
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
                        {t('Archive')}
                    </Button>
                ) : null}

                {/*
                 * Deletion is offered only for a type nothing has referenced.
                 * Offering it and refusing on submit would teach an
                 * administrator that the button sometimes lies.
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
                        {t('Delete')}
                    </Button>
                ) : (
                    <span className="text-muted-foreground text-xs">
                        {t('Used by :count rounds', {
                            count: type.used_by_rounds,
                        })}
                    </span>
                )}
            </div>
        </li>
    );
}

function ScopeSummary({ scopes }: { scopes: Scope[] }) {
    const { t } = useTranslation();

    if (scopes.length === 0) {
        return (
            <p className="text-muted-foreground text-xs">
                {t('Applies to everyone')}
            </p>
        );
    }

    return (
        <ul className="text-muted-foreground text-xs">
            {scopes.map((scope, index) => (
                <li key={index}>
                    {scope.package && scope.country
                        ? t(':package accounts in :country', {
                              package: scope.package,
                              country: scope.country,
                          })
                        : scope.country
                          ? t('Accounts in :country', {
                                country: scope.country,
                            })
                          : t(':package accounts', {
                                package: scope.package ?? '',
                            })}
                    {scope.is_required === null
                        ? ''
                        : scope.is_required
                          ? ` — ${t('required')}`
                          : ` — ${t('optional')}`}
                </li>
            ))}
        </ul>
    );
}

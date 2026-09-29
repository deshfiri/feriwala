import { Head, router } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { useState } from 'react';
import PayoutMethodController from '@/actions/App/Http/Controllers/Erp/PayoutMethodController';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/payout-methods';
import PayoutMethodDialog from '@/pages/erp/payout-methods/payout-method-dialog';
import ArchivePayoutMethodDialog from '@/pages/erp/payout-methods/archive-payout-method-dialog';

type Method = {
    id: string;
    type: string;
    type_label: string;
    label: string;
    masked_number: string;
    bank_name: string | null;
    branch_name: string | null;
    is_default: boolean;
    is_active: boolean;
    status_label: string;
    verified_at: string | null;
    created_at: string;
};

type TypeOption = { value: string; label: string; fields: string[] };

export default function ErpPayoutMethodsIndex({
    methods,
    types,
    can,
}: {
    methods: Method[];
    types: TypeOption[];
    can: { create: boolean };
}) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Method | null>(null);
    const [archiving, setArchiving] = useState<Method | null>(null);

    const active = methods.filter((method) => method.is_active);

    return (
        <>
            <Head title={t('payout.index.title')} />

            <PageContainer>
                <PageHeader
                    title={t('payout.index.title')}
                    description={t('payout.index.description')}
                    actions={
                        can.create && (
                            <Button size="sm" onClick={() => setCreating(true)}>
                                {t('payout.index.add')}
                            </Button>
                        )
                    }
                />

                {active.length === 0 ? (
                    <EmptyState
                        icon={CreditCard}
                        title={t('payout.index.empty_title')}
                        description={t('payout.index.empty_description')}
                    />
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2">
                        {active.map((method) => (
                            <li
                                key={method.id}
                                className="border-border bg-card space-y-3 rounded-lg border p-4"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="truncate font-medium">
                                            {method.label}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {method.type_label} ·{' '}
                                            {method.masked_number}
                                        </div>
                                        {method.bank_name && (
                                            <div className="text-muted-foreground text-xs">
                                                {method.bank_name}
                                                {method.branch_name
                                                    ? ` · ${method.branch_name}`
                                                    : ''}
                                            </div>
                                        )}
                                    </div>
                                    {method.is_default && (
                                        <StatusPill
                                            tone="success"
                                            label={t('payout.index.default')}
                                        />
                                    )}
                                </div>

                                <p className="text-muted-foreground text-xs">
                                    {method.verified_at
                                        ? t('payout.index.verified')
                                        : t('payout.index.not_verified')}
                                </p>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditing(method)}
                                    >
                                        {t('payout.index.edit')}
                                    </Button>
                                    {!method.is_default && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    PayoutMethodController.setDefault.url(
                                                        method.id,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('payout.index.make_default')}
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setArchiving(method)}
                                    >
                                        {t('payout.index.archive')}
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </PageContainer>

            <PayoutMethodDialog
                open={creating}
                onOpenChange={setCreating}
                types={types}
            />

            <PayoutMethodDialog
                key={editing?.id ?? 'editing-none'}
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) setEditing(null);
                }}
                types={types}
                method={editing ?? undefined}
            />

            <ArchivePayoutMethodDialog
                open={archiving !== null}
                onOpenChange={(open) => {
                    if (!open) setArchiving(null);
                }}
                method={archiving}
            />
        </>
    );
}

ErpPayoutMethodsIndex.layout = {
    breadcrumbs: [{ title: 'payout.nav.payout_methods', href: index() }],
};

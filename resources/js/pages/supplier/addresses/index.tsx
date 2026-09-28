import { Form, Head } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { useState } from 'react';
import AddressController from '@/actions/App/Http/Controllers/Supplier/AddressController';
import ArchiveAddressDialog from '@/components/address-book/archive-address-dialog';
import AddressDialog from '@/components/address-book/address-dialog';
import type { AddressRow } from '@/components/address-book/types';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { children, divisions } from '@/routes/supplier/locations';

export default function SupplierAddressesIndex({
    addresses,
    types,
}: {
    addresses: AddressRow[];
    types: string[];
}) {
    const { t, locale } = useTranslation();
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<AddressRow | null>(null);
    const [archiving, setArchiving] = useState<AddressRow | null>(null);

    const active = addresses.filter((address) => address.is_active);

    return (
        <>
            <Head title={t('address.supplier.title')} />

            <PageContainer>
                <PageHeader
                    title={t('address.supplier.title')}
                    description={t('address.supplier.description')}
                    actions={
                        <Button size="sm" onClick={() => setCreating(true)}>
                            {t('address.actions.add')}
                        </Button>
                    }
                />

                {active.length === 0 ? (
                    <EmptyState
                        icon={MapPin}
                        title={t('address.supplier.empty_title')}
                        description={t('address.supplier.empty_description')}
                    />
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2">
                        {active.map((address) => (
                            <li
                                key={address.id}
                                className="border-border bg-card space-y-3 rounded-lg border p-4"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="truncate font-medium">
                                            {address.contact_name}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {t(`address.types.${address.type}`)}{' '}
                                            · {address.contact_mobile}
                                        </div>
                                    </div>
                                    {address.is_default && (
                                        <StatusPill
                                            tone="success"
                                            label={t('address.status.default')}
                                        />
                                    )}
                                </div>

                                <p className="text-muted-foreground text-xs">
                                    {[
                                        address.detailed_address,
                                        address.location_snapshot.upazila?.[
                                            locale === 'bn' ? 'bn' : 'en'
                                        ],
                                        address.location_snapshot.district?.[
                                            locale === 'bn' ? 'bn' : 'en'
                                        ],
                                        address.location_snapshot.division?.[
                                            locale === 'bn' ? 'bn' : 'en'
                                        ],
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                </p>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditing(address)}
                                    >
                                        {t('address.actions.edit')}
                                    </Button>

                                    {!address.is_default && (
                                        <Form
                                            {...AddressController.setDefault.form(
                                                address.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {t(
                                                        'address.actions.set_default',
                                                    )}
                                                </Button>
                                            )}
                                        </Form>
                                    )}

                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setArchiving(address)}
                                    >
                                        {t('address.actions.archive')}
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </PageContainer>

            <AddressDialog
                open={creating}
                onOpenChange={setCreating}
                types={types}
                form={AddressController.store.form()}
                divisionsUrl={divisions.url()}
                childrenUrl={(parentType, parentSourceId) =>
                    children.url([parentType, parentSourceId])
                }
            />

            {editing && (
                <AddressDialog
                    key={editing.id}
                    open={editing !== null}
                    onOpenChange={(open) => {
                        if (!open) setEditing(null);
                    }}
                    types={types}
                    address={editing}
                    form={AddressController.update.form(editing.id)}
                    divisionsUrl={divisions.url()}
                    childrenUrl={(parentType, parentSourceId) =>
                        children.url([parentType, parentSourceId])
                    }
                />
            )}

            <ArchiveAddressDialog
                open={archiving !== null}
                onOpenChange={(open) => {
                    if (!open) setArchiving(null);
                }}
                address={archiving}
                form={
                    archiving
                        ? AddressController.archive.form(archiving.id)
                        : null
                }
            />
        </>
    );
}

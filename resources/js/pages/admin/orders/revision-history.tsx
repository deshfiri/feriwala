import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import type { OrderRevisionEntry } from '@/pages/admin/orders/editable-order-details';

/**
 * Every revision of the order, oldest first, with the current version marked
 * (D38): who made it, when, why, what changed field by field, and what it
 * meant for payment and courier. With none, the order is as it was placed.
 */
export default function RevisionHistory({
    history,
}: {
    history: OrderRevisionEntry[];
}) {
    const { t, locale } = useTranslation();

    const fieldLabel = (field: string) => {
        const [group, key] = field.includes('.')
            ? field.split('.', 2)
            : [null, field];
        const name = t(`orders.revision.fields.${key}`);

        return group ? `${t(`orders.revision.${group}`)} · ${name}` : name;
    };

    return (
        <SectionCard title={t('orders.revision.history')}>
            {history.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {t('orders.revision.history_empty')}
                </p>
            ) : (
                <ol className="space-y-4">
                    {history.map((entry) => (
                        <li key={entry.id} className="rounded-lg border p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {t('orders.revision.revision_number', {
                                        number: String(entry.number),
                                    })}
                                </span>
                                <StatusPill
                                    tone={
                                        entry.is_current ? 'success' : 'neutral'
                                    }
                                    label={
                                        entry.is_current
                                            ? t('orders.revision.current')
                                            : entry.status_label
                                    }
                                />
                                <span className="text-muted-foreground text-xs">
                                    {entry.applied_at
                                        ? new Date(
                                              entry.applied_at,
                                          ).toLocaleString(locale)
                                        : '—'}
                                    {entry.actor
                                        ? ` · ${t('orders.revision.by', { name: entry.actor })}`
                                        : ''}
                                </span>
                            </div>

                            <p className="mt-2 text-sm">{entry.reason}</p>

                            {entry.internal_note && (
                                <p className="text-muted-foreground mt-1 text-xs">
                                    {t('orders.revision.internal')}:{' '}
                                    {entry.internal_note}
                                </p>
                            )}

                            {entry.changes.length > 0 && (
                                <div className="mt-3 overflow-x-auto">
                                    <table className="w-full min-w-[28rem] text-left text-xs">
                                        <thead className="text-muted-foreground">
                                            <tr>
                                                <th className="py-1 pr-3 font-medium" />
                                                <th className="py-1 pr-3 font-medium">
                                                    {t(
                                                        'orders.revision.before',
                                                    )}
                                                </th>
                                                <th className="py-1 font-medium">
                                                    {t('orders.revision.after')}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {entry.changes.map((change) => (
                                                <tr
                                                    key={change.field}
                                                    className="border-t align-top"
                                                >
                                                    <td className="py-1 pr-3 font-medium">
                                                        {fieldLabel(
                                                            change.field,
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground py-1 pr-3 break-words">
                                                        {change.before ??
                                                            t(
                                                                'orders.revision.empty_value',
                                                            )}
                                                    </td>
                                                    <td className="py-1 break-words">
                                                        {change.after ??
                                                            t(
                                                                'orders.revision.empty_value',
                                                            )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            <dl className="text-muted-foreground mt-3 grid gap-1 text-xs sm:grid-cols-2">
                                <div>
                                    <dt className="inline">
                                        {t('orders.revision.payment')}:{' '}
                                    </dt>
                                    <dd className="inline">
                                        {entry.payment_consequence}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="inline">
                                        {t('orders.revision.courier')}:{' '}
                                    </dt>
                                    <dd className="inline">
                                        {entry.courier_consequence}
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    ))}
                </ol>
            )}
        </SectionCard>
    );
}

import LinkedRef from '@/components/cases/linked-ref';
import { useTranslation } from '@/hooks/use-translation';
import type { ExchangeOrigin } from '@/types/cases';

/**
 * On an Exchange Order: which exchange sent it and which Order it replaces goods
 * of (D35). Nothing is shown for any other Order, and a reference the reader may
 * not open stays plain text.
 */
export default function ExchangeOriginNotice({
    origin,
}: {
    origin?: ExchangeOrigin | null;
}) {
    const { t } = useTranslation();

    if (!origin) {
        return null;
    }

    return (
        <div
            role="note"
            className="bg-surface-subtle flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg border px-4 py-3 text-sm"
        >
            <span>
                {t('cases.facts.sent_by_case')}:{' '}
                <LinkedRef item={origin.case} />
            </span>
            {origin.original_order && (
                <span>
                    {t('cases.facts.exchange_of')}:{' '}
                    <LinkedRef item={origin.original_order} />
                </span>
            )}
        </div>
    );
}

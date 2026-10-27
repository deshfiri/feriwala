import { Form, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { returnRefundTone, returnStatusTone } from '@/lib/order-return';
import WebsiteOrderController from '@/actions/App/Http/Controllers/Erp/WebsiteOrderController';
import { create as createCase } from '@/routes/cases';
import type {
    WebsiteOrderReturn,
    WebsiteOrderReturnable,
} from '@/types/website-order';

/**
 * Returns on one of a partner's website orders (§18.2, P6-12, D35).
 *
 * What can still come back and until when, every return asked for with where
 * it stands and where the money is, and — for the owner — withdrawing one before
 * goods arrive. Asking for a return or an exchange is its own page, where the
 * estimate and the evidence belong. Everything shown is decided on the server;
 * this only displays it and sends requests.
 */
export default function WebsiteOrderReturns({
    websiteId,
    orderId,
    returnable,
    returns,
    canRequest,
}: {
    websiteId: string;
    orderId: string;
    returnable: WebsiteOrderReturnable;
    returns: WebsiteOrderReturn[];
    reasons?: string[];
    canRequest: boolean;
}) {
    const { t, locale } = useTranslation();

    const when = (value: string) => new Date(value).toLocaleString(locale);

    return (
        <SectionCard
            title={t('website.orders.returns.title')}
            description={
                returnable.eligible && returnable.window_closes_at
                    ? t('website.orders.returns.window', {
                          time: when(returnable.window_closes_at),
                      })
                    : t(
                          `website.orders.returns.refusals.${returnable.refusal ?? 'order_not_returnable'}`,
                      )
            }
        >
            <div className="space-y-4">
                {returns.length === 0 && (
                    <p className="text-muted-foreground text-sm">
                        {t('website.orders.returns.none')}
                    </p>
                )}
                {returns.map((item) => (
                    <article
                        key={item.id}
                        className="border-border space-y-3 rounded-lg border p-3"
                        aria-label={item.reference}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="min-w-0">
                                <div className="font-mono text-sm font-medium">
                                    {item.reference}
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    {t(`returns.reasons.${item.reason}`)} ·{' '}
                                    {when(item.requested_at)}
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusPill
                                    tone={returnStatusTone(item.status)}
                                    label={t(`returns.statuses.${item.status}`)}
                                />
                                <StatusPill
                                    tone={returnRefundTone(item.refund.state)}
                                    label={t(
                                        `returns.refund_states.${item.refund.state}`,
                                    )}
                                />
                            </div>
                        </div>

                        <ul className="divide-border divide-y text-sm">
                            {item.lines.map((line) => (
                                <li
                                    key={line.id}
                                    className="flex flex-wrap justify-between gap-2 py-1.5"
                                >
                                    <span className="min-w-0 break-words">
                                        {line.name}{' '}
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {line.sku}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {t(
                                            'website.orders.returns.quantities',
                                            {
                                                asked: String(line.quantity),
                                                approved:
                                                    line.approved_quantity ===
                                                    null
                                                        ? '—'
                                                        : String(
                                                              line.approved_quantity,
                                                          ),
                                                received: String(
                                                    line.received_quantity,
                                                ),
                                            },
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>

                        {item.refund.amount && (
                            <div className="flex justify-between text-sm">
                                <span className="text-muted-foreground">
                                    {t('website.orders.returns.refund_amount')}
                                </span>
                                <MoneyAmount
                                    amount={item.refund.amount}
                                    direction="credit"
                                />
                            </div>
                        )}

                        {item.decision_note && (
                            <p className="text-sm">
                                <span className="text-muted-foreground">
                                    {t('website.orders.returns.decision_note')}
                                    :{' '}
                                </span>
                                {item.decision_note}
                            </p>
                        )}

                        <ol className="text-muted-foreground space-y-1 text-xs">
                            {item.timeline.map((entry, index) => (
                                <li key={`${entry.status}-${index}`}>
                                    {when(entry.at)} — {entry.note}
                                </li>
                            ))}
                        </ol>

                        {item.can_cancel && (
                            <Form
                                {...WebsiteOrderController.cancelReturn.form([
                                    websiteId,
                                    orderId,
                                    item.id,
                                ])}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                'website.orders.returns.withdraw',
                                            )}
                                        </Button>
                                        <InputError message={errors.return} />
                                    </>
                                )}
                            </Form>
                        )}
                    </article>
                ))}
                {canRequest && (
                    <Button asChild size="sm" variant="outline">
                        <Link href={createCase(orderId)}>
                            {t('cases.partner.request')}
                        </Link>
                    </Button>
                )}
            </div>
        </SectionCard>
    );
}

import MoneyAmount from '@/components/money-amount';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';

/**
 * How a payment is converted for an international gateway (D4, amended): the
 * currency the payer will be charged in, and the administrator's rate in Taka
 * per unit. `charge` is the converted figure when the page knows the amount.
 *
 * Every figure is the server's. Nothing here converts anything.
 */
export type GatewayFx = {
    currency: string;
    rate: string;
    charge: Money | null;
};

export default function GatewayFxNote({ fx }: { fx?: GatewayFx | null }) {
    const { t } = useTranslation();

    if (!fx) {
        return null;
    }

    return (
        <span
            className="text-muted-foreground mt-0.5 block text-xs font-normal"
            data-test="gateway-fx-note"
        >
            {fx.charge ? (
                <>
                    {t('gateways.fx.charged_as')}{' '}
                    <MoneyAmount amount={fx.charge} />
                    {' · '}
                </>
            ) : (
                <>
                    {t('gateways.fx.charged_in', { currency: fx.currency })}
                    {' · '}
                </>
            )}
            {t('gateways.fx.rate', { currency: fx.currency, rate: fx.rate })}
        </span>
    );
}

import GatewayFxNote, { type GatewayFx } from '@/components/gateway-fx-note';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Money } from '@/lib/money';

export type PaymentMethodChoice = {
    name: string;
    label: string;
    /** Set when the gateway is charged in another currency at the administrator's rate. */
    fx?: GatewayFx | null;
    /** The wallet's spendable balance, as the server worked it out. Gateways carry none. */
    balance?: Money | null;
    /** False for a wallet that cannot cover the payment; it is shown but cannot be chosen. */
    sufficient?: boolean;
};

/**
 * One way to pay: a gateway, or the wallet with what it can spend.
 *
 * A wallet that cannot cover the payment stays on the screen, greyed out and
 * saying so in words, rather than disappearing — the person is told where it is
 * and why it cannot be used, and nothing here compares an amount in the browser.
 */
export default function PaymentMethodOption({
    option,
    selected,
    onSelect,
}: {
    option: PaymentMethodChoice;
    selected: boolean;
    onSelect: () => void;
}) {
    const { t } = useTranslation();
    const usable = option.sufficient !== false;
    const isWallet = option.balance !== undefined && option.balance !== null;

    return (
        <label
            className={cn(
                'flex items-center gap-3 rounded-lg border p-3 text-sm transition-colors',
                usable ? 'cursor-pointer' : 'cursor-not-allowed opacity-60',
                selected && usable
                    ? 'border-brand bg-brand-subtle'
                    : 'border-input',
                usable && !selected && 'hover:bg-muted',
            )}
        >
            <input
                type="radio"
                name="payment_method"
                value={option.name}
                checked={selected && usable}
                disabled={!usable}
                onChange={onSelect}
                className="accent-brand"
            />
            <span className="min-w-0">
                <span className="font-medium">{option.label}</span>
                <GatewayFxNote fx={option.fx} />
                {isWallet && option.balance && (
                    <span className="text-muted-foreground block text-xs">
                        {t('wallet.pay.available', {
                            amount: option.balance.formatted,
                        })}
                    </span>
                )}
                {!usable && (
                    <span className="text-danger block text-xs">
                        {t(
                            option.balance
                                ? 'wallet.pay.insufficient'
                                : 'wallet.pay.no_wallet',
                        )}
                    </span>
                )}
            </span>
        </label>
    );
}

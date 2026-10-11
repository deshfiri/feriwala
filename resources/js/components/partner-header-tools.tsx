import { Link, usePage } from '@inertiajs/react';
import { Eye, EyeOff, ShoppingCart } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import MoneyAmount from '@/components/money-amount';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { show as cartShow } from '@/routes/wholesale/cart';
import { quickView, show as walletShow } from '@/routes/wallet';

/** How long the revealed balance stays on screen before it hides itself. */
export const BALANCE_REVEAL_MS = 4000;

/**
 * The Partner's two header shortcuts: the wholesale cart, and a quick look at
 * the wallet balance. Neither is drawn for anyone the server did not give an
 * account to, and each only where that facility is real for the account.
 */
export default function PartnerHeaderTools() {
    const { account } = usePage().props;

    if (!account) {
        return null;
    }

    return (
        <>
            {account.allowsWholesale && <CartShortcut />}
            {account.status === 'active' && <WalletEye />}
        </>
    );
}

/**
 * The cart's count comes from the server with every page (`wholesaleCartLines`);
 * nothing here counts anything.
 */
function CartShortcut() {
    const { t } = useTranslation();
    const { account } = usePage().props;
    const lines = account?.wholesaleCartLines ?? 0;
    const label =
        lines > 0
            ? t('header.cart.with_items', { count: lines })
            : t('header.cart.empty');

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    asChild
                    variant="ghost"
                    size="icon"
                    className="text-muted-foreground hover:text-foreground relative size-9"
                >
                    <Link
                        href={cartShow()}
                        aria-label={label}
                        data-test="header-cart"
                    >
                        <ShoppingCart className="size-4" aria-hidden="true" />
                        {lines > 0 && (
                            <span
                                aria-hidden="true"
                                data-test="header-cart-count"
                                className="bg-brand text-brand-foreground ring-background absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-4 font-semibold ring-2"
                            >
                                {lines > 99 ? '99+' : lines}
                            </span>
                        )}
                    </Link>
                </Button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

/**
 * The wallet's usable balance, hidden until asked for.
 *
 * Nothing about the wallet is in the page: the figure is fetched from the
 * account's own quick-view endpoint when the eye is pressed, held only in this
 * component's state, and dropped again after a few seconds, on a second press,
 * on Escape or when the page changes. It is the server's own Money value,
 * rendered by {@see MoneyAmount}.
 */
function WalletEye() {
    const { t } = useTranslation();
    const [balance, setBalance] = useState<Money | null>(null);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const timer = useRef<number | null>(null);

    const clearTimer = useCallback(() => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }
    }, []);

    const hide = useCallback(() => {
        clearTimer();
        setOpen(false);
        setBalance(null);
        setFailed(false);
    }, [clearTimer]);

    const startTimer = useCallback(() => {
        clearTimer();
        timer.current = window.setTimeout(hide, BALANCE_REVEAL_MS);
    }, [clearTimer, hide]);

    useEffect(() => clearTimer, [clearTimer]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                hide();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open, hide]);

    const reveal = async () => {
        if (open) {
            hide();

            return;
        }

        setOpen(true);
        setLoading(true);
        setFailed(false);

        try {
            const response = await fetch(quickView.url(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error('wallet unavailable');
            }

            const body = (await response.json()) as { usable: Money };

            setBalance(body.usable);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
            startTimer();
        }
    };

    const label = open ? t('header.wallet.hide') : t('header.wallet.show');

    return (
        <div className="relative">
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="text-muted-foreground hover:text-foreground size-9"
                        aria-label={label}
                        aria-expanded={open}
                        aria-controls="header-wallet-panel"
                        onClick={reveal}
                        data-test="header-wallet-eye"
                    >
                        {open ? (
                            <EyeOff className="size-4" aria-hidden="true" />
                        ) : (
                            <Eye className="size-4" aria-hidden="true" />
                        )}
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{label}</TooltipContent>
            </Tooltip>

            {open && (
                <div
                    id="header-wallet-panel"
                    role="status"
                    aria-live="polite"
                    onMouseEnter={clearTimer}
                    onMouseLeave={startTimer}
                    onFocus={clearTimer}
                    onBlur={startTimer}
                    className="bg-popover text-popover-foreground absolute top-full right-0 z-50 mt-2 w-56 rounded-xl border p-3 shadow-md"
                    data-test="header-wallet-panel"
                >
                    <p className="text-muted-foreground text-xs">
                        {t('header.wallet.label')}
                    </p>
                    <div className="mt-1 min-h-7">
                        {loading && (
                            <span className="text-muted-foreground text-sm">
                                {t('header.wallet.loading')}
                            </span>
                        )}
                        {failed && (
                            <span className="text-danger text-sm">
                                {t('header.wallet.failed')}
                            </span>
                        )}
                        {balance && (
                            <MoneyAmount amount={balance} size="large" />
                        )}
                    </div>
                    <Link
                        href={walletShow()}
                        className="text-brand mt-2 inline-block text-xs font-medium underline-offset-4 hover:underline"
                        onClick={hide}
                    >
                        {t('header.wallet.open')}
                    </Link>
                </div>
            )}
        </div>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { KeyRound, ScrollText } from 'lucide-react';
import { useState } from 'react';
import PaymentGatewayController from '@/actions/App/Http/Controllers/Admin/PaymentGatewayController';
import InputError from '@/components/input-error';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import ToggleSwitch from '@/components/toggle-switch';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { index as gatewaysIndex, switches } from '@/routes/admin/gateways';
import { index as paymentsIndex } from '@/routes/admin/payments';
import { settings } from '@/routes/admin';

export type GatewaySwitchRow = {
    name: string;
    label: string;
    is_implemented: boolean;
    is_operational: boolean;
    is_enabled: boolean;
    is_configured: boolean;
    is_available: boolean;
    is_sandbox: boolean | null;
    missing_configuration: string[];
};

type Props = {
    gateways: GatewaySwitchRow[];
    can: { manage: boolean };
};

/**
 * Every payment gateway on one list, each with its own on/off switch.
 *
 * The switch posts to the same `toggle` action the credentials screen uses,
 * so whether a gateway may be switched on — its credentials stored, able to
 * confirm a payment with its provider — is decided by the server. The page
 * only mirrors that: a switch that the server would refuse to turn on is
 * shown disabled with the reason beside it, rather than offered and then
 * refused. A gateway already on can always be switched off.
 */
export default function PaymentSwitches({ gateways, can }: Props) {
    const { t } = useTranslation();
    const [pending, setPending] = useState<string | null>(null);
    const [failure, setFailure] = useState<{
        gateway: string;
        message: string;
    } | null>(null);

    const toggle = (gateway: GatewaySwitchRow, enabled: boolean) => {
        setFailure(null);

        router.put(
            PaymentGatewayController.toggle.url(),
            { gateway: gateway.name, enabled },
            {
                preserveScroll: true,
                onStart: () => setPending(gateway.name),
                onFinish: () => setPending(null),
                onError: (errors) =>
                    setFailure({
                        gateway: gateway.name,
                        message: errors.enabled ?? errors.gateway ?? '',
                    }),
            },
        );
    };

    const state = (
        gateway: GatewaySwitchRow,
    ): { tone: StatusTone; label: string } => {
        if (!gateway.is_operational) {
            return {
                tone: 'neutral',
                label: t('gateways.state.not_implemented'),
            };
        }

        if (!gateway.is_configured) {
            return {
                tone: 'warning',
                label: t('gateways.state.not_configured'),
            };
        }

        if (!gateway.is_enabled) {
            return { tone: 'neutral', label: t('gateways.state.disabled') };
        }

        return { tone: 'success', label: t('gateways.state.available') };
    };

    const reason = (gateway: GatewaySwitchRow): string => {
        if (!gateway.is_operational) {
            return t('gateways.switches.reason.not_implemented');
        }

        if (!gateway.is_configured) {
            return gateway.missing_configuration.length > 0
                ? t('gateways.switches.reason.not_configured', {
                      fields: gateway.missing_configuration
                          .map((key) => t(`gateways.fields.${key}`))
                          .join(', '),
                  })
                : t('gateways.switches.reason.not_configured_plain');
        }

        return gateway.is_sandbox
            ? t('gateways.switches.reason.sandbox')
            : t('gateways.switches.reason.live');
    };

    /*
     * Mirrors what `ConfigureGateway::setEnabled()` accepts: switching on
     * needs a driver that can verify and every credential present; switching
     * off is always allowed for a gateway the server knows how to toggle.
     */
    const isSwitchable = (gateway: GatewaySwitchRow): boolean =>
        can.manage &&
        gateway.is_implemented &&
        (gateway.is_enabled ||
            (gateway.is_operational && gateway.is_configured));

    return (
        <>
            <Head title={t('gateways.switches.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('gateways.switches.title')}
                    description={t('gateways.switches.description')}
                    back={{
                        href: settings(),
                        label: t('settings.hub.title'),
                    }}
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={paymentsIndex()} prefetch>
                                    <ScrollText
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {t('gateways.switches.payment_log')}
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={gatewaysIndex()} prefetch>
                                    <KeyRound
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {t('gateways.switches.credentials')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                {!can.manage && (
                    <Notice tone="info">
                        {t('gateways.switches.read_only')}
                    </Notice>
                )}

                <SectionCard
                    title={t('gateways.switches.list_title')}
                    description={t('gateways.switches.list_description')}
                    contentClassName="p-0"
                >
                    <ul className="divide-border divide-y">
                        {gateways.map((gateway) => (
                            <li
                                key={gateway.name}
                                data-test={`gateway-row-${gateway.name}`}
                                className="flex flex-wrap items-center gap-x-4 gap-y-2 px-6 py-4"
                            >
                                <div className="min-w-0 flex-1 space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-medium">
                                            {gateway.label}
                                        </p>
                                        {/* Label carries the state, never colour alone (§33.9). */}
                                        <StatusPill
                                            tone={state(gateway).tone}
                                            label={state(gateway).label}
                                        />
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        {reason(gateway)}
                                    </p>
                                    {failure?.gateway === gateway.name && (
                                        <InputError message={failure.message} />
                                    )}
                                </div>

                                <ToggleSwitch
                                    checked={gateway.is_enabled}
                                    onCheckedChange={(enabled) =>
                                        toggle(gateway, enabled)
                                    }
                                    label={t('gateways.switches.toggle', {
                                        gateway: gateway.label,
                                    })}
                                    disabled={!isSwitchable(gateway)}
                                    busy={pending === gateway.name}
                                />
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </PageContainer>
        </>
    );
}

PaymentSwitches.layout = {
    breadcrumbs: [
        { title: 'settings.hub.title', href: settings() },
        { title: 'nav.payments', href: switches() },
    ],
};

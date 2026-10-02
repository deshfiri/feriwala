import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import SmsController from '@/actions/App/Http/Controllers/Admin/SmsController';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import ToggleSwitch from '@/components/toggle-switch';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type ProviderRow = {
    name: string;
    is_implemented: boolean;
    is_active: boolean;
    is_configured: boolean;
    required_configuration: string[];
    missing_configuration: string[];
};

type MessageRow = {
    id: string;
    event: string;
    recipient: string;
    status: string;
    status_label: string;
    status_tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
    segments: number;
    attempts: number;
    provider: string | null;
    error: string | null;
    at: string | null;
};

export type SmsEventRow = {
    event: string;
    title: string;
    description: string;
    /** A code someone must type in: switching it off blocks their flow. */
    one_time_code: boolean;
    enabled: boolean;
    sent: number;
};

type Props = {
    settings: { enabled: boolean; provider: string; can_send: boolean };
    providers: ProviderRow[];
    messages: MessageRow[];
    events: SmsEventRow[];
    can: { manage: boolean };
};

/**
 * SMS settings (§30).
 *
 * The global switch and the provider are one form, because they are one
 * question: can a message go out right now, and through whom. Every provider
 * §30.1 names is listed including the unbuilt ones — an absence explains
 * nothing, a row saying "not built yet" explains everything.
 */
export default function AdminSms({
    settings,
    providers,
    messages,
    events,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [pending, setPending] = useState<string | null>(null);
    const [failure, setFailure] = useState<{
        event: string;
        message: string;
    } | null>(null);

    /** An event's own name for the history list, falling back to its key. */
    const eventTitle = (event: string) =>
        events.find((row) => row.event === event)?.title ?? event;

    const toggleEvent = (row: SmsEventRow, enabled: boolean) => {
        /*
         * Switching a one-time code off stops people verifying their mobile
         * or confirming a cash order — asked once more, in words, first.
         */
        if (
            !enabled &&
            row.one_time_code &&
            !window.confirm(
                t('sms.event_switch.confirm_code_off', { event: row.title }),
            )
        ) {
            return;
        }

        setFailure(null);

        router.put(
            SmsController.toggleEvent.url(),
            { event: row.event, enabled },
            {
                preserveScroll: true,
                onStart: () => setPending(row.event),
                onFinish: () => setPending(null),
                onError: (errors) =>
                    setFailure({
                        event: row.event,
                        message: errors.event ?? errors.enabled ?? '',
                    }),
            },
        );
    };

    return (
        <>
            <Head title={t('sms.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('sms.title')}
                    description={t('sms.description')}
                />

                <SectionCard
                    title={t('sms.switch.title')}
                    description={t('sms.switch.description')}
                >
                    {!settings.enabled && (
                        <p className="text-muted-foreground mb-4 text-sm">
                            {t('sms.switch.disabled_notice')}
                        </p>
                    )}

                    {settings.enabled && !settings.can_send && (
                        <p className="text-danger mb-4 text-sm font-medium">
                            {t('sms.switch.unavailable_notice')}
                        </p>
                    )}

                    {can.manage ? (
                        <Form
                            {...SmsController.update.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="flex items-center gap-3">
                                        {/* A hidden false ahead of the checkbox:
                                            an unchecked box sends nothing, and
                                            "nothing" is not "off". */}
                                        <input
                                            type="hidden"
                                            name="enabled"
                                            value="0"
                                        />
                                        <input
                                            id="sms-enabled"
                                            type="checkbox"
                                            name="enabled"
                                            value="1"
                                            defaultChecked={settings.enabled}
                                            className="accent-brand size-4"
                                        />
                                        <Label htmlFor="sms-enabled">
                                            {t('sms.switch.enabled')}
                                        </Label>
                                    </div>

                                    <div className="grid max-w-sm gap-2">
                                        <Label htmlFor="sms-provider">
                                            {t('sms.providers.choose')}
                                        </Label>
                                        <select
                                            id="sms-provider"
                                            name="provider"
                                            defaultValue={settings.provider}
                                            className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                        >
                                            {providers
                                                .filter(
                                                    (provider) =>
                                                        provider.is_implemented,
                                                )
                                                .map((provider) => (
                                                    <option
                                                        key={provider.name}
                                                        value={provider.name}
                                                    >
                                                        {provider.name}
                                                    </option>
                                                ))}
                                        </select>
                                        <InputError message={errors.provider} />
                                    </div>

                                    <Button type="submit" disabled={processing}>
                                        {t('sms.providers.submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-sm">
                            {settings.enabled
                                ? t('sms.switch.enabled')
                                : t('sms.switch.disabled_notice')}
                        </p>
                    )}
                </SectionCard>

                <SectionCard
                    title={t('sms.providers.title')}
                    description={t('sms.providers.description')}
                    contentClassName="p-0"
                >
                    <ul className="divide-border divide-y text-sm">
                        {providers.map((provider) => (
                            <li key={provider.name} className="px-5 py-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <span className="font-medium">
                                        {provider.name}
                                    </span>

                                    {/* Labelled, never colour alone (§33.9). */}
                                    <StatusPill
                                        tone={
                                            provider.is_active
                                                ? 'success'
                                                : !provider.is_implemented
                                                  ? 'neutral'
                                                  : provider.is_configured
                                                    ? 'info'
                                                    : 'warning'
                                        }
                                        label={t(
                                            provider.is_active
                                                ? 'sms.providers.active'
                                                : !provider.is_implemented
                                                  ? 'sms.providers.not_implemented'
                                                  : provider.is_configured
                                                    ? 'sms.providers.available'
                                                    : 'sms.providers.not_configured',
                                        )}
                                    />
                                </div>

                                {provider.is_implemented && can.manage && (
                                    <Form
                                        {...SmsController.updateCredentials.form()}
                                        options={{ preserveScroll: true }}
                                        resetOnSuccess
                                        className="mt-3 space-y-3"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="provider"
                                                    value={provider.name}
                                                />

                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    {provider.required_configuration.map(
                                                        (key) => (
                                                            <div
                                                                key={key}
                                                                className="grid gap-1.5"
                                                            >
                                                                <Label
                                                                    htmlFor={`${provider.name}-${key}`}
                                                                >
                                                                    {t(
                                                                        `sms.credentials.fields.${key}`,
                                                                    )}
                                                                </Label>
                                                                {/*
                                                                    type=password and no autofill: the
                                                                    stored value is never sent here, so
                                                                    there is nothing for a browser to
                                                                    helpfully put back.
                                                                */}
                                                                <input
                                                                    id={`${provider.name}-${key}`}
                                                                    name={`credentials[${key}]`}
                                                                    type="password"
                                                                    autoComplete="new-password"
                                                                    placeholder={t(
                                                                        provider.missing_configuration.includes(
                                                                            key,
                                                                        )
                                                                            ? 'sms.credentials.missing'
                                                                            : 'sms.credentials.set',
                                                                    )}
                                                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                                                />
                                                            </div>
                                                        ),
                                                    )}
                                                </div>

                                                <p className="text-muted-foreground text-xs">
                                                    {t(
                                                        'sms.credentials.blank_help',
                                                    )}
                                                </p>

                                                <InputError
                                                    message={errors.provider}
                                                />

                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    {t(
                                                        'sms.credentials.submit',
                                                    )}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </li>
                        ))}
                    </ul>
                </SectionCard>

                {/*
                    §30's per-event switch. Each switch posts to the server,
                    which reads it at delivery — so turning an event off also
                    stops its messages already queued. One-time codes are
                    listed but locked on: silencing one would lock people out.
                */}
                <SectionCard
                    title={t('sms.event_switch.title')}
                    description={t('sms.event_switch.description')}
                    contentClassName="p-0"
                >
                    {(!settings.enabled || !can.manage) && (
                        <p className="text-muted-foreground border-b px-5 py-3 text-sm">
                            {!settings.enabled
                                ? t('sms.event_switch.global_off')
                                : t('sms.event_switch.read_only')}
                        </p>
                    )}

                    <ul className="divide-border divide-y text-sm">
                        {events.map((row) => (
                            <li
                                key={row.event}
                                data-test={`sms-event-${row.event}`}
                                className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3"
                            >
                                <div className="min-w-0 flex-1 space-y-0.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-medium">
                                            {row.title}
                                        </p>
                                        <span className="text-muted-foreground text-xs">
                                            {t('sms.event_switch.sent', {
                                                count: row.sent,
                                            })}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        {row.description}
                                    </p>
                                    {row.one_time_code && (
                                        <p className="text-warning text-xs font-medium">
                                            {t('sms.event_switch.code_warning')}
                                        </p>
                                    )}
                                    {failure?.event === row.event && (
                                        <InputError message={failure.message} />
                                    )}
                                </div>

                                <ToggleSwitch
                                    checked={row.enabled}
                                    onCheckedChange={(enabled) =>
                                        toggleEvent(row, enabled)
                                    }
                                    label={t('sms.event_switch.toggle', {
                                        event: row.title,
                                    })}
                                    disabled={!can.manage}
                                    busy={pending === row.event}
                                />
                            </li>
                        ))}
                    </ul>
                </SectionCard>

                {/*
                    §30.2's delivery status, failed-SMS log and history in one
                    list. Recipients are masked: the row holds the number in
                    full so a message can be chased, but a screen full of phone
                    numbers is a screen that leaks them over a shoulder (§42).
                */}
                <SectionCard
                    title={t('sms.history.title')}
                    description={t('sms.history.description')}
                    contentClassName="p-0"
                >
                    {messages.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-4 text-sm">
                            {t('sms.history.empty')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y text-sm">
                            {messages.map((message) => (
                                <li
                                    key={message.id}
                                    className="flex flex-wrap items-start justify-between gap-3 px-5 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {eventTitle(message.event)}
                                            <span className="text-muted-foreground">
                                                {' · '}
                                                {message.recipient}
                                            </span>
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {message.at === null
                                                ? '—'
                                                : new Date(
                                                      message.at,
                                                  ).toLocaleString(locale)}
                                            {' · '}
                                            {t('sms.history.segments', {
                                                count: message.segments,
                                            })}
                                            {message.attempts > 0 &&
                                                ` · ${t('sms.history.attempts', { count: message.attempts })}`}
                                            {message.provider !== null &&
                                                ` · ${message.provider}`}
                                        </p>
                                        {message.error !== null && (
                                            <p className="text-danger text-xs">
                                                {message.error}
                                            </p>
                                        )}
                                    </div>

                                    <StatusPill
                                        tone={message.status_tone}
                                        label={message.status_label}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

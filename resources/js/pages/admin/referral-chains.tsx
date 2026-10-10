import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import ReferralChainController from '@/actions/App/Http/Controllers/Admin/ReferralChainController';
import { show } from '@/routes/admin/referral-chains';
import { index as commissions } from '@/routes/admin/referral-commissions';
import type { ChainAccount } from '@/types/referral';
import ReasonTextarea from '@/components/forms/reason-textarea';

export type ChainView = {
    account: ChainAccount;
    link: {
        attached_via: 'registration' | 'backfill' | 'staff';
        attached_by: string | null;
        reason: string | null;
        locked: boolean;
        since: string;
    } | null;
    upline: (ChainAccount & { level: number })[];
    direct_count: number;
    direct: (ChainAccount & { since: string; locked: boolean })[];
};

type Props = {
    query: string;
    matches: { id: string; name: string; status_label: string }[];
    chain: ChainView | null;
    can_attach: boolean;
};

/**
 * One account's place in the referral hierarchy, for staff (D24, P7-44).
 *
 * Its referrer, its chain upward, its direct referrals. A referrer can be
 * attached or corrected only before a qualifying event has locked it, with a
 * reason, and only by someone holding `referral.edit`.
 */
export default function ReferralChains({
    query,
    matches,
    chain,
    can_attach: canAttach,
}: Props) {
    const { t, locale } = useTranslation();
    const when = (value: string) => new Date(value).toLocaleDateString(locale);

    const accountLink = (account: ChainAccount) => (
        <Link
            href={show({ query: { account: account.id } })}
            className="font-medium underline-offset-4 hover:underline"
        >
            {account.name}
        </Link>
    );

    return (
        <>
            <Head title={t('referral.chains.title')} />

            <PageContainer>
                <PageHeader
                    title={t('referral.chains.title')}
                    description={t('referral.chains.description')}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={commissions()}>
                                {t('referral.commissions.title')}
                            </Link>
                        </Button>
                    }
                />

                <form
                    method="get"
                    action={show().url}
                    className="flex flex-wrap items-end gap-2"
                    role="search"
                >
                    <div className="grid min-w-0 flex-1 gap-1">
                        <Label htmlFor="chain-account">
                            {t('referral.chains.search')}
                        </Label>
                        <Input
                            id="chain-account"
                            name="account"
                            defaultValue={query}
                            required
                        />
                    </div>
                    <Button type="submit" size="sm">
                        {t('referral.chains.find')}
                    </Button>
                </form>

                {query !== '' && chain === null && (
                    <SectionCard title={t('referral.chains.matches')}>
                        {matches.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('referral.chains.none')}
                            </p>
                        ) : (
                            <ul className="divide-border divide-y text-sm">
                                {matches.map((match) => (
                                    <li
                                        key={match.id}
                                        className="flex items-center justify-between gap-2 py-2"
                                    >
                                        <Link
                                            href={show({
                                                query: { account: match.id },
                                            })}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {match.name}
                                        </Link>
                                        <span className="text-muted-foreground text-xs">
                                            {match.status_label}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                )}

                {chain && (
                    <>
                        <SectionCard
                            title={chain.account.name}
                            description={chain.account.status_label}
                        >
                            <div className="space-y-2 text-sm">
                                <p className="font-medium">
                                    {t('referral.chains.referrer')}
                                </p>
                                {chain.link === null ? (
                                    <p className="text-muted-foreground">
                                        {t('referral.chains.no_referrer')}
                                    </p>
                                ) : (
                                    <div className="space-y-1">
                                        {chain.upline[0] &&
                                            accountLink(chain.upline[0])}
                                        <div className="text-muted-foreground text-xs">
                                            {chain.link.attached_by
                                                ? t(
                                                      'referral.chains.attached_by',
                                                      {
                                                          name: chain.link
                                                              .attached_by,
                                                          reason:
                                                              chain.link
                                                                  .reason ?? '',
                                                      },
                                                  )
                                                : t(
                                                      'referral.chains.attached',
                                                      {
                                                          via: t(
                                                              `referral.chains.via.${chain.link.attached_via}`,
                                                          ),
                                                      },
                                                  )}{' '}
                                            · {when(chain.link.since)}
                                        </div>
                                        <StatusPill
                                            tone={
                                                chain.link.locked
                                                    ? 'neutral'
                                                    : 'info'
                                            }
                                            label={
                                                chain.link.locked
                                                    ? t(
                                                          'referral.chains.locked',
                                                      )
                                                    : t(
                                                          'referral.chains.unlocked',
                                                      )
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                        </SectionCard>

                        <SectionCard title={t('referral.chains.upline')}>
                            {chain.upline.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('referral.chains.no_referrer')}
                                </p>
                            ) : (
                                <ol className="divide-border divide-y text-sm">
                                    {chain.upline.map((ancestor) => (
                                        <li
                                            key={ancestor.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                                        >
                                            <span className="text-muted-foreground">
                                                {t('referral.mine.level', {
                                                    level: String(
                                                        ancestor.level,
                                                    ),
                                                })}
                                            </span>
                                            {accountLink(ancestor)}
                                            <span className="text-muted-foreground text-xs">
                                                {ancestor.status_label}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </SectionCard>

                        <SectionCard
                            title={t('referral.chains.direct', {
                                count: String(chain.direct_count),
                            })}
                        >
                            {chain.direct.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('referral.chains.no_direct')}
                                </p>
                            ) : (
                                <ul className="divide-border divide-y text-sm">
                                    {chain.direct.map((referred) => (
                                        <li
                                            key={referred.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                                        >
                                            {accountLink(referred)}
                                            <span className="text-muted-foreground text-xs">
                                                {referred.status_label} ·{' '}
                                                {when(referred.since)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        {canAttach && !chain.link?.locked && (
                            <SectionCard
                                title={t('referral.chains.attach_title')}
                                description={t('referral.chains.attach_hint')}
                            >
                                <Form
                                    {...ReferralChainController.attach.form(
                                        chain.account.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3 sm:grid-cols-2"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="referrer-id">
                                                    {t(
                                                        'referral.chains.referrer_id',
                                                    )}
                                                </Label>
                                                <Input
                                                    id="referrer-id"
                                                    name="referrer"
                                                    minLength={26}
                                                    maxLength={26}
                                                    required
                                                />
                                                <InputError
                                                    message={errors.referrer}
                                                />
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="referrer-reason">
                                                    {t(
                                                        'referral.chains.reason',
                                                    )}
                                                </Label>
                                                <ReasonTextarea
                                                    context="referral"
                                                    id="referrer-reason"
                                                    name="reason"
                                                    minLength={10}
                                                    maxLength={500}
                                                    required
                                                />
                                                <InputError
                                                    message={errors.reason}
                                                />
                                            </div>
                                            <div className="sm:col-span-2">
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    {t(
                                                        'referral.chains.attach',
                                                    )}
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </SectionCard>
                        )}
                    </>
                )}
            </PageContainer>
        </>
    );
}

ReferralChains.layout = {
    breadcrumbs: [{ title: 'nav.referral_chains', href: show() }],
};

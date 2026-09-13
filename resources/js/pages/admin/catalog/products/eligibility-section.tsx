import { router, usePage } from '@inertiajs/react';
import { Plus, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductEligibilityController from '@/actions/App/Http/Controllers/Admin/ProductEligibilityController';
import AlertError from '@/components/alert-error';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type {
    AccountMatch,
    CatalogOption,
    ProductDetail,
    ProductEligibilityState,
} from '@/types';

type Props = {
    product: ProductDetail;
    eligibility: ProductEligibilityState;
    packageOptions: CatalogOption[];
    accountMatches: AccountMatch[] | undefined;
    canEdit: boolean;
};

/**
 * Which partners may see a product (§11.1, §12).
 *
 * Scopes and lists are saved together, as one decision. The warnings state what
 * the server will conclude — "no partner can see this" — rather than leaving an
 * administrator to work out that an empty list under "only selected" means
 * nobody. Deciding eligibility happens on the server for every request a partner
 * makes; this screen only records the rules.
 */
export default function EligibilitySection({
    product,
    eligibility,
    packageOptions,
    accountMatches,
    canEdit,
}: Props) {
    const { t } = useTranslation();
    const page = usePage<{ errors: Record<string, string> }>();
    const errors = page.props.errors ?? {};

    const [packageScope, setPackageScope] = useState(eligibility.package_scope);
    const [packageIds, setPackageIds] = useState<string[]>(
        eligibility.package_ids,
    );
    const [accountScope, setAccountScope] = useState(eligibility.account_scope);
    const [accounts, setAccounts] = useState(eligibility.accounts);
    const [search, setSearch] = useState('');
    const [processing, setProcessing] = useState(false);

    // Re-seeded when the server's answer changes, e.g. after a save.
    useEffect(() => {
        setPackageScope(eligibility.package_scope);
        setPackageIds(eligibility.package_ids);
        setAccountScope(eligibility.account_scope);
        setAccounts(eligibility.accounts);
    }, [eligibility]);

    const togglePackage = (id: string) =>
        setPackageIds((current) =>
            current.includes(id)
                ? current.filter((candidate) => candidate !== id)
                : [...current, id],
        );

    const findAccounts = () =>
        router.reload({
            only: ['account_matches'],
            data: { account_search: search },
        });

    const save = () =>
        router.put(
            ProductEligibilityController.update.url(product.id),
            {
                package_scope: packageScope,
                package_ids: packageIds,
                account_scope: accountScope,
                account_ids: accounts.map((account) => account.id),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    const error = Object.entries(errors)
        .filter(
            ([key]) => key.startsWith('package_') || key.startsWith('account_'),
        )
        .map(([, message]) => message);

    return (
        <SectionCard
            title={t('catalog.eligibility.title')}
            description={t('catalog.eligibility.description')}
        >
            <fieldset disabled={!canEdit} className="space-y-6">
                {error.length > 0 && <AlertError errors={error} />}

                <div className="space-y-3">
                    <h3 className="text-sm font-medium">
                        {t('catalog.eligibility.packages')}
                    </h3>

                    <div className="flex flex-col gap-2 text-sm sm:flex-row sm:gap-6">
                        {(['all', 'selected'] as const).map((scope) => (
                            <label
                                key={scope}
                                className="flex items-center gap-2"
                            >
                                <input
                                    type="radio"
                                    name="package_scope"
                                    value={scope}
                                    checked={packageScope === scope}
                                    onChange={() => setPackageScope(scope)}
                                    className="size-4"
                                />
                                {t(
                                    `catalog.eligibility.package_scope_${scope}`,
                                )}
                            </label>
                        ))}
                    </div>

                    {packageScope === 'selected' && (
                        <>
                            {packageOptions.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t(
                                        'catalog.eligibility.no_package_options',
                                    )}
                                </p>
                            ) : (
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {packageOptions.map((option) => (
                                        <li key={option.value}>
                                            <label className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={packageIds.includes(
                                                        option.value,
                                                    )}
                                                    onChange={() =>
                                                        togglePackage(
                                                            option.value,
                                                        )
                                                    }
                                                    className="size-4"
                                                />
                                                {option.is_available
                                                    ? option.label
                                                    : t(
                                                          'catalog.eligibility.package_inactive',
                                                          {
                                                              name: option.label,
                                                          },
                                                      )}
                                            </label>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {packageIds.length === 0 && (
                                <StatusPill
                                    tone="warning"
                                    label={t(
                                        'catalog.eligibility.no_packages_warning',
                                    )}
                                />
                            )}
                        </>
                    )}
                </div>

                <div className="space-y-3">
                    <h3 className="text-sm font-medium">
                        {t('catalog.eligibility.accounts')}
                    </h3>
                    <p className="text-muted-foreground text-xs">
                        {t('catalog.eligibility.account_scope_help')}
                    </p>

                    <div className="flex flex-col gap-2 text-sm sm:flex-row sm:gap-6">
                        {(['any', 'selected'] as const).map((scope) => (
                            <label
                                key={scope}
                                className="flex items-center gap-2"
                            >
                                <input
                                    type="radio"
                                    name="account_scope"
                                    value={scope}
                                    checked={accountScope === scope}
                                    onChange={() => setAccountScope(scope)}
                                    className="size-4"
                                />
                                {t(
                                    `catalog.eligibility.account_scope_${scope}`,
                                )}
                            </label>
                        ))}
                    </div>

                    {accountScope === 'selected' && (
                        <div className="space-y-3">
                            {accounts.length === 0 ? (
                                <StatusPill
                                    tone="warning"
                                    label={t(
                                        'catalog.eligibility.no_accounts_warning',
                                    )}
                                />
                            ) : (
                                <ul className="flex flex-wrap gap-2">
                                    {accounts.map((account) => (
                                        <li
                                            key={account.id}
                                            className="bg-muted/50 flex items-center gap-1 rounded-md border py-1 pr-1 pl-2.5 text-sm"
                                        >
                                            {account.name}
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-6"
                                                aria-label={`${t('catalog.eligibility.remove')} ${account.name}`}
                                                onClick={() =>
                                                    setAccounts((current) =>
                                                        current.filter(
                                                            (candidate) =>
                                                                candidate.id !==
                                                                account.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                <X className="size-3" />
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <div className="flex max-w-md items-end gap-2">
                                <div className="grid flex-1 gap-1.5">
                                    <Label htmlFor="eligibility-account-search">
                                        {t(
                                            'catalog.eligibility.search_accounts',
                                        )}
                                    </Label>
                                    <Input
                                        id="eligibility-account-search"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                findAccounts();
                                            }
                                        }}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={findAccounts}
                                >
                                    <Search
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('catalog.eligibility.search')}
                                </Button>
                            </div>

                            {accountMatches !== undefined &&
                                (accountMatches.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        {t('catalog.eligibility.no_matches')}
                                    </p>
                                ) : (
                                    <ul className="divide-border max-w-md divide-y rounded-md border">
                                        {accountMatches.map((match) => (
                                            <li
                                                key={match.id}
                                                className="flex items-center justify-between gap-2 px-3 py-2 text-sm"
                                            >
                                                {match.name}
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={accounts.some(
                                                        (account) =>
                                                            account.id ===
                                                            match.id,
                                                    )}
                                                    onClick={() =>
                                                        setAccounts(
                                                            (current) => [
                                                                ...current,
                                                                {
                                                                    id: match.id,
                                                                    name: match.name,
                                                                },
                                                            ],
                                                        )
                                                    }
                                                >
                                                    <Plus
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    {t(
                                                        'catalog.eligibility.add',
                                                    )}
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                ))}
                        </div>
                    )}
                </div>

                {canEdit && (
                    <Button type="button" disabled={processing} onClick={save}>
                        {t('catalog.eligibility.save')}
                    </Button>
                )}
            </fieldset>
        </SectionCard>
    );
}

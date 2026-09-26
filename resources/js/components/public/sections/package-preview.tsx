import { Link } from '@inertiajs/react';
import MoneyAmount from '@/components/money-amount';
import SectionContainer from '@/components/public/section-container';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { CmsCta, CmsPackagePreview } from '@/types';

type PackagePreviewContent = {
    heading: string;
    body?: string | null;
    cta?: CmsCta | null;
};

/**
 * Real, current package data — fetched live by the controller
 * (App\Domain\Cms\Support\PublishedPageReader::publicPackagePreviews) and
 * attached to this section's props, never duplicated into the section's own
 * JSON where a price change could go stale (§34).
 */
export default function PackagePreview({
    content,
    packages,
}: {
    content: PackagePreviewContent;
    packages: CmsPackagePreview[];
}) {
    const { t } = useTranslation();

    return (
        <SectionContainer>
            <h2 className="text-center text-3xl font-semibold tracking-tight text-balance">
                {content.heading}
            </h2>

            {content.body && (
                <p className="text-muted-foreground mx-auto mt-3 max-w-xl text-center text-balance">
                    {content.body}
                </p>
            )}

            {packages.length === 0 ? (
                <p className="text-muted-foreground mt-10 text-center text-sm">
                    {t('public.packages.empty')}
                </p>
            ) : (
                <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {packages.map((pkg) => (
                        <div
                            key={pkg.key}
                            className="bg-card border-border flex flex-col rounded-xl border p-6"
                        >
                            <h3 className="font-semibold">{pkg.name}</h3>
                            {pkg.short_description && (
                                <p className="text-muted-foreground mt-1 text-sm">
                                    {pkg.short_description}
                                </p>
                            )}
                            <div className="mt-4">
                                <MoneyAmount amount={pkg.fee} size="large" />
                                {pkg.validity_days && (
                                    <span className="text-muted-foreground ms-1.5 text-sm">
                                        /{' '}
                                        {t('public.packages.days', {
                                            days: pkg.validity_days,
                                        })}
                                    </span>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {content.cta && (
                <div className="mt-8 text-center">
                    <Button asChild size="lg">
                        <Link href={content.cta.href}>{content.cta.label}</Link>
                    </Button>
                </div>
            )}
        </SectionContainer>
    );
}

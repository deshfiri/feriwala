import { Form, Head, Link } from '@inertiajs/react';
import { FolderTree } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteCategoryController from '@/actions/App/Http/Controllers/Erp/WebsiteCategoryController';
import { index as websitesIndex } from '@/routes/websites';
import { index as websiteProducts } from '@/routes/websites/products';
import type { WebsiteCategoryRow, WebsiteSummary } from '@/types/website';

type Props = {
    website: WebsiteSummary;
    categories: WebsiteCategoryRow[];
};

/**
 * A partner arranging their own shop (§15, P5-4).
 *
 * These are the storefront's own categories. Feriwala's catalogue categories
 * belong to the platform (§11.3) and are not editable here — nor is anything
 * about a product itself.
 */
export default function WebsiteCategories({ website, categories }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('website.categories.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('website.categories.title')}
                    description={`${website.name} · ${t('website.categories.subtitle')}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" variant="outline" asChild>
                                <Link href={websiteProducts(website.id)}>
                                    {t('website.products.title')}
                                </Link>
                            </Button>
                            <Button size="sm" variant="ghost" asChild>
                                <Link href={websitesIndex()}>
                                    {t('website.title')}
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <SectionCard title={t('website.categories.add')}>
                    <Form
                        {...WebsiteCategoryController.store.form(website.id)}
                        options={{ preserveScroll: true }}
                        className="space-y-3"
                        resetOnSuccess
                    >
                        {({ processing, errors }) => (
                            <>
                                <FormField
                                    label={t('website.categories.name')}
                                    error={errors.name}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="name"
                                            maxLength={120}
                                            required
                                        />
                                    )}
                                </FormField>

                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('website.categories.add')}
                                </Button>
                            </>
                        )}
                    </Form>
                </SectionCard>

                {categories.length === 0 ? (
                    <EmptyState
                        icon={FolderTree}
                        title={t('website.categories.empty')}
                        description={t('website.categories.empty_help')}
                    />
                ) : (
                    <SectionCard title={t('website.categories.arrangement')}>
                        <ul className="divide-border divide-y">
                            {categories.map((category) => (
                                <li
                                    key={category.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="truncate text-sm font-medium">
                                            {category.name}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {t('website.categories.count', {
                                                count: String(
                                                    category.products_count,
                                                ),
                                            })}
                                        </div>
                                        {!category.is_active && (
                                            <StatusPill
                                                tone="neutral"
                                                label={t(
                                                    'website.categories.hidden',
                                                )}
                                            />
                                        )}
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        <Form
                                            {...WebsiteCategoryController.update.form(
                                                {
                                                    website: website.id,
                                                    category: category.id,
                                                },
                                            )}
                                            options={{ preserveScroll: true }}
                                            transform={(data) => ({
                                                ...data,
                                                is_active: category.is_active
                                                    ? 0
                                                    : 1,
                                            })}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    {category.is_active
                                                        ? t(
                                                              'website.categories.hide',
                                                          )
                                                        : t(
                                                              'website.categories.show',
                                                          )}
                                                </Button>
                                            )}
                                        </Form>

                                        <Form
                                            {...WebsiteCategoryController.destroy.form(
                                                {
                                                    website: website.id,
                                                    category: category.id,
                                                },
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing, errors }) => (
                                                <div className="space-y-1">
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={processing}
                                                    >
                                                        {t(
                                                            'website.categories.remove',
                                                        )}
                                                    </Button>
                                                    <InputError
                                                        message={errors.website}
                                                    />
                                                </div>
                                            )}
                                        </Form>
                                    </div>
                                </li>
                            ))}
                        </ul>

                        <p className="text-muted-foreground mt-4 text-xs">
                            {t('website.categories.removal_note')}
                        </p>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}

WebsiteCategories.layout = {
    breadcrumbs: [
        {
            title: 'Websites',
            href: websitesIndex(),
        },
    ],
};

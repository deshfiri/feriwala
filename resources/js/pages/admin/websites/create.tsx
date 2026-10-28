import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteController from '@/actions/App/Http/Controllers/Admin/WebsiteController';
import { index } from '@/routes/admin/websites';

type Props = {
    accounts: { id: string; name: string }[];
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Standing a website up for a partner, on their behalf.
 *
 * The server prices it and applies the partner's package limit exactly as it
 * does when the partner asks for themselves; this form only says whose it is,
 * what it is called and where it lives.
 */
export default function AdminWebsiteCreate({ accounts }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('website.admin.create_title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('website.admin.create_title')}
                    description={t('website.admin.create_hint')}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>
                                <ArrowLeft aria-hidden="true" />
                                {t('website.admin_title')}
                            </Link>
                        </Button>
                    }
                />

                <SectionCard title={t('website.admin.create_title')}>
                    <Form
                        {...WebsiteController.store.form()}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <FormField
                                    label={t('website.admin.partner')}
                                    error={errors.account ?? errors.package}
                                    required
                                >
                                    {(field) => (
                                        <select
                                            {...field}
                                            name="account"
                                            required
                                            defaultValue=""
                                            className={controlClass}
                                        >
                                            <option value="" disabled>
                                                {t(
                                                    'website.admin.choose_partner',
                                                )}
                                            </option>
                                            {accounts.map((account) => (
                                                <option
                                                    key={account.id}
                                                    value={account.id}
                                                >
                                                    {account.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </FormField>

                                <FormField
                                    label={t('website.create.name')}
                                    description={t('website.create.name_hint')}
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

                                <FormField
                                    label={t('website.admin.domain')}
                                    description={t(
                                        'website.admin.domain_field_hint',
                                    )}
                                    error={errors.domain}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="domain"
                                            placeholder="myshop.com"
                                            maxLength={253}
                                            required
                                        />
                                    )}
                                </FormField>

                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('website.admin.create_submit')}
                                </Button>
                            </>
                        )}
                    </Form>
                </SectionCard>
            </PageContainer>
        </>
    );
}

AdminWebsiteCreate.layout = {
    breadcrumbs: [
        {
            title: 'nav.partner_websites',
            href: index(),
        },
    ],
};

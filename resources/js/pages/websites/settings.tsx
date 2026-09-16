import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteSettingsController from '@/actions/App/Http/Controllers/Erp/WebsiteSettingsController';
import { show } from '@/routes/websites';
import { index } from '@/routes/websites';
import type { WebsiteDetail } from '@/types/website';

type Props = {
    website: WebsiteDetail;
    themes: { value: string; label: string }[];
    image_limits: { max_bytes: number; accepted: string[] };
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Managing a storefront from the ERP (§16.3, P5-12).
 *
 * Information, branding, contact details and the theme — the list §16.3 gives.
 * There is deliberately **no way to create a product here**: products come from
 * the central catalogue and are selected, never authored by a partner (§12,
 * P5-14).
 */
export default function WebsiteSettings({
    website,
    themes,
    image_limits: imageLimits,
}: Props) {
    const { t } = useTranslation();

    const imageCard = (asset: 'logo' | 'banner', url: string | null) => (
        <SectionCard
            title={t(`website.settings.${asset}`)}
            description={t('website.settings.image_hint', {
                size: String(Math.round(imageLimits.max_bytes / 1024 / 1024)),
            })}
        >
            {url && (
                <img
                    src={url}
                    alt={t(`website.settings.${asset}_alt`, {
                        website: website.name,
                    })}
                    className="border-border mb-4 max-h-32 rounded-lg border object-contain"
                />
            )}

            <Form
                {...WebsiteSettingsController.updateImage.form({
                    website: website.id,
                    asset,
                })}
                options={{ preserveScroll: true }}
                className="space-y-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor={`website-${asset}`}>
                                {t('website.settings.choose_file')}
                            </Label>
                            <Input
                                id={`website-${asset}`}
                                name="image"
                                type="file"
                                accept={imageLimits.accepted.join(',')}
                                required
                            />
                            <InputError
                                message={errors.image ?? errors.website}
                            />
                        </div>

                        <Button type="submit" size="sm" disabled={processing}>
                            {processing && <Spinner />}
                            {t('website.settings.upload')}
                        </Button>
                    </>
                )}
            </Form>

            {url && (
                <Form
                    {...WebsiteSettingsController.destroyImage.form({
                        website: website.id,
                        asset,
                    })}
                    options={{ preserveScroll: true }}
                    className="mt-3"
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={processing}
                        >
                            {t('website.settings.remove')}
                        </Button>
                    )}
                </Form>
            )}
        </SectionCard>
    );

    return (
        <>
            <Head title={t('website.settings.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('website.settings.title')}
                    description={t('website.settings.subtitle')}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={show(website.id)}>
                                <ArrowLeft aria-hidden="true" />
                                {website.name}
                            </Link>
                        </Button>
                    }
                />

                <SectionCard title={t('website.settings.information')}>
                    <Form
                        {...WebsiteSettingsController.update.form(website.id)}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <FormField
                                    label={t('website.create.name')}
                                    error={errors.name}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="name"
                                            defaultValue={website.name}
                                            maxLength={120}
                                            required
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t('website.settings.tagline')}
                                    error={errors.tagline}
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="tagline"
                                            defaultValue={website.tagline ?? ''}
                                            maxLength={160}
                                        />
                                    )}
                                </FormField>

                                <div className="grid gap-2">
                                    <Label htmlFor="website-about">
                                        {t('website.settings.about')}
                                    </Label>
                                    <textarea
                                        id="website-about"
                                        name="about"
                                        rows={4}
                                        maxLength={2000}
                                        defaultValue={website.about ?? ''}
                                        className={controlClass}
                                    />
                                    <InputError message={errors.about} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="website-theme">
                                        {t('website.settings.theme')}
                                    </Label>
                                    <select
                                        id="website-theme"
                                        name="theme"
                                        defaultValue={website.theme}
                                        className={controlClass}
                                    >
                                        {themes.map((theme) => (
                                            <option
                                                key={theme.value}
                                                value={theme.value}
                                            >
                                                {theme.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.theme} />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        label={t('website.settings.primary')}
                                        error={errors.primary_color}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="primary_color"
                                                type="color"
                                                defaultValue={
                                                    website.primary_color
                                                }
                                                required
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('website.settings.secondary')}
                                        error={errors.secondary_color}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="secondary_color"
                                                type="color"
                                                defaultValue={
                                                    website.secondary_color
                                                }
                                                required
                                            />
                                        )}
                                    </FormField>
                                </div>

                                <FormField
                                    label={t('website.settings.contact_email')}
                                    error={errors.contact_email}
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="contact_email"
                                            type="email"
                                            defaultValue={
                                                website.contact.email ?? ''
                                            }
                                            maxLength={255}
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t('website.settings.contact_phone')}
                                    error={errors.contact_phone}
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="contact_phone"
                                            defaultValue={
                                                website.contact.phone ?? ''
                                            }
                                            maxLength={32}
                                        />
                                    )}
                                </FormField>

                                <div className="grid gap-2">
                                    <Label htmlFor="website-contact-address">
                                        {t('website.settings.contact_address')}
                                    </Label>
                                    <textarea
                                        id="website-contact-address"
                                        name="contact_address"
                                        rows={3}
                                        maxLength={500}
                                        defaultValue={
                                            website.contact.address ?? ''
                                        }
                                        className={controlClass}
                                    />
                                    <InputError
                                        message={errors.contact_address}
                                    />
                                </div>

                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('website.settings.save')}
                                </Button>
                            </>
                        )}
                    </Form>
                </SectionCard>

                {imageCard('logo', website.logo_url)}
                {imageCard('banner', website.banner_url)}

                <SectionCard title={t('website.settings.products_title')}>
                    <p className="text-muted-foreground text-sm">
                        {t('website.settings.products_body')}
                    </p>
                </SectionCard>
            </PageContainer>
        </>
    );
}

WebsiteSettings.layout = {
    breadcrumbs: [
        {
            title: 'Websites',
            href: index(),
        },
    ],
};

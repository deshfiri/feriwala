import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import WebsiteImageCard from '@/components/website-image-card';
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
    sms_verification: { enabled: boolean; locked: boolean; effective: boolean };
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
    sms_verification: smsVerification,
}: Props) {
    const { t } = useTranslation();

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

                {(['logo', 'banner'] as const).map((asset) => (
                    <WebsiteImageCard
                        key={asset}
                        asset={asset}
                        websiteName={website.name}
                        url={
                            asset === 'logo'
                                ? website.logo_url
                                : website.banner_url
                        }
                        limits={imageLimits}
                        uploadForm={WebsiteSettingsController.updateImage.form({
                            website: website.id,
                            asset,
                        })}
                        removeForm={WebsiteSettingsController.destroyImage.form(
                            {
                                website: website.id,
                                asset,
                            },
                        )}
                    />
                ))}

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

                                <div className="grid gap-2">
                                    <Label htmlFor="website-banner-link">
                                        {t('website.settings.banner_link')}
                                    </Label>
                                    <Input
                                        id="website-banner-link"
                                        name="banner_link_url"
                                        maxLength={2048}
                                        placeholder="/products  ·  https://"
                                        defaultValue={
                                            website.banner_link_url ?? ''
                                        }
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('website.settings.banner_link_hint')}
                                    </p>
                                    <InputError
                                        message={errors.banner_link_url}
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

                <SectionCard
                    title={t('website.settings.sms_title')}
                    description={t('website.settings.sms_hint')}
                >
                    <Form
                        {...WebsiteSettingsController.updateSmsVerification.form(
                            website.id,
                        )}
                        options={{ preserveScroll: true }}
                        className="space-y-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input type="hidden" name="enabled" value="0" />
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="enabled"
                                        value="1"
                                        defaultChecked={
                                            smsVerification.effective
                                        }
                                        disabled={smsVerification.locked}
                                    />
                                    {t('website.settings.sms_label')}
                                </label>
                                {smsVerification.locked && (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-test="sms-locked"
                                    >
                                        {t('website.settings.sms_locked')}
                                    </p>
                                )}
                                <InputError
                                    message={errors.sms_verification_enabled}
                                />
                                {!smsVerification.locked && (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {t('website.settings.save')}
                                    </Button>
                                )}
                            </>
                        )}
                    </Form>
                </SectionCard>

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
            title: 'nav.websites',
            href: index(),
        },
    ],
};

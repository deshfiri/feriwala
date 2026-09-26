import { Form, Head } from '@inertiajs/react';
import SeoSettingController from '@/actions/App/Http/Controllers/Admin/Cms/SeoSettingController';
import MediaPicker, { type MediaPickerItem } from '@/components/admin/cms/media-picker';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { CmsAdminSeoSetting } from '@/types';

type Props = {
    settings: CmsAdminSeoSetting[];
    media: MediaPickerItem[];
    can: { update: boolean; view_media: boolean; manage_media: boolean };
};

const LOCALE_LABEL: Record<string, string> = { en: 'English', bn: 'বাংলা' };

/**
 * The global, per-locale SEO defaults every page's own override falls back
 * to (§34, Stage 7) — a separate `seo.*` permission set from ordinary page
 * content, per PermissionCatalogue.
 */
export default function CmsSeoIndex({ settings, media, can }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('cms.seo_index.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('cms.seo_index.title')}
                    description={t('cms.seo_index.description')}
                />

                {settings.map((setting) => (
                    <SectionCard
                        key={setting.locale}
                        title={LOCALE_LABEL[setting.locale] ?? setting.locale}
                    >
                        <Form
                            {...SeoSettingController.update.form(
                                setting.locale,
                            )}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor={`title-${setting.locale}`}
                                        >
                                            {t('cms.seo_index.default_title')}
                                        </Label>
                                        <Input
                                            id={`title-${setting.locale}`}
                                            name="default_title"
                                            defaultValue={
                                                setting.default_title ?? ''
                                            }
                                            required
                                        />
                                        <InputError
                                            message={errors.default_title}
                                        />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor={`description-${setting.locale}`}
                                        >
                                            {t(
                                                'cms.seo_index.default_description',
                                            )}
                                        </Label>
                                        <textarea
                                            id={`description-${setting.locale}`}
                                            name="default_description"
                                            rows={2}
                                            defaultValue={
                                                setting.default_description ??
                                                ''
                                            }
                                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                        />
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`org-name-${setting.locale}`}
                                            >
                                                {t(
                                                    'cms.seo_index.organization_name',
                                                )}
                                            </Label>
                                            <Input
                                                id={`org-name-${setting.locale}`}
                                                name="organization_name"
                                                defaultValue={
                                                    setting.organization_name ??
                                                    ''
                                                }
                                                required
                                            />
                                            <InputError
                                                message={
                                                    errors.organization_name
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`org-url-${setting.locale}`}
                                            >
                                                {t(
                                                    'cms.seo_index.organization_url',
                                                )}
                                            </Label>
                                            <Input
                                                id={`org-url-${setting.locale}`}
                                                name="organization_url"
                                                defaultValue={
                                                    setting.organization_url ??
                                                    ''
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors.organization_url
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <MediaPicker
                                            name="default_og_image_id"
                                            label={t(
                                                'cms.seo_index.default_og_image',
                                            )}
                                            value={setting.default_og_image_id}
                                            media={media}
                                            canManage={can.manage_media}
                                        />
                                        <MediaPicker
                                            name="organization_logo_id"
                                            label={t(
                                                'cms.seo_index.organization_logo',
                                            )}
                                            value={setting.organization_logo_id}
                                            media={media}
                                            canManage={can.manage_media}
                                        />
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`robots-${setting.locale}`}
                                            >
                                                {t(
                                                    'cms.seo_index.robots_default',
                                                )}
                                            </Label>
                                            <Input
                                                id={`robots-${setting.locale}`}
                                                name="robots_default"
                                                defaultValue={
                                                    setting.robots_default
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`twitter-${setting.locale}`}
                                            >
                                                {t(
                                                    'cms.seo_index.twitter_handle',
                                                )}
                                            </Label>
                                            <Input
                                                id={`twitter-${setting.locale}`}
                                                name="twitter_handle"
                                                defaultValue={
                                                    setting.twitter_handle ?? ''
                                                }
                                            />
                                        </div>
                                    </div>

                                    {can.update && (
                                        <div className="flex justify-end">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {t('cms.actions.save')}
                                            </Button>
                                        </div>
                                    )}
                                </>
                            )}
                        </Form>
                    </SectionCard>
                ))}
            </PageContainer>
        </>
    );
}

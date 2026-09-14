import { Form, Head, router } from '@inertiajs/react';
import { RotateCcw, Upload } from 'lucide-react';
import BrandingController from '@/actions/App/Http/Controllers/Admin/BrandingController';
import FormField from '@/components/forms/form-field';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

type AssetState = {
    asset: 'logo' | 'favicon';
    url: string;
    is_custom: boolean;
    extensions: string[];
    accept: string;
    max_kb: number;
};

type Props = {
    assets: AssetState[];
};

/**
 * The platform's logo and browser icon.
 *
 * Each image is its own form, because each is its own decision, and each shows
 * exactly what every screen is showing right now — the uploaded file or the
 * shipped default — on both a light and a dark ground, since both themes use it.
 */
export default function AdminBranding({ assets }: Props) {
    const { t } = useTranslation();

    const restore = (asset: AssetState) => {
        if (!window.confirm(t('branding.restore_confirm'))) {
            return;
        }

        router.delete(BrandingController.destroy.url({ asset: asset.asset }), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={t('branding.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('branding.title')}
                    description={t('branding.description')}
                />

                {assets.map((asset) => (
                    <SectionCard
                        key={asset.asset}
                        title={t(`branding.${asset.asset}.title`)}
                        description={t(`branding.${asset.asset}.description`)}
                        actions={
                            <StatusPill
                                tone={asset.is_custom ? 'info' : 'neutral'}
                                label={t(
                                    asset.is_custom
                                        ? 'branding.state_custom'
                                        : 'branding.state_default',
                                )}
                            />
                        }
                    >
                        <div className="space-y-5">
                            <div className="grid gap-3 sm:grid-cols-2">
                                {(['light', 'dark'] as const).map((ground) => (
                                    <div
                                        key={ground}
                                        className={
                                            ground === 'light'
                                                ? 'flex h-24 items-center justify-center rounded-lg border bg-white p-4'
                                                : 'flex h-24 items-center justify-center rounded-lg border bg-neutral-900 p-4'
                                        }
                                    >
                                        <img
                                            src={asset.url}
                                            alt={t(
                                                `branding.${asset.asset}.preview`,
                                            )}
                                            className={
                                                asset.asset === 'logo'
                                                    ? 'max-h-14 max-w-full object-contain'
                                                    : 'size-12 object-contain'
                                            }
                                        />
                                    </div>
                                ))}
                            </div>

                            <Form
                                {...BrandingController.update.form({
                                    asset: asset.asset,
                                })}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="flex flex-col gap-3 sm:flex-row sm:items-end"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <FormField
                                            label={t('branding.file')}
                                            hint={t('branding.help', {
                                                types: asset.extensions
                                                    .map((extension) =>
                                                        extension.toUpperCase(),
                                                    )
                                                    .join(', '),
                                                size: asset.max_kb,
                                            })}
                                            error={errors.file}
                                            className="flex-1"
                                        >
                                            {(field) => (
                                                <Input
                                                    {...field}
                                                    type="file"
                                                    name="file"
                                                    accept={asset.accept}
                                                    required
                                                />
                                            )}
                                        </FormField>

                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <Upload
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t('branding.upload')}
                                        </Button>
                                    </>
                                )}
                            </Form>

                            {asset.is_custom && (
                                <Button
                                    variant="outline"
                                    onClick={() => restore(asset)}
                                >
                                    <RotateCcw
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('branding.restore')}
                                </Button>
                            )}
                        </div>
                    </SectionCard>
                ))}
            </PageContainer>
        </>
    );
}

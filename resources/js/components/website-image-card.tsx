import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

type FormTarget = {
    action: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

type Props = {
    asset: 'logo' | 'banner';
    websiteName: string;
    url: string | null;
    limits: { max_bytes: number; accepted: string[] };
    /** Where the file is posted. Partner and admin each have their own route. */
    uploadForm: FormTarget;
    removeForm: FormTarget;
};

/**
 * A storefront's logo or banner: the current image, an upload, and a remove.
 *
 * Shared by the partner's Website settings and the admin's website page, so both
 * show the same thing; each supplies its own routes and the server authorises
 * them separately (§16.3).
 */
export default function WebsiteImageCard({
    asset,
    websiteName,
    url,
    limits,
    uploadForm,
    removeForm,
}: Props) {
    const { t } = useTranslation();

    return (
        <SectionCard
            title={t(`website.settings.${asset}`)}
            description={t('website.settings.image_hint', {
                size: String(Math.round(limits.max_bytes / 1024 / 1024)),
            })}
        >
            {url && (
                <img
                    src={url}
                    alt={t(`website.settings.${asset}_alt`, {
                        website: websiteName,
                    })}
                    className="border-border mb-4 max-h-32 rounded-lg border object-contain"
                />
            )}

            <Form
                {...uploadForm}
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
                                accept={limits.accepted.join(',')}
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
                    {...removeForm}
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
}

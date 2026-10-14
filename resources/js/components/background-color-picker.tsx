import { Form, router } from '@inertiajs/react';
import { Check, RotateCcw, Save } from 'lucide-react';
import { useState } from 'react';
import BrandingController from '@/actions/App/Http/Controllers/Admin/BrandingController';
import FormField from '@/components/forms/form-field';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { HEX_COLOR_PATTERN } from '@/lib/accent';
import { cn } from '@/lib/utils';

export type BackgroundColorState = {
    /** The light-theme background in use now, `#rrggbb`. */
    color: string;
    default: string;
    is_custom: boolean;
};

/** Light backgrounds offered as one-click presets. The first is the shipped white. */
const BACKGROUND_PRESETS = [
    { name: 'white', hex: '#ffffff' },
    { name: 'warm', hex: '#faf8f5' },
    { name: 'grey', hex: '#f4f4f5' },
    { name: 'blue', hex: '#f3f7fc' },
    { name: 'green', hex: '#f2f8f4' },
    { name: 'rose', hex: '#fcf4f5' },
] as const;

/**
 * Choose the page background behind the panels in the light theme. Cards stay
 * white and dark mode keeps its own canvas.
 *
 * Previewed live before it is saved; the server refuses a colour too dark for
 * the text drawn on it.
 */
export default function BackgroundColorPicker({
    background,
}: {
    background: BackgroundColorState;
}) {
    const { t } = useTranslation();
    const [color, setColor] = useState(background.color);

    const isValid = HEX_COLOR_PATTERN.test(color);
    const previewColor = isValid ? color.toLowerCase() : background.color;
    const isUnchanged = previewColor === background.color.toLowerCase();

    const restore = () => {
        if (!window.confirm(t('branding.background.restore_confirm'))) {
            return;
        }

        router.delete(BrandingController.destroyBackground.url(), {
            preserveScroll: true,
            onSuccess: () => setColor(background.default),
        });
    };

    return (
        <SectionCard
            title={t('branding.background.title')}
            description={t('branding.background.description')}
            actions={
                <StatusPill
                    tone={background.is_custom ? 'info' : 'neutral'}
                    label={t(
                        background.is_custom
                            ? 'branding.background.state_custom'
                            : 'branding.background.state_default',
                    )}
                />
            }
        >
            <Form
                {...BrandingController.updateBackground.form()}
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ errors, processing }) => (
                    <>
                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium">
                                {t('branding.accent.presets')}
                            </legend>
                            <div className="flex flex-wrap gap-2.5">
                                {BACKGROUND_PRESETS.map((preset) => {
                                    const isSelected =
                                        previewColor === preset.hex;

                                    return (
                                        <button
                                            key={preset.hex}
                                            type="button"
                                            onClick={() => setColor(preset.hex)}
                                            aria-pressed={isSelected}
                                            aria-label={t(
                                                `branding.background.names.${preset.name}`,
                                            )}
                                            title={t(
                                                `branding.background.names.${preset.name}`,
                                            )}
                                            style={{
                                                backgroundColor: preset.hex,
                                            }}
                                            className={cn(
                                                'text-foreground border-input flex size-10 items-center justify-center rounded-full border transition-transform hover:scale-105',
                                                isSelected &&
                                                    'ring-foreground ring-offset-card ring-2 ring-offset-2',
                                            )}
                                        >
                                            {isSelected && (
                                                <Check
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </fieldset>

                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                            <div className="grid gap-2">
                                <span className="text-sm font-medium">
                                    {t('branding.accent.custom')}
                                </span>
                                <input
                                    type="color"
                                    value={previewColor}
                                    onChange={(event) =>
                                        setColor(event.target.value)
                                    }
                                    aria-label={t('branding.accent.custom')}
                                    className="border-input bg-card h-(--control-height) w-16 cursor-pointer rounded-lg border p-1"
                                />
                            </div>

                            <FormField
                                label={t('branding.accent.hex')}
                                hint={t('branding.accent.hex_hint')}
                                error={errors.background_color}
                                className="flex-1 sm:max-w-56"
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        name="background_color"
                                        value={color}
                                        onChange={(event) =>
                                            setColor(event.target.value.trim())
                                        }
                                        maxLength={7}
                                        spellCheck={false}
                                        autoComplete="off"
                                        className="font-mono uppercase"
                                        required
                                    />
                                )}
                            </FormField>
                        </div>

                        <div
                            aria-hidden="true"
                            style={{ backgroundColor: previewColor }}
                            className="rounded-lg border p-4"
                        >
                            <div className="bg-card text-card-foreground rounded-xl border p-4 text-sm shadow-sm">
                                {t('branding.background.preview_card')}
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="submit"
                                disabled={processing || !isValid || isUnchanged}
                            >
                                <Save className="size-4" aria-hidden="true" />
                                {t('branding.background.save')}
                            </Button>

                            {background.is_custom && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={restore}
                                >
                                    <RotateCcw
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('branding.background.restore')}
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </Form>
        </SectionCard>
    );
}

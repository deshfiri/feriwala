import { Form, router } from '@inertiajs/react';
import { Check, RotateCcw, Save } from 'lucide-react';
import { useState } from 'react';
import type { CSSProperties } from 'react';
import BrandingController from '@/actions/App/Http/Controllers/Admin/BrandingController';
import FormField from '@/components/forms/form-field';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import {
    ACCENT_PRESETS,
    HEX_COLOR_PATTERN,
    accentPreviewStyle,
} from '@/lib/accent';
import { cn } from '@/lib/utils';

export type ButtonColorState = {
    /** The colour buttons use now, `#rrggbb`: the chosen one, or the accent. */
    color: string;
    is_custom: boolean;
};

/**
 * Choose the colour of buttons, checkboxes and switches (`kind="button"`) or
 * of the current navigation link (`kind="nav"`) across every panel. Until one
 * is chosen they follow the accent colour.
 *
 * Previewed live before it is saved; the server validates the value and picks
 * the label colour for contrast itself.
 */
export default function ButtonColorPicker({
    kind = 'button',
    state: button,
}: {
    kind?: 'button' | 'nav';
    state: ButtonColorState;
}) {
    const { t } = useTranslation();
    const [color, setColor] = useState(button.color);

    const isValid = HEX_COLOR_PATTERN.test(color);
    const previewColor = isValid ? color.toLowerCase() : button.color;
    const isUnchanged =
        button.is_custom && previewColor === button.color.toLowerCase();

    const restore = () => {
        if (!window.confirm(t(`branding.${kind}.restore_confirm`))) {
            return;
        }

        router.delete(
            (kind === 'nav'
                ? BrandingController.destroyNav
                : BrandingController.destroyButton
            ).url(),
            {
                preserveScroll: true,
            },
        );
    };

    return (
        <SectionCard
            title={t(`branding.${kind}.title`)}
            description={t(`branding.${kind}.description`)}
            actions={
                <StatusPill
                    tone={button.is_custom ? 'info' : 'neutral'}
                    label={t(
                        button.is_custom
                            ? `branding.${kind}.state_custom`
                            : `branding.${kind}.state_default`,
                    )}
                />
            }
        >
            <Form
                {...(kind === 'nav'
                    ? BrandingController.updateNav
                    : BrandingController.updateButton
                ).form()}
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
                                {ACCENT_PRESETS.map((preset) => {
                                    const isSelected =
                                        previewColor === preset.hex;

                                    return (
                                        <button
                                            key={preset.hex}
                                            type="button"
                                            onClick={() => setColor(preset.hex)}
                                            aria-pressed={isSelected}
                                            aria-label={t(
                                                `branding.accent.names.${preset.name}`,
                                            )}
                                            title={t(
                                                `branding.accent.names.${preset.name}`,
                                            )}
                                            style={
                                                accentPreviewStyle(
                                                    preset.hex,
                                                ) as CSSProperties
                                            }
                                            className={cn(
                                                'flex size-10 items-center justify-center rounded-full border-2 border-transparent bg-(--brand-base) text-(--brand-on) transition-transform hover:scale-105',
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
                                error={errors[`${kind}_color`]}
                                className="flex-1 sm:max-w-56"
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        name={`${kind}_color`}
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
                            style={
                                accentPreviewStyle(
                                    previewColor,
                                ) as CSSProperties
                            }
                            className="bg-surface-subtle flex flex-wrap items-center gap-3 rounded-lg border p-4"
                        >
                            <span className="inline-flex h-(--control-height) items-center rounded-lg bg-(--brand-base) px-4 text-sm font-medium text-(--brand-on)">
                                {t(`branding.${kind}.preview_primary`)}
                            </span>
                            <span className="bg-card inline-flex h-(--control-height) items-center rounded-lg border px-4 text-sm font-medium">
                                {t(`branding.${kind}.preview_secondary`)}
                            </span>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="submit"
                                disabled={processing || !isValid || isUnchanged}
                            >
                                <Save className="size-4" aria-hidden="true" />
                                {t(`branding.${kind}.save`)}
                            </Button>

                            {button.is_custom && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={restore}
                                >
                                    <RotateCcw
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t(`branding.${kind}.restore`)}
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </Form>
        </SectionCard>
    );
}

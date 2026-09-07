import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Light, dark, or whatever the device says (§33.1).
 *
 * Exactly three options. "System" is not a third theme — it is a standing
 * instruction to follow `prefers-color-scheme`, which is why it keeps working
 * when the operating system changes at sunset without anyone touching this.
 *
 * It is a radio group, not a row of buttons: the choice is one-of-three, and
 * marking it up that way is what gives a keyboard user arrow keys and a screen
 * reader "2 of 3 selected" instead of three unrelated controls.
 */
export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();
    const { t } = useTranslation();

    const tabs: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'light', icon: Sun, label: t('common.appearance.light') },
        { value: 'dark', icon: Moon, label: t('common.appearance.dark') },
        {
            value: 'system',
            icon: Monitor,
            label: t('common.appearance.system'),
        },
    ];

    return (
        <div
            role="radiogroup"
            aria-label={t('common.appearance.label')}
            className={cn(
                'bg-muted inline-flex gap-1 rounded-lg p-1',
                className,
            )}
            {...props}
        >
            {tabs.map(({ value, icon: Icon, label }) => {
                const selected = appearance === value;

                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => updateAppearance(value)}
                        className={cn(
                            'flex items-center rounded-md px-3.5 py-1.5 text-sm transition-colors',
                            selected
                                ? 'bg-card text-foreground shadow-xs'
                                : 'text-muted-foreground hover:bg-card/60 hover:text-foreground',
                        )}
                    >
                        <Icon aria-hidden="true" className="-ml-1 size-4" />
                        <span className="ml-1.5">{label}</span>
                    </button>
                );
            })}
        </div>
    );
}

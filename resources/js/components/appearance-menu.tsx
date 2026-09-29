import { Monitor, Moon, Sun, type LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Light, dark, or whatever the device says — from the header (§33.1).
 *
 * The same three choices, the same hook, and the same stored preference as the
 * appearance settings page; this only saves somebody a trip there. It is a radio
 * group, so the menu announces which of the three is chosen rather than offering
 * three unrelated commands.
 *
 * The trigger shows the theme actually on screen. Both marks are rendered and
 * the `dark` class picks one, so the icon is right before hydration and flips
 * with the device when the choice is System.
 */
export default function AppearanceMenu({ className }: { className?: string }) {
    const { appearance, updateAppearance } = useAppearance();
    const { t } = useTranslation();

    const options: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'light', icon: Sun, label: t('common.appearance.light') },
        { value: 'dark', icon: Moon, label: t('common.appearance.dark') },
        {
            value: 'system',
            icon: Monitor,
            label: t('common.appearance.system'),
        },
    ];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className={cn(
                        'text-muted-foreground hover:text-foreground size-9',
                        className,
                    )}
                    aria-label={t('common.appearance.label')}
                >
                    <Sun aria-hidden="true" className="size-4 dark:hidden" />
                    <Moon
                        aria-hidden="true"
                        className="hidden size-4 dark:block"
                    />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" sideOffset={8} className="w-48">
                <DropdownMenuLabel className="text-muted-foreground text-xs font-medium">
                    {t('common.appearance.label')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />

                <DropdownMenuRadioGroup
                    value={appearance}
                    onValueChange={(value) =>
                        updateAppearance(value as Appearance)
                    }
                >
                    {options.map(({ value, icon: Icon, label }) => (
                        <DropdownMenuRadioItem key={value} value={value}>
                            <Icon aria-hidden="true" />
                            {label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

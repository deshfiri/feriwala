import { router } from '@inertiajs/react';
import { Check, Languages } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/use-translation';
import { update } from '@/routes/locale';
import { cn } from '@/lib/utils';

/**
 * Switches the interface between English and Bangla (decision D6).
 *
 * Language is changed on the server, not in the browser, so the choice is stored
 * against the account and follows the person to their next device and session —
 * and so the very next server-rendered response already comes back translated.
 *
 * Each language is written in its own script: someone looking for Bangla scans
 * for "বাংলা", not for the English word "Bangla".
 */
export default function LanguageSwitcher({
    className,
}: {
    className?: string;
}) {
    const { t, locale, available } = useTranslation();

    if (available.length < 2) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className={cn(
                        'text-muted-foreground hover:text-foreground size-8',
                        className,
                    )}
                    aria-label={t('common.language.switch')}
                >
                    <Languages aria-hidden="true" className="size-4" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" sideOffset={8} className="w-44">
                <DropdownMenuLabel className="text-muted-foreground text-xs font-medium">
                    {t('common.language.label')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />

                {available.map((option) => (
                    <DropdownMenuItem
                        key={option.value}
                        onSelect={() =>
                            router.put(
                                update().url,
                                { locale: option.value },
                                { preserveScroll: true },
                            )
                        }
                        className={cn(
                            'justify-between',
                            option.value === locale && 'font-semibold',
                        )}
                    >
                        {option.label}
                        {option.value === locale && (
                            <Check
                                aria-hidden="true"
                                className="text-brand size-4"
                            />
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

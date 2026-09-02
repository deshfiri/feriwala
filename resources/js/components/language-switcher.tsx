import { router } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
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
                    className={cn('size-8', className)}
                    aria-label={t('common.language.switch')}
                >
                    <Languages className="size-4" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-40">
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
                            <span className="text-brand" aria-hidden="true">
                                ✓
                            </span>
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

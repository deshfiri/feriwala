import { Columns3 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/use-translation';
import type { Column } from '@/types';

/**
 * Lets an operator hide columns they do not need.
 *
 * Different roles read the same table for different reasons — a finance manager
 * wants the money columns, a fulfillment manager wants addresses and courier.
 * Rather than build two tables, let each person keep the one they need.
 *
 * Columns marked `alwaysVisible` are not offered: hiding a row's identifier or
 * its actions leaves a table nobody can act on.
 */
export default function ColumnVisibilityMenu<T>({
    columns,
    hidden,
    onToggle,
}: {
    columns: Column<T>[];
    hidden: Set<string>;
    onToggle: (key: string) => void;
}) {
    const { t } = useTranslation();
    const toggleable = columns.filter((column) => !column.alwaysVisible);

    if (toggleable.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" className="h-8 gap-1.5">
                    <Columns3 className="size-3.5" />
                    <span className="hidden sm:inline">
                        {t('common.table.columns')}
                    </span>
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuLabel>
                    {t('common.table.columns')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />

                {toggleable.map((column) => (
                    <DropdownMenuCheckboxItem
                        key={column.key}
                        checked={!hidden.has(column.key)}
                        onCheckedChange={() => onToggle(column.key)}
                        onSelect={(event) => event.preventDefault()}
                    >
                        {column.header}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

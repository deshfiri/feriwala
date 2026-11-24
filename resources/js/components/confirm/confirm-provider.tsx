import { AlertTriangle } from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type ConfirmOptions = {
    title: string;
    description?: ReactNode;
    confirmLabel: ReactNode;
    cancelLabel: ReactNode;
    /** Red confirm button and a warning mark: the default for anything dangerous. */
    destructive?: boolean;
};

type Ask = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<Ask | null>(null);

/**
 * The one "Are you sure?" dialog for the whole app.
 *
 * Mounted once around every page (app.tsx). Anything dangerous — a delete, a
 * cancellation, money leaving, a switch turned off — asks through
 * {@see useConfirm} or {@see ConfirmButton} and waits for the answer, so every
 * such question looks and behaves the same and nothing fires on one click.
 *
 * Holds no strings of its own: callers pass them already translated, because
 * this sits outside the Inertia page where the translations live.
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
    const [options, setOptions] = useState<ConfirmOptions | null>(null);
    const resolver = useRef<((answer: boolean) => void) | null>(null);

    const ask = useCallback<Ask>(
        (next) =>
            new Promise<boolean>((resolve) => {
                // A question already open is answered "no" before the next one.
                resolver.current?.(false);
                resolver.current = resolve;
                setOptions(next);
            }),
        [],
    );

    const answer = (value: boolean) => {
        resolver.current?.(value);
        resolver.current = null;
        setOptions(null);
    };

    const destructive = options?.destructive ?? true;

    return (
        <ConfirmContext.Provider value={ask}>
            {children}
            <Dialog
                open={options !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        answer(false);
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader className="gap-3 sm:flex-row sm:items-start sm:gap-4">
                        {destructive && (
                            <span
                                aria-hidden="true"
                                className="bg-danger-subtle text-danger mx-auto flex size-10 shrink-0 items-center justify-center rounded-full sm:mx-0"
                            >
                                <AlertTriangle className="size-5" />
                            </span>
                        )}
                        <div className="space-y-1.5">
                            <DialogTitle>{options?.title}</DialogTitle>
                            {options?.description && (
                                <DialogDescription>
                                    {options.description}
                                </DialogDescription>
                            )}
                        </div>
                    </DialogHeader>
                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => answer(false)}
                        >
                            {options?.cancelLabel}
                        </Button>
                        <Button
                            type="button"
                            variant={destructive ? 'destructive' : 'default'}
                            onClick={() => answer(true)}
                            autoFocus
                        >
                            {options?.confirmLabel}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ConfirmContext.Provider>
    );
}

/** The provider's raw question, or null outside it (a component test). */
export function useConfirmContext(): Ask | null {
    return useContext(ConfirmContext);
}

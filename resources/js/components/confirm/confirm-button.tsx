import { useRef, type ComponentProps, type MouseEvent } from 'react';
import { Button } from '@/components/ui/button';
import { useConfirm, type ConfirmRequest } from '@/hooks/use-confirm';

type ButtonProps = ComponentProps<typeof Button>;

/**
 * A button that asks "Are you sure?" before it does anything.
 *
 * A drop-in for `<Button>` on anything dangerous. The first click opens the
 * app's confirmation; only "Confirm" lets the click through — the button's own
 * `onClick` runs, or, for a submit button, its form is submitted exactly as it
 * would have been, with the button's own `name`/`value`. So an Inertia `<Form>`,
 * a `router.delete()` in `onClick`, or a plain form all keep working unchanged.
 *
 * The confirm label defaults to the button's own text (or its aria-label for
 * an icon button), so "Delete" asks to confirm "Delete".
 */
export default function ConfirmButton({
    confirm,
    onClick,
    children,
    ...props
}: ButtonProps & { confirm?: ConfirmRequest }) {
    const ask = useConfirm();
    const button = useRef<HTMLButtonElement>(null);
    const confirmed = useRef(false);

    const click = (event: MouseEvent<HTMLButtonElement>) => {
        if (confirmed.current) {
            confirmed.current = false;
            onClick?.(event);

            return;
        }

        event.preventDefault();
        event.stopPropagation();

        void ask({
            ...confirm,
            confirmLabel:
                confirm?.confirmLabel ??
                (typeof children === 'string'
                    ? children
                    : (props['aria-label'] ?? undefined)),
        }).then((yes) => {
            if (yes && button.current) {
                confirmed.current = true;
                button.current.click();
            }
        });
    };

    return (
        <Button {...props} ref={button} onClick={click}>
            {children}
        </Button>
    );
}

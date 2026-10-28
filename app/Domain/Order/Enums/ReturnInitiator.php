<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * Who opened a case (D35).
 *
 * Stored beside `on_behalf_of` rather than derived from it: a member of staff
 * may open a case on behalf of the Partner, and a customer's request through the
 * storefront is the Partner's request on their customer's behalf. Who *pressed
 * the button* and who the case is *for* are different facts.
 */
enum ReturnInitiator: string
{
    use HasTranslatedLabel;

    /** From the Partner Panel. Never allowed to declare Banij responsible. */
    case Partner = 'partner';

    /** From the Admin Panel, by a person who then states responsibility. */
    case Staff = 'staff';

    /** A customer of the Partner's storefront, through the storefront API. */
    case Customer = 'customer';

    protected static function statusLabelGroup(): string
    {
        return 'return_initiator';
    }
}

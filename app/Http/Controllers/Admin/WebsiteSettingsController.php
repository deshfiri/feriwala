<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\ResolvesWebsiteForStaff;
use App\Http\Controllers\Erp\WebsiteSettingsController as PartnerWebsiteSettingsController;

/**
 * A partner's storefront settings, edited by platform staff on their behalf.
 *
 * The partner's own controller, unchanged: the same fields, the same validation
 * and the same actions, so a name, a colour or the SMS-confirmation switch is
 * held to the rules it would have been had the partner saved it. The only
 * differences are in {@see ResolvesWebsiteForStaff} — the website is found among
 * every website and `website.edit` is required.
 */
class WebsiteSettingsController extends PartnerWebsiteSettingsController
{
    use ResolvesWebsiteForStaff;
}

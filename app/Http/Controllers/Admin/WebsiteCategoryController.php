<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\ResolvesWebsiteForStaff;
use App\Http\Controllers\Erp\WebsiteCategoryController as PartnerWebsiteCategoryController;

/**
 * A partner storefront's own categories, arranged by platform staff on their
 * behalf. The partner's controller, unchanged; see {@see ResolvesWebsiteForStaff}.
 */
class WebsiteCategoryController extends PartnerWebsiteCategoryController
{
    use ResolvesWebsiteForStaff;
}

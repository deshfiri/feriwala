<?php

namespace App\Concerns;

/**
 * Lets one website controller answer on two sets of routes: the partner's own
 * (`websites.*`) and the platform's (`admin.websites.*`).
 *
 * Everything a controller redirects to goes through {@see named()}, and its pages
 * are told which side they are on through {@see scope()}, so a staff member who
 * saves a setting lands back on the admin page, not on one they cannot open.
 * See {@see ResolvesWebsiteForStaff} for the staff side.
 */
trait ScopesWebsiteRoutes
{
    /**
     * What a route name starts with on this side: nothing for the partner,
     * `admin.` for platform staff.
     */
    protected function routePrefix(): string
    {
        return '';
    }

    /**
     * The route name as it is called on this side.
     */
    protected function named(string $route): string
    {
        return $this->routePrefix().$route;
    }

    /**
     * What the page is told so it links, and posts, to this side's routes.
     *
     * @return 'partner'|'admin'
     */
    protected function scope(): string
    {
        return $this->routePrefix() === '' ? 'partner' : 'admin';
    }
}

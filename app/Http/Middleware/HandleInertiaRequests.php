<?php

namespace App\Http\Middleware;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Data\AccountContext;
use App\Domain\Account\StaffAllowance;
use App\Models\User;
use App\Support\Localization\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        protected StaffAllowance $allowance,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            /*
             * One account, shared as a fact rather than a choice (D1).
             *
             * This replaces the starter kit's `currentTeam` plus `teams` pair.
             * There is no list, because a person belongs to one business account
             * and cannot switch; sending a list is what makes a front end build
             * a switcher for it.
             */
            'account' => fn () => $user === null
                ? null
                : AccountContext::forUser($user, $this->allowance),
            'locale' => [
                'current' => App::getLocale(),
                'direction' => Locale::parse(App::getLocale())->direction(),
                'available' => Locale::options(),
            ],
            'translations' => fn () => $this->translations(App::getLocale()),
            'permissions' => fn () => $this->navigationPermissions($user),
        ];
    }

    /**
     * The abilities the navigation gates on.
     *
     * An explicit short list, not the user's whole permission set. Shipping all
     * 161 would put the entire access model in the browser on every page load
     * for the sake of a handful of menu entries — and hiding a link is only a
     * convenience anyway. The policy on the route is what actually refuses.
     *
     * @return array<string, bool>
     */
    protected function navigationPermissions(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return [
            'kyc.view' => $user->can(PermissionCatalogue::name(
                PermissionModule::Kyc,
                PermissionAction::View,
            )),
            'account.view' => $user->can(PermissionCatalogue::name(
                PermissionModule::Account,
                PermissionAction::View,
            )),
        ];
    }

    /**
     * The translation lines for the active locale.
     *
     * Sent as a lazy prop so it is resolved once per full page load rather than on
     * every partial reload. Missing lines fall back to English rather than
     * rendering a raw key at the user (decision D6).
     *
     * @return array<string, mixed>
     */
    protected function translations(string $locale): array
    {
        $fallback = $this->loadTranslations(Locale::default()->value);

        if ($locale === Locale::default()->value) {
            return $fallback;
        }

        return array_replace_recursive($fallback, $this->loadTranslations($locale));
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadTranslations(string $locale): array
    {
        $path = lang_path($locale);

        if (! File::isDirectory($path)) {
            return [];
        }

        $lines = [];

        foreach (File::files($path) as $file) {
            if ($file->getExtension() === 'php') {
                $lines[$file->getFilenameWithoutExtension()] = require $file->getPathname();
            }
        }

        return $lines;
    }
}

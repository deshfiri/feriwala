<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Domain\Account\Actions\ChangeLocale;
use App\Domain\Account\Data\LocaleChange;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Account\ChangeLocaleRequest;
use App\Models\User;
use App\Support\Localization\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The language the person reads the app in. Saved on the account, and applied
 * to every later response unless an `Accept-Language` header overrides it.
 */
class LocaleController extends MobileController
{
    /**
     * Languages and my choice
     *
     * The languages on offer (with their own names) and the one saved for this
     * person.
     *
     * @response array{data: array{locale: string, options: list<array{value: string, label: string}>}}
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['data' => [
            'locale' => Locale::parse($user->locale)->value,
            'options' => Locale::options(),
        ]]);
    }

    /**
     * Change my language
     *
     * Saves the choice on the account (`en` or `bn`). `422` for any other value.
     *
     * @response array{data: array{locale: string, options: list<array{value: string, label: string}>}}
     */
    public function update(ChangeLocaleRequest $request, ChangeLocale $changeLocale): JsonResponse
    {
        $locale = $changeLocale->handle(new LocaleChange(
            locale: Locale::from($request->validated('locale')),
            user: $request->user(),
        ));

        return new JsonResponse(['data' => [
            'locale' => $locale->value,
            'options' => Locale::options(),
        ]]);
    }
}

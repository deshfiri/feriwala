<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Account\Queries\OwnDeletionRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request, OwnDeletionRequest $deletion): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user('web') instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Nobody deletes their own account: they ask. This is their request.
            'deletionRequest' => $deletion->for($request->user('web') ?? abort(403)),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user('web')->fill($request->validated());

        if ($request->user('web')->isDirty('email')) {
            $request->user('web')->email_verified_at = null;
        }

        $request->user('web')->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}

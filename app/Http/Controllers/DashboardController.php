<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The business ERP's home screen.
 *
 * It carries no invitation list, deliberately. Under D1 only someone with no
 * account of their own can accept an invitation, and someone with no account
 * never reaches this page — the §5.4 gate sends them to the invitation itself.
 * A list here could therefore only ever be shown to people who cannot act on it.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard');
    }
}

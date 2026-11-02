<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;

/**
 * Base for the mobile API, v1 (`/api/mobile/v1`).
 *
 * Every mobile controller extends this. It exists so the surface has one place
 * to hold anything shared, and so a controller outside the surface cannot be
 * mistaken for one inside it.
 */
abstract class MobileController extends Controller {}

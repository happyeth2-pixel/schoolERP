<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    /**
     * Everyone gets this page -- no permission required beyond being
     * logged in. What it actually SHOWS is decided client-side, the
     * same way the sidebar decides what to show: by the viewer's own
     * role and permissions, so nobody sees a diagram for a workflow
     * they can't use anyway.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Help/Index');
    }
}

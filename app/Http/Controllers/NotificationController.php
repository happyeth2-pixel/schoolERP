<?php

namespace App\Http\Controllers;

use App\Support\NotificationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            // Named notificationList, not notifications -- HandleInertiaRequests
            // already shares a global `notifications` prop (the header bell's
            // {unreadCount, recent} data) on every page. Reusing that name here
            // would let this page-specific paginated list silently override the
            // bell's shared prop for the layout wrapping this exact page.
            'notificationList' => $request->user()->notifications()->paginate(20)->through(fn ($n) => NotificationPresenter::present($n)),
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}

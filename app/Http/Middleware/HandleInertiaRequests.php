<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'role' => $user?->getRoleNames()->first(),
                'permissions' => $user?->getAllPermissions()->pluck('name'),
                'school' => $user?->school,
                'branch' => $user?->branch,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
            'notifications' => $user ? [
                'unreadCount' => $user->unreadNotifications()->count(),
                'recent' => $user->notifications()->latest()->limit(5)->get()->map(fn ($n) => [
                    'id' => $n->id,
                    'title' => $n->data['title'] ?? '',
                    'body' => $n->data['body'] ?? '',
                    'read_at' => $n->read_at,
                    'created_at' => $n->created_at->diffForHumans(),
                ]),
            ] : null,
        ];
    }
}

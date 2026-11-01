<?php

namespace App\Http\Controllers;

use App\Models\ContactInquiry;
use App\Models\GalleryImage;
use App\Models\SiteAnnouncement;
use App\Models\User;
use App\Notifications\NewContactInquiry;
use App\Support\PhoneNumber;
use App\Support\SiteContentSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The logged-out marketing site: Home, About, Contact. No school scoping -- this deployment serves one school. */
class PublicSiteController extends Controller
{
    /** Shared by every public page: who's signed in (for the nav's Login/Dashboard link) and the one
     * holiday/event banner currently showable, if any (see SiteAnnouncement::scopeCurrentlyShowable). */
    private function sharedProps(Request $request): array
    {
        return [
            'auth' => ['user' => $request->user()],
            'announcement' => SiteAnnouncement::currentlyShowable()->latest()->value('message'),
        ];
    }

    public function home(Request $request): Response
    {
        return Inertia::render('Marketing/Home', [
            ...$this->sharedProps($request),
            'content' => SiteContentSchema::forPage('home'),
            'gallery' => GalleryImage::orderBy('sort_order')->orderBy('id')->get()->map(fn (GalleryImage $i) => [
                'id' => $i->id,
                'url' => $i->url,
                'caption' => $i->caption,
            ]),
        ]);
    }

    public function about(Request $request): Response
    {
        return Inertia::render('Marketing/About', [
            ...$this->sharedProps($request),
            'content' => SiteContentSchema::forPage('about'),
        ]);
    }

    public function contact(Request $request): Response
    {
        return Inertia::render('Marketing/Contact', [
            ...$this->sharedProps($request),
            'content' => SiteContentSchema::forPage('contact'),
        ]);
    }

    public function submitContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $data['phone'] = PhoneNumber::normalize($data['phone']) ?? $data['phone'];

        $inquiry = ContactInquiry::create($data);

        foreach (User::role(['owner', 'branch_admin'])->get() as $recipient) {
            $recipient->notify(new NewContactInquiry($inquiry));
        }

        return back()->with('status', 'contact-sent');
    }
}

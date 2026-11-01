<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesLists;
use App\Models\ContactInquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Messages submitted from the public Contact page -- a simple inbox for the owner/branch admin. */
class ContactInquiryController extends Controller
{
    use PaginatesLists;

    public function index(Request $request): Response
    {
        $request->user()->can('manage-school-settings') || abort(403);

        $paginator = ContactInquiry::latest()->paginate($this->perPage($request));

        return Inertia::render('ContactInquiries/Index', [
            'inquiries' => collect($paginator->items())->map(fn (ContactInquiry $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'phone' => $i->phone,
                'email' => $i->email,
                'message' => $i->message,
                'read' => $i->read_at !== null,
                'created_at' => $i->created_at->diffForHumans(),
            ]),
            'inquiriesMeta' => $this->paginationMeta($paginator),
        ]);
    }

    public function markRead(Request $request, ContactInquiry $inquiry): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);

        if (! $inquiry->read_at) {
            $inquiry->update(['read_at' => now()]);
        }

        return back();
    }
}

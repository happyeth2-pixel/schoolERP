<?php

namespace App\Http\Controllers;

use App\Models\GalleryImage;
use App\Rules\SafeUploadRule;
use App\Support\SafeUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/** The owner/branch admin's own photo gallery for the public website -- shown on the Home page's
    "School Life" section when at least one image exists. */
class GalleryImageController extends Controller
{
    private function authorize(Request $request): void
    {
        $request->user()->can('manage-school-settings') || abort(403);
    }

    public function index(Request $request): Response
    {
        $this->authorize($request);

        return Inertia::render('Gallery/Index', [
            'images' => GalleryImage::orderBy('sort_order')->orderBy('id')->get()->map(fn (GalleryImage $i) => [
                'id' => $i->id,
                'url' => $i->url,
                'caption' => $i->caption,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize($request);

        $validated = $request->validate([
            'photo' => ['required', 'file', 'max:4096', new SafeUploadRule(['jpg', 'png'])],
            'caption' => ['nullable', 'string', 'max:160'],
        ]);

        $path = SafeUpload::storePublicImage($validated['photo'], 'gallery', 1600);
        $nextOrder = (int) GalleryImage::max('sort_order') + 1;

        GalleryImage::create([
            'path' => $path,
            'caption' => $validated['caption'] ?? null,
            'sort_order' => $nextOrder,
        ]);

        return back()->with('success', 'Photo added.');
    }

    public function destroy(Request $request, GalleryImage $image): RedirectResponse
    {
        $this->authorize($request);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return back()->with('success', 'Photo removed.');
    }

    /** Swap sort_order with the image immediately before/after it -- the whole reordering UI this needs. */
    public function move(Request $request, GalleryImage $image): RedirectResponse
    {
        $this->authorize($request);

        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        $neighbor = $direction === 'up'
            ? GalleryImage::where('sort_order', '<', $image->sort_order)->orderByDesc('sort_order')->first()
            : GalleryImage::where('sort_order', '>', $image->sort_order)->orderBy('sort_order')->first();

        if ($neighbor) {
            [$a, $b] = [$image->sort_order, $neighbor->sort_order];
            $image->update(['sort_order' => $b]);
            $neighbor->update(['sort_order' => $a]);
        }

        return back();
    }
}

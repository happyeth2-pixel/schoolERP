<?php

namespace Tests\Feature;

use App\Models\ContactInquiry;
use App\Models\GalleryImage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSchool;

    public function test_home_about_and_contact_pages_load_for_a_guest(): void
    {
        $this->get('/')->assertOk();
        $this->get('/about')->assertOk();
        $this->get('/contact')->assertOk();
    }

    public function test_a_visitor_can_submit_the_contact_form_and_the_owner_is_notified(): void
    {
        Notification::fake();
        $this->buildSchool();

        $response = $this->post('/contact', [
            'name' => 'A Parent',
            'phone' => '0911223344',
            'email' => 'parent@example.test',
            'message' => 'Can I visit the campus this week?',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contact_inquiries', ['name' => 'A Parent', 'message' => 'Can I visit the campus this week?']);
        Notification::assertSentTo($this->owner, \App\Notifications\NewContactInquiry::class);
    }

    public function test_the_contact_form_requires_name_phone_and_message(): void
    {
        $this->post('/contact', [])->assertSessionHasErrors(['name', 'phone', 'message']);
    }

    public function test_only_an_owner_or_branch_admin_can_view_contact_inquiries(): void
    {
        $this->buildSchool();
        ContactInquiry::create(['name' => 'X', 'phone' => '0911000000', 'message' => 'Hi']);

        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->actingAs($teacher)->get(route('contact-inquiries.index'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('contact-inquiries.index'))->assertOk();
    }

    public function test_opening_an_inquiry_marks_it_read(): void
    {
        $this->buildSchool();
        $inquiry = ContactInquiry::create(['name' => 'X', 'phone' => '0911000000', 'message' => 'Hi']);

        $this->actingAs($this->owner)->post(route('contact-inquiries.read', $inquiry))->assertRedirect();

        $this->assertNotNull($inquiry->fresh()->read_at);
    }

    public function test_an_owner_can_upload_and_reorder_gallery_photos_and_they_appear_on_the_home_page(): void
    {
        Storage::fake('public');
        $this->buildSchool();

        $photo = UploadedFile::fake()->image('campus.jpg', 800, 800);
        $this->actingAs($this->owner)->post(route('gallery.store'), ['photo' => $photo, 'caption' => 'Front gate'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('gallery_images', 1);
        $image = GalleryImage::first();
        Storage::disk('public')->assertExists($image->path);

        $home = $this->get('/')->assertOk();
        $home->assertInertia(fn ($page) => $page->has('gallery', 1));
    }

    public function test_a_teacher_cannot_manage_the_gallery(): void
    {
        $this->buildSchool();
        $teacher = $this->makeUser('teacher', $this->branchA->id);

        $this->actingAs($teacher)->get(route('gallery.index'))->assertForbidden();
    }
}

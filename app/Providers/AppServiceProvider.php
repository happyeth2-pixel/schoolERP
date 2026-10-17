<?php

namespace App\Providers;

use App\Models\Complaint;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Policies\ComplaintPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\StudentPolicy;
use App\Listeners\CheckApplicationHealth;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        // Public ID-card verification: generous for a guard scanning cards at a
        // gate, tight enough that nobody can hammer it.
        RateLimiter::for('invitation', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perHour(60)->by($request->ip()),
        ]);

        RateLimiter::for('id-verify', fn (Request $request) => [
            Limit::perMinute(30)->by($request->ip()),
            Limit::perHour(300)->by($request->ip()),
        ]);

        // A password set through the emailed "forgot password" link proves control of the account: record it, and forget
        // any failures counted against it.
        Event::listen(\Illuminate\Auth\Events\PasswordReset::class, function ($event) {
            \App\Models\SecurityEvent::log('password_reset_by_email', $event->user, $event->user->login_phone ?? $event->user->email);
            app(\App\Security\LoginThrottle::class)->clear($event->user);
        });

        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Complaint::class, ComplaintPolicy::class);
        Gate::policy(\App\Models\MarkBatch::class, \App\Policies\MarkBatchPolicy::class);

        // Makes /up actually check the database and cache -- see MONITORING.md.
        Event::listen(DiagnosingHealth::class, CheckApplicationHealth::class);
    }
}

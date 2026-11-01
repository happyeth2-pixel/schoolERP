<?php

namespace App\Providers;

use App\Events\NotificationBroadcast;
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
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        // Previously just Laravel's bare framework default (min 8, nothing else) -- a password could be
        // "password" or the account's own phone number and pass every rule. uncompromised() (a breach-database
        // check via Have I Been Pwned's k-anonymity API) is production-only: it needs a live network call, which
        // would make every test run flaky/slow and fail offline local dev.
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers();

            return $this->app->environment('production') ? $rule->uncompromised() : $rule;
        });

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

        // Every one of the app's ~36 Notification classes writes a row here (all 'database' channel,
        // in-app only -- see each class's own doc comment). Broadcasting from this single model event,
        // rather than editing every Notification class, makes all of them real-time at once: attendance,
        // payments, messages, HR, inventory, discipline, everything. Skipped for the one mail-only
        // notification (account invitations -- a real person needs the one-time link emailed anyway, not
        // a bell) by simply never writing a database row for it in the first place.
        //
        // Wrapped: this push is a nice-to-have on top of the database row that already, unconditionally,
        // succeeded -- the person will still see it next time they load any page either way. If Reverb
        // happens to be down (not started yet, restarting, briefly unreachable), ShouldBroadcastNow's
        // synchronous HTTP call throws, and letting that bubble up would fail the real action that
        // triggered it -- marking attendance, recording a payment, generating a whole grade's bills --
        // over nothing more than a live-update channel having no listener. Logged, not silent, so a
        // genuinely dead Reverb is still visible in the logs rather than invisibly degrading forever.
        // RealtimeBroadcast's circuit breaker keeps a whole BULK operation's worth of notifications from
        // each separately rediscovering (and separately paying the connection cost for) the same outage.
        DatabaseNotification::created(function (DatabaseNotification $notification) {
            if ($notification->notifiable_type !== \App\Models\User::class || \App\Support\RealtimeBroadcast::isDown()) {
                return;
            }

            try {
                event(new NotificationBroadcast($notification));
            } catch (\Throwable $e) {
                \App\Support\RealtimeBroadcast::markDown();
                \Illuminate\Support\Facades\Log::warning('Real-time notification push failed; the notification itself was still saved.', [
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}

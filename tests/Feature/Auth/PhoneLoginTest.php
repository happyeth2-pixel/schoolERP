<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\TemporaryPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Phone number as the sign-in identifier: every everyday way of typing an Ethiopian mobile number is one number,
 * two accounts can never share it, staff are given a temporary password that must be replaced at the first
 * sign-in, and an administrator can reset a password without ever seeing anyone's password.
 */
class PhoneLoginTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function account(string $phone = '0911223344', string $role = 'teacher', string $password = 'secret-pass-1'): User
    {
        $u = User::create([
            'school_id' => $this->school->id, 'branch_id' => $role === 'owner' ? null : $this->branchA->id, 'name' => 'Sara '.$role, 'phone' => $phone, 'login_phone' => $phone,
            'password' => $password, 'status' => 'active',
        ]);
        $u->assignTier($role);

        return $u;
    }

    private function staff(array $over = []): array
    {
        return array_merge(['name' => 'New Teacher', 'phone' => '0922334455', 'title' => 'Teacher', 'role' => 'teacher', 'branch_id' => $this->branchA->id], $over);
    }

    private function temporaryPasswordOf(string $phone = '0922334455'): string
    {
        return Crypt::decryptString(session('credentials')['password']);
    }

    // ------------------------------------------------------------------ the numbers themselves

    public static function everyDayForms(): array
    {
        return [
            '09 local' => ['0911223344', '+251911223344'],
            '07 local' => ['0711223344', '+251711223344'],
            '+2519' => ['+251911223344', '+251911223344'],
            '+2517' => ['+251711223344', '+251711223344'],
            'spaces' => ['0911 22 33 44', '+251911223344'],
            'dashes and brackets' => ['(0911)-22-33-44', '+251911223344'],
            'no zero' => ['911223344', '+251911223344'],
            'country code without plus' => ['251911223344', '+251911223344'],
            'double zero' => ['00251 911 223 344', '+251911223344'],
        ];
    }

    /** @dataProvider everyDayForms */
    public function test_every_everyday_form_of_a_mobile_number_becomes_one_canonical_number(string $typed, string $canonical): void
    {
        $this->assertSame($canonical, PhoneNumber::normalizeMobile($typed));
    }

    public static function notMobileNumbers(): array
    {
        return [['0811223344'], ['091122334'], ['09112233445'], ['+25191122334455'], ['abc'], ['0111234567'], ['+441234567890'], [''], ['+251']];
    }

    /** @dataProvider notMobileNumbers */
    public function test_things_that_are_not_ethiopian_mobile_numbers_are_refused(string $typed): void
    {
        $this->assertNull(PhoneNumber::normalizeMobile($typed));
    }

    // ------------------------------------------------------------------ signing in

    /** @dataProvider everyDayForms */
    public function test_a_person_signs_in_with_the_number_typed_any_everyday_way(string $typed, string $canonical): void
    {
        $user = $this->account($canonical);

        $this->post('/login', ['phone' => $typed, 'password' => 'secret-pass-1'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_and_an_unknown_number_get_the_same_answer(): void
    {
        $this->account('0911223344');
        $message = function (string $phone) {
            $this->post('/login', ['phone' => $phone, 'password' => 'nope']);

            return session('errors')->first('phone');
        };

        $wrong = $message('0911223344');
        $this->assertNotSame('', $wrong);
        $this->assertSame($wrong, $message('0977000000'), 'an unknown number reads exactly like a wrong password');
        $this->assertSame($wrong, $message('hello'), 'so does something that is not a number at all');
        $this->assertGuest();
    }

    public function test_an_account_that_only_has_an_email_can_still_sign_in_with_it_until_it_gets_a_phone(): void
    {
        $u = User::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'name' => 'Old Account', 'email' => 'old@test.test', 'password' => 'secret-pass-1', 'status' => 'active']);
        $u->assignTier('teacher');

        $this->post('/login', ['phone' => 'Old@Test.test', 'password' => 'secret-pass-1'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($u);
    }

    public function test_an_inactive_account_and_a_student_account_cannot_sign_in(): void
    {
        $gone = $this->account('0911000001');
        $gone->update(['status' => 'inactive']);
        $this->post('/login', ['phone' => '0911000001', 'password' => 'secret-pass-1'])->assertSessionHasErrors('phone');
        $this->assertGuest();

        $kid = $this->account('0911000002', 'student');
        $this->post('/login', ['phone' => '0911000002', 'password' => 'secret-pass-1'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_failed_attempts_are_counted_per_number_however_it_is_typed(): void
    {
        $this->account('0911223344');
        foreach (['0911223344', '+251911223344', '911223344', '0911 22 33 44', '251911223344'] as $typed) {
            $this->post('/login', ['phone' => $typed, 'password' => 'nope']);
        }

        $this->post('/login', ['phone' => '0911223344', 'password' => 'secret-pass-1'])->assertSessionHasErrors('phone');
        $this->assertGuest();

        $this->account('0922000000', 'accountant');
        $this->post('/login', ['phone' => '0922000000', 'password' => 'secret-pass-1'])->assertRedirect('/dashboard');   // another person is not held up
    }

    public function test_users_can_log_out(): void
    {
        $user = $this->account();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_two_accounts_can_never_share_a_sign_in_number(): void
    {
        $this->account('0911223344');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->account('+251 911 22 33 44', 'accountant');
    }

    // ------------------------------------------------------------------ creating staff

    public function test_an_administrator_creates_staff_with_a_phone_and_a_temporary_password_and_no_email(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->staff())->assertSessionHasNoErrors()->assertSessionHas('credentials');

        $u = User::where('login_phone', '+251922334455')->firstOrFail();
        $temporary = $this->temporaryPasswordOf();
        $this->assertNull($u->email, 'email is optional');
        $this->assertSame('+251922334455', $u->phone);
        $this->assertTrue($u->must_change_password);
        $this->assertTrue(Hash::check($temporary, $u->password));
        $this->assertMatchesRegularExpression('/^[A-Za-z2-9]{10}$/', $temporary);
        $this->assertNotSame($temporary, $u->getRawOriginal('password'), 'stored hashed, never as typed');
        $this->assertSame('0922 33 44 55', session('credentials')['phone']);
    }

    public function test_the_temporary_password_is_readable_only_in_the_one_hand_over_and_never_in_the_audit_log(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->staff());
        $temporary = $this->temporaryPasswordOf();
        $this->assertStringNotContainsString($temporary, json_encode(session('credentials')), 'the flash holds it encrypted');

        $logs = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString($temporary, $logs);
        $this->assertTrue(AuditLog::where('action', 'account.temporary_password_issued')->exists());
    }

    public function test_temporary_passwords_are_random_and_easy_to_read_out_loud(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $p = TemporaryPassword::make();
            $this->assertMatchesRegularExpression('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z2-9]{10}$/', $p);
            $this->assertDoesNotMatchRegularExpression('/[01OIl]/', $p);
            $seen[$p] = true;
        }
        $this->assertCount(50, $seen);
    }

    public static function goodPhones(): array
    {
        return [['0911223344'], ['0711223344'], ['+251911223344'], ['+251711223344'], ['+251 711 22 33 44']];
    }

    /** @dataProvider goodPhones */
    public function test_valid_ethiopian_mobile_numbers_are_accepted_for_staff(string $phone): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->staff(['phone' => $phone]))->assertSessionHasNoErrors();

        $this->assertSame(1, User::where('login_phone', PhoneNumber::normalizeMobile($phone))->count());
    }

    public static function badPhones(): array
    {
        return [['0811223344'], ['0111234567'], ['09112'], ['abc'], ['+441234567890'], ['']];
    }

    /** @dataProvider badPhones */
    public function test_invalid_numbers_are_refused_for_staff(string $phone): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->staff(['phone' => $phone]))->assertSessionHasErrors('phone');

        $this->assertSame(0, User::where('name', 'New Teacher')->count());
    }

    public function test_the_same_number_typed_differently_cannot_make_a_second_account(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->staff(['phone' => '0911223344']))->assertSessionHasNoErrors();

        foreach (['+251911223344', '911223344', '0911 22 33 44', '00251911223344'] as $typed) {
            $this->actingAs($this->owner)->post('/staff', $this->staff(['phone' => $typed, 'name' => 'Copy']))->assertSessionHasErrors('phone');
        }

        $this->assertSame(1, User::where('phone', '+251911223344')->count());
        $this->assertSame(0, User::where('name', 'Copy')->count());
    }

    public function test_a_number_that_is_already_someones_contact_number_is_refused_too(): void
    {
        $parent = $this->makeUser('parent', null);
        $parent->update(['phone' => '0911998877']);

        $this->actingAs($this->owner)->post('/staff', $this->staff(['phone' => '+251 911 99 88 77']))->assertSessionHasErrors('phone');
    }

    public function test_only_people_who_manage_staff_can_create_accounts(): void
    {
        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->post('/staff', $this->staff())->assertForbidden();
        $this->assertSame(0, User::where('name', 'New Teacher')->count());
    }

    // ------------------------------------------------------------------ first sign-in

    private function createAndSignInAsNewStaff(): array
    {
        $this->actingAs($this->owner)->post('/staff', $this->staff());
        $temporary = $this->temporaryPasswordOf();
        $this->post('/logout');
        $this->flushSession();

        $this->post('/login', ['phone' => '0922 33 44 55', 'password' => $temporary])->assertRedirect('/dashboard');

        return [User::where('login_phone', '+251922334455')->firstOrFail(), $temporary];
    }

    public function test_with_a_temporary_password_only_the_password_change_page_opens(): void
    {
        [$u] = $this->createAndSignInAsNewStaff();

        foreach (['/dashboard', '/students', '/staff', '/attendance', '/profile'] as $path) {
            $this->get($path)->assertRedirect('/password/change');
        }

        $page = $this->get('/password/change')->assertOk();
        $this->assertSame($u->name, $this->props($page)['name']);
    }

    public function test_the_person_can_still_sign_out_from_the_password_change_page(): void
    {
        $this->createAndSignInAsNewStaff();

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_the_new_password_needs_the_current_one_a_confirmation_and_must_be_different(): void
    {
        [, $temporary] = $this->createAndSignInAsNewStaff();

        $this->put('/password/change', ['current_password' => 'wrong', 'password' => 'Brand-New-Pass!77', 'password_confirmation' => 'Brand-New-Pass!77'])->assertSessionHasErrors('current_password');
        $this->put('/password/change', ['current_password' => $temporary, 'password' => 'Brand-New-Pass!77', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->put('/password/change', ['current_password' => $temporary, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->put('/password/change', ['current_password' => $temporary, 'password' => $temporary, 'password_confirmation' => $temporary])->assertSessionHasErrors('password');

        $this->assertTrue(User::where('login_phone', '+251922334455')->first()->must_change_password, 'nothing changed');
    }

    public function test_after_the_change_the_temporary_password_is_dead_and_the_new_one_works(): void
    {
        [$u, $temporary] = $this->createAndSignInAsNewStaff();

        $this->put('/password/change', ['current_password' => $temporary, 'password' => 'Brand-New-Pass!77', 'password_confirmation' => 'Brand-New-Pass!77'])->assertRedirect('/dashboard');

        $u->refresh();
        $this->assertFalse($u->must_change_password);
        $this->assertNotNull($u->password_changed_at);
        $this->assertTrue(Hash::check('Brand-New-Pass!77', $u->password));
        $this->assertFalse(Hash::check($temporary, $u->password));
        $this->get('/dashboard')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'account.password_changed')->where('auditable_id', $u->id)->exists());

        $this->post('/logout');
        $this->post('/login', ['phone' => '0922334455', 'password' => $temporary])->assertSessionHasErrors('phone');
        $this->assertGuest();
        $this->post('/login', ['phone' => '+251922334455', 'password' => 'Brand-New-Pass!77'])->assertRedirect('/dashboard');
    }

    public function test_someone_without_a_temporary_password_never_sees_the_change_page(): void
    {
        $this->actingAs($this->account())->get('/password/change')->assertRedirect('/dashboard');
    }

    public function test_changing_a_password_the_ordinary_way_clears_a_pending_temporary_flag(): void
    {
        $u = $this->account();
        $u->forceFill(['must_change_password' => true])->save();

        $u->update(['password' => 'Another-Pass!88']);

        $this->assertFalse($u->fresh()->must_change_password);
    }

    // ------------------------------------------------------------------ resetting a password

    public function test_an_administrator_resets_a_password_and_sees_only_the_new_temporary_one(): void
    {
        $teacher = $this->account('0933445566', 'teacher', 'her-own-secret-1');
        $oldHash = $teacher->password;

        $this->actingAs($this->owner)->post("/staff/{$teacher->id}/password-reset")->assertSessionHasNoErrors()->assertSessionHas('credentials');

        $temporary = Crypt::decryptString(session('credentials')['password']);
        $teacher->refresh();
        $this->assertNotSame($oldHash, $teacher->password);
        $this->assertTrue($teacher->must_change_password);
        $this->assertFalse(Hash::check('her-own-secret-1', $teacher->password), 'the old password stops working');
        $this->assertTrue(Hash::check($temporary, $teacher->password));
        $this->assertSame('0933 44 55 66', session('credentials')['phone']);

        $log = AuditLog::where('action', 'staff.password_reset')->firstOrFail();
        $this->assertStringNotContainsString($temporary, json_encode($log->toArray()));
        $this->assertStringNotContainsString('her-own-secret-1', json_encode($log->toArray()));

        $this->post('/logout');
        $this->post('/login', ['phone' => '0933445566', 'password' => 'her-own-secret-1'])->assertSessionHasErrors('phone');
        $this->post('/login', ['phone' => '0933445566', 'password' => $temporary])->assertRedirect('/dashboard');
        $this->get('/students')->assertRedirect('/password/change');
    }

    public function test_the_administrator_is_never_shown_anyones_existing_password(): void
    {
        $teacher = $this->account('0933445566', 'teacher', 'her-own-secret-1');

        $page = $this->props($this->actingAs($this->owner)->get('/staff')->assertOk());

        $this->assertStringNotContainsString('her-own-secret-1', json_encode($page));
        $this->assertStringNotContainsString($teacher->getRawOriginal('password'), json_encode($page));
        $this->assertArrayNotHasKey('password', $page['staff'][0]);
    }

    public function test_who_may_reset_whose_password(): void
    {
        $teacher = $this->account('0933445566', 'teacher');
        $east = User::create(['school_id' => $this->school->id, 'branch_id' => $this->branchB->id, 'name' => 'East', 'phone' => '0944556677', 'login_phone' => '0944556677', 'password' => 'x-secret-1', 'status' => 'active']);
        $east->assignTier('teacher');
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['manage-staff']);

        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->post("/staff/{$teacher->id}/password-reset")->assertForbidden();
        $this->actingAs($this->makeUser('branch_admin', $this->branchA->id, ['manage-students']))->post("/staff/{$teacher->id}/password-reset")->assertForbidden();
        $this->actingAs($admin)->post("/staff/{$east->id}/password-reset")->assertForbidden();                       // another branch
        $this->actingAs($admin)->post("/staff/{$this->owner->id}/password-reset")->assertForbidden();               // an owner
        $this->assertFalse($east->fresh()->must_change_password);

        $this->actingAs($admin)->post("/staff/{$teacher->id}/password-reset")->assertSessionHas('credentials');   // their own branch
    }

    public function test_nobody_resets_their_own_password_that_way(): void
    {
        $this->actingAs($this->owner)->post("/staff/{$this->owner->id}/password-reset")->assertSessionHasErrors('reset');

        $this->assertFalse($this->owner->fresh()->must_change_password);
    }

    // ------------------------------------------------------------------ changing a phone number

    public function test_changing_a_staff_phone_changes_what_they_sign_in_with_and_is_audited(): void
    {
        $teacher = $this->account('0933445566', 'teacher');

        $this->actingAs($this->owner)->patch("/staff/{$teacher->id}", ['title' => 'T', 'status' => 'active', 'phone' => '0966554433'])->assertSessionHasNoErrors();

        $teacher->refresh();
        $this->assertSame(['+251966554433', '+251966554433'], [$teacher->phone, $teacher->login_phone]);
        $log = AuditLog::where('action', 'staff.phone_changed')->firstOrFail();
        $this->assertSame(['+251933445566', '+251966554433'], [$log->before['phone'], $log->after['phone']]);

        $this->post('/logout');
        $this->post('/login', ['phone' => '0933445566', 'password' => 'secret-pass-1'])->assertSessionHasErrors('phone');
        $this->post('/login', ['phone' => '0966554433', 'password' => 'secret-pass-1'])->assertRedirect('/dashboard');
    }

    public function test_a_staff_phone_cannot_be_changed_to_someone_elses(): void
    {
        $a = $this->account('0933445566', 'teacher');
        $this->account('0944556677', 'accountant');

        $this->actingAs($this->owner)->patch("/staff/{$a->id}", ['title' => 'T', 'status' => 'active', 'phone' => '+251944556677'])->assertSessionHasErrors('phone');
        $this->assertSame('+251933445566', $a->fresh()->login_phone);

        $this->actingAs($this->owner)->patch("/staff/{$a->id}", ['title' => 'T', 'status' => 'active', 'phone' => '0933445566'])->assertSessionHasNoErrors();   // keeping their own is fine
    }

    public function test_email_stays_optional_on_the_profile_page(): void
    {
        $u = $this->account();

        $this->actingAs($u)->patch('/profile', ['name' => 'Sara', 'email' => null])->assertSessionHasNoErrors();

        $this->assertNull($u->fresh()->email);
    }
}

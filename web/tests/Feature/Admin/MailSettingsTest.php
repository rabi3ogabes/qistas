<?php

use App\Mail\MailSettings;
use App\Mail\SettingsCheck;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/*
| E-mail through the owner's own SMTP server (Sprint 3 Task 1, decision D1). A super admin saves it in /admin/mail; it is
| kept encrypted, never shown back, and used for every e-mail (verification, password reset) unless the server itself
| names a real mailer in MAIL_MAILER.
*/

function mailAdmin(string $role = 'super_admin'): User
{
    return User::factory()->create(['platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

/** @return array<string, mixed> */
function smtpForm(array $overrides = []): array
{
    return [
        'host' => 'smtp.example.test', 'port' => 587, 'security' => 'starttls', 'username' => 'qistas@example.test',
        'password' => 'mail-secret-value', 'from_address' => 'noreply@qistas.test', 'from_name' => 'Qistas', ...$overrides,
    ];
}

it('sends through the SMTP server a super admin saved', function () {
    $this->actingAs(mailAdmin())->put(route('admin.mail.update'), smtpForm())->assertRedirect()->assertSessionHasNoErrors();

    app(MailSettings::class)->apply();

    $transport = Mail::mailer()->getSymfonyTransport();
    expect(config('mail.default'))->toBe('smtp')
        ->and($transport)->toBeInstanceOf(EsmtpTransport::class)
        ->and($transport->getStream()->getHost())->toBe('smtp.example.test')
        ->and($transport->getStream()->getPort())->toBe(587)
        ->and($transport->getUsername())->toBe('qistas@example.test')
        ->and($transport->getPassword())->toBe('mail-secret-value')
        ->and(config('mail.from'))->toBe(['address' => 'noreply@qistas.test', 'name' => 'Qistas']);
});

it('uses SMTPS on port 465 when asked for SSL', function () {
    $this->actingAs(mailAdmin())->put(route('admin.mail.update'), smtpForm(['port' => 465, 'security' => 'ssl']));

    app(MailSettings::class)->apply();

    expect(Mail::mailer()->getSymfonyTransport()->getStream()->isTLS())->toBeTrue();
});

it('keeps the password encrypted, never shows it back, and keeps it when the form leaves it empty', function () {
    $admin = mailAdmin();
    $this->actingAs($admin)->put(route('admin.mail.update'), smtpForm());

    expect((string) PlatformSetting::query()->find(MailSettings::KEY)?->value)->not->toContain('mail-secret-value');
    $this->actingAs($admin)->get(route('admin.mail.index'))->assertOk()
        ->assertDontSee('mail-secret-value')->assertSee('smtp.example.test')->assertSee('A password is saved');

    $this->actingAs($admin)->put(route('admin.mail.update'), smtpForm(['password' => '', 'host' => 'smtp2.example.test']));

    expect(app(MailSettings::class)->get())->host->toBe('smtp2.example.test')->password->toBe('mail-secret-value');
});

it('lets only a super admin change it', function () {
    $this->actingAs(mailAdmin('admin'))->put(route('admin.mail.update'), smtpForm())->assertForbidden();
    $this->actingAs(mailAdmin('admin'))->get(route('admin.mail.index'))->assertForbidden();

    expect(app(MailSettings::class)->configured())->toBeFalse();
});

it('checks what it is given', function () {
    $this->actingAs(mailAdmin())->put(route('admin.mail.update'), smtpForm(['host' => '', 'port' => 70000, 'security' => 'none', 'from_address' => 'not-an-address']))
        ->assertSessionHasErrors(['host', 'port', 'security', 'from_address']);

    expect(app(MailSettings::class)->configured())->toBeFalse();
});

it('records saving and removing, never with the password', function () {
    $admin = mailAdmin();
    $this->actingAs($admin)->put(route('admin.mail.update'), smtpForm());
    $this->actingAs($admin)->put(route('admin.mail.update'), ['remove' => '1']);

    $actions = DB::table('audit_logs')->whereIn('action', ['mail.settings_saved', 'mail.settings_removed'])->get();
    expect($actions->pluck('action')->all())->toEqualCanonicalizing(['mail.settings_saved', 'mail.settings_removed'])
        ->and($actions->pluck('changes')->implode(' '))->not->toContain('mail-secret-value')
        ->and(app(MailSettings::class)->configured())->toBeFalse();
});

it('sends a test e-mail to the admin', function () {
    $admin = mailAdmin();
    $this->actingAs($admin)->put(route('admin.mail.update'), smtpForm());
    Mail::fake();

    $this->actingAs($admin)->post(route('admin.mail.test'))->assertRedirect()->assertSessionHas('status');

    Mail::assertSent(fn (SettingsCheck $mail) => $mail->hasTo($admin->email));
});

it('says plainly when the server cannot be reached, rather than failing', function () {
    $admin = mailAdmin();
    // A port nothing listens on.
    $this->actingAs($admin)->put(route('admin.mail.update'), smtpForm(['host' => '127.0.0.1', 'port' => 9]));

    $this->actingAs($admin)->post(route('admin.mail.test'))->assertRedirect()
        ->assertSessionHas('warning', fn (string $message) => str_starts_with($message, 'The test e-mail could not be sent'));
});

it('leaves a real mailer named by the server alone', function () {
    $this->actingAs(mailAdmin())->put(route('admin.mail.update'), smtpForm());
    config(['mail.server_mailer' => 'ses', 'mail.default' => 'ses']);

    app(MailSettings::class)->apply();

    expect(config('mail.default'))->toBe('ses');
});

it('tells the platform overview that e-mail is sending', function () {
    $admin = mailAdmin();
    $this->actingAs($admin)->get(route('admin.home'))->assertSee('Not sending');

    $this->actingAs($admin)->put(route('admin.mail.update'), smtpForm());

    $this->actingAs($admin)->get(route('admin.home'))->assertSee('Sending through smtp.example.test');
});

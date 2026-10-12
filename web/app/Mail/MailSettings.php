<?php

namespace App\Mail;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Crypt;

/**
 * The owner's own SMTP server (Sprint 3, decision D1), kept in platform_settings with its password encrypted and never
 * shown back. It is used for every e-mail (verification, password reset, alerts) unless the server's environment names
 * a real mailer in MAIL_MAILER, which then wins; "log" and "array" (the defaults) are not real ones.
 */
final class MailSettings
{
    public const KEY = 'mail.smtp';

    /** STARTTLS (usually port 587) or SSL from the start (usually port 465). */
    public const SECURITY = ['starttls', 'ssl'];

    /** @return array{host: string, port: int, security: string, username: string|null, password: string|null, from_address: string, from_name: string}|null */
    public function get(): ?array
    {
        $stored = PlatformSetting::query()->whereKey(self::KEY)->value('value');
        $settings = is_string($stored) ? json_decode($stored, true) : null;
        if (! is_array($settings) || ! is_string($settings['host'] ?? null) || $settings['host'] === '') {
            return null;
        }

        return [
            'host' => $settings['host'],
            'port' => (int) ($settings['port'] ?? 587),
            'security' => in_array($settings['security'] ?? null, self::SECURITY, true) ? $settings['security'] : 'starttls',
            'username' => is_string($settings['username'] ?? null) && $settings['username'] !== '' ? $settings['username'] : null,
            'password' => is_string($settings['password'] ?? null) && $settings['password'] !== '' ? Crypt::decryptString($settings['password']) : null,
            'from_address' => (string) ($settings['from_address'] ?? ''),
            'from_name' => (string) ($settings['from_name'] ?? config('qistas.app_name')),
        ];
    }

    public function configured(): bool
    {
        return $this->get() !== null;
    }

    /**
     * Keeps the server's details. An empty password keeps the one already saved, so the form never has to show it.
     *
     * @param  array{host: string, port: int, security: string, username?: string|null, password?: string|null, from_address: string, from_name: string}  $settings
     */
    public function save(array $settings, ?User $by): void
    {
        $password = trim((string) ($settings['password'] ?? ''));
        $kept = $password === '' ? $this->get()['password'] ?? null : $password;

        $this->store([
            'host' => trim($settings['host']),
            'port' => (int) $settings['port'],
            'security' => $settings['security'],
            'username' => trim((string) ($settings['username'] ?? '')) ?: null,
            'password' => $kept === null ? null : Crypt::encryptString($kept),
            'from_address' => trim($settings['from_address']),
            'from_name' => trim($settings['from_name']),
        ], $by);

        // Recorded with where mail now goes, never with the password.
        Audit::record('mail.settings_saved', null, ['host' => trim($settings['host']), 'port' => (int) $settings['port']], null, $by?->id);
        $this->apply();
    }

    public function remove(?User $by): void
    {
        $this->store(null, $by);
        Audit::record('mail.settings_removed', null, [], null, $by?->id);
    }

    /** Whether the server's own environment chose a real mailer, which then wins over the saved server. */
    public function serverDecides(): bool
    {
        $mailer = config('mail.server_mailer');

        return is_string($mailer) && ! in_array($mailer, ['', 'log', 'array'], true);
    }

    /**
     * Points the mailer at the saved server, before the first e-mail of a request or a queued job (and again right
     * after the settings change). Does nothing when the server's environment decides, or nothing is saved.
     */
    public function apply(): void
    {
        $smtp = $this->serverDecides() ? null : $this->get();
        if ($smtp === null) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $smtp['security'] === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => $smtp['host'],
            'mail.mailers.smtp.port' => $smtp['port'],
            'mail.mailers.smtp.username' => $smtp['username'],
            'mail.mailers.smtp.password' => $smtp['password'],
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => $smtp['from_address'],
            'mail.from.name' => $smtp['from_name'],
        ]);

        // A mailer already made this request keeps its old server unless it is made again.
        if (app()->resolved('mail.manager')) {
            app(MailManager::class)->forgetMailers();
        }
    }

    /** @param  array<string, mixed>|null  $settings */
    private function store(?array $settings, ?User $by): void
    {
        $setting = PlatformSetting::query()->find(self::KEY) ?? (new PlatformSetting)->forceFill(['key' => self::KEY]);
        $setting->forceFill(['value' => $settings === null ? null : json_encode($settings), 'updated_by_user_id' => $by?->id])->save();
    }
}

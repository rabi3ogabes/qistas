<?php

namespace App\Documents;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;

/**
 * Makes a statement, report or receipt (Win Plan PP8) for the active workspace: renders it with the shop's profile and
 * its preferences, counts it against the plan's monthly allowance and records it, so its QR code can be checked at
 * /verify. The same document asked for again within ten minutes (same kind, subject, choices and content) is the same
 * document: same code, not counted twice.
 */
final class DocumentService
{
    /** How long the same document can be made again without counting it twice. */
    public const SAME_DOCUMENT_MINUTES = 10;

    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly PdfRenderer $renderer) {}

    /** @return array{pdf: string, document: Document, filename: string} */
    public function issue(Template $template, DocumentOptions $options, Tenant $tenant, User $by): array
    {
        $data = $template->data($options);
        $subject = $template->subject();
        $hash = hash('sha256', (string) json_encode([$template->kind(), $subject?->getMorphClass(), $subject?->getKey(), $options->fingerprint(), $template->reference(), $template->total()]));

        $existing = Document::query()->where('kind', $template->kind())->where('params_hash', $hash)
            ->where('created_at', '>=', now()->subMinutes(self::SAME_DOCUMENT_MINUTES))->latest()->first();
        $code = $existing->verification_code ?? $this->newCode();

        $profile = BusinessProfile::for($tenant);
        $branded = Entitlements::for($tenant)->check(Feature::CustomBranding)->enabled();
        $verifyUrl = route('documents.verify', ['code' => $code]);

        $pdf = $this->renderer->render($template->view(), $data + [
            'options' => $options,
            'profile' => $profile,
            'shop' => $profile->name($options->language),
            'currency' => (string) $tenant->currency,
            'code' => $code,
            'verifyUrl' => $verifyUrl,
            'qr' => QrCode::dataUri($verifyUrl, 180),
            'issuedAt' => $tenant->localTime(now()),
            'signature' => $options->shows('signature') ? $profile->signature()?->dataUri() : null,
        ], $options->language, $existing === null ? Feature::PdfStatements : null, [
            'logo' => $branded ? $profile->logo()?->dataUri() : null,
            'name' => $profile->name($options->language),
            // Free documents say where they were made; a shop with its own branding signs off with its own words.
            'qistas' => ! $branded,
            'footer' => $branded ? $profile->fields['footer'] : null,
        ], $options->page());

        $document = $existing ?? tap((new Document)->forceFill([
            'kind' => $template->kind(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'params_hash' => $hash,
            'verification_code' => $code,
            'reference' => mb_substr($template->reference(), 0, 120),
            'total' => $template->total(),
            'created_by_user_id' => $by->getKey(),
        ]))->save();

        return ['pdf' => $pdf, 'document' => $document, 'filename' => $template->filename($options)];
    }

    /**
     * The document a code was issued for, from any workspace, with its workspace: what /verify shows. The only place a
     * document is read outside its workspace, and only by the unguessable code printed on it.
     */
    public static function verify(string $code): ?Document
    {
        $document = Document::withoutGlobalScope(TenantScope::class)->with('tenant')->where('verification_code', strtoupper($code))->first();

        return $document !== null && $document->tenant !== null ? $document : null;
    }

    private function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Document::withoutGlobalScope(TenantScope::class)->where('verification_code', $code)->exists());

        return $code;
    }
}

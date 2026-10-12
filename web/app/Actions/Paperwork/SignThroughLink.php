<?php

namespace App\Actions\Paperwork;

use App\Http\ApiException;
use App\Models\ContractSignature;
use App\Models\SignatureLink;
use App\Support\Digits;
use App\Support\SignatureImage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The customer's side of a signing link (Win Plan K5, decision D6): the code first, then the signature, once. Five
 * wrong codes close the link. Nothing about the contract is shown before the right code.
 */
final class SignThroughLink
{
    public function __construct(private readonly CurrentTenant $current, private readonly SignContract $sign) {}

    /**
     * The link behind a secret, open or not, or null. Read outside any workspace: the customer is in none. The only
     * read of signature links across workspaces; everything after it happens inside the link's own workspace.
     */
    public static function find(string $token): ?SignatureLink
    {
        $link = SignatureLink::withoutGlobalScope(TenantScope::class)->with('tenant')->where('token_hash', hash('sha256', $token))->first();

        return $link !== null && $link->tenant?->status === 'active' ? $link : null;
    }

    /** Whether [$code] is the link's; a wrong one counts towards closing it. */
    public function checkCode(SignatureLink $link, string $code): bool
    {
        if (! $link->isOpen()) {
            return false;
        }

        if (Hash::check(Digits::toAscii(trim($code)), $link->code_hash)) {
            return true;
        }

        DB::table('signature_links')->where('id', $link->id)->increment('attempts', 1, ['updated_at' => now()]);

        return false;
    }

    /**
     * Keeps the signature and closes the link, once: two taps at once sign once.
     *
     * @throws ApiException signature_link_invalid (410) when the link was used, closed or expired meanwhile
     */
    public function sign(SignatureLink $link, string $png, ?string $ip, ?string $userAgent): ContractSignature
    {
        SignatureImage::clean($png);

        return DB::transaction(function () use ($link, $png, $ip, $userAgent): ContractSignature {
            $claimed = DB::table('signature_links')->where('id', $link->id)->whereNull('used_at')
                ->where('expires_at', '>', now())->where('attempts', '<', SignatureLink::ATTEMPTS)
                ->update(['used_at' => now(), 'updated_at' => now()]);
            if ($claimed === 0) {
                throw new ApiException('signature_link_invalid', __('This signing link is no longer valid. Ask the shop for a new one.'), 410);
            }

            return $this->current->use($link->tenant, fn () => $this->sign->handle($link->contract, $png, null, $link, $ip, $userAgent));
        });
    }
}

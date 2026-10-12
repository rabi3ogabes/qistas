<?php

namespace App\Actions\Paperwork;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\SignatureLink;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A link for the customer to sign on their own phone (Win Plan K5, decision D6), with a six-digit code to send them
 * apart (the link by WhatsApp, the code by text message), so a forwarded link alone signs nothing. Both are shown to
 * the owner once and only their hashes are kept. A new link closes any the contract still had open.
 */
final class CreateSignatureLink
{
    public function __construct(private readonly CurrentTenant $current) {}

    /** @return array{link: SignatureLink, url: string, code: string} */
    public function handle(Contract $contract, User $by): array
    {
        Entitlements::for($contract->tenant)->assertEnabled(Feature::Guarantors);

        $token = Str::random(48);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $link = $this->current->use($contract->tenant, fn () => DB::transaction(function () use ($contract, $token, $code, $by): SignatureLink {
            SignatureLink::query()->where('contract_id', $contract->id)->whereNull('used_at')->where('expires_at', '>', now())
                ->update(['expires_at' => now(), 'updated_at' => now()]);

            $link = (new SignatureLink)->forceFill([
                'contract_id' => $contract->id,
                'token_hash' => hash('sha256', $token),
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addHours(SignatureLink::HOURS),
                'created_by_user_id' => $by->id,
            ]);
            $link->save();

            Audit::record('signature_link.created', $link, ['contract_id' => $contract->id], $contract->tenant_id, $by->id);

            return $link;
        }));

        return ['link' => $link, 'url' => route('sign.show', ['token' => $token]), 'code' => $code];
    }
}

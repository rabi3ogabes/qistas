<?php

namespace App\Actions\Paperwork;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\SignatureLink;
use App\Models\User;
use App\Support\Audit;
use App\Support\SignatureImage;
use App\Tenancy\CurrentTenant;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the customer's signature with a contract (Win Plan K5, CT23): drawn on the owner's screen ([$by] is who held
 * the phone), or through a link on the customer's own phone ([$link], with where it came from). A new signature takes
 * the place of the one before, which stays as history. Audited.
 */
final class SignContract
{
    public function __construct(private readonly CurrentTenant $current) {}

    /** @throws ValidationException (field `file`) when the drawing is not a PNG picture */
    public function handle(Contract $contract, string $png, ?User $by, ?SignatureLink $link = null, ?string $ip = null, ?string $userAgent = null): ContractSignature
    {
        Entitlements::for($contract->tenant)->assertEnabled(Feature::Guarantors);
        $image = SignatureImage::clean($png);

        return $this->current->use($contract->tenant, function () use ($contract, $image, $by, $link, $ip, $userAgent): ContractSignature {
            $signature = (new ContractSignature)->forceFill([
                'contract_id' => $contract->id,
                'name' => (string) $contract->customer?->name,
                'image' => $image,
                'method' => $link === null ? 'screen' : 'link',
                'signature_link_id' => $link?->id,
                'recorded_by_user_id' => $by?->id,
                'ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'signed_at' => now(),
            ]);
            $signature->save();

            Audit::record('contract.signed', $signature, ['contract_id' => $contract->id, 'method' => $signature->method], $contract->tenant_id, $by?->id);

            return $signature;
        });
    }
}

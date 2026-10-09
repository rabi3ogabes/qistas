<?php

namespace App\Support;

use App\Tenancy\TenantRole;

/**
 * Everything a workspace may keep, and nothing else. The kind is an enum, so a storage path can never be built from
 * something a client sent (no `../`), and each kind says which file types it takes, how large they may be, who may
 * open them and whether opening one is written to the audit log.
 */
enum FileKind: string
{
    case IdFront = 'id_front';
    case IdBack = 'id_back';
    case PaymentProof = 'payment_proof';
    case ChequeFront = 'cheque_front';
    case ChequeBack = 'cheque_back';
    case ContractPdf = 'contract_pdf';
    case Signature = 'signature';
    case Logo = 'logo';
    case Statement = 'statement';

    public const IMAGE_BYTES = 6 * 1024 * 1024;

    public const PDF_BYTES = 10 * 1024 * 1024;

    /**
     * The file types this kind takes, by what the bytes are (never by the name the client gave).
     *
     * @return list<string>
     */
    public function mimes(): array
    {
        return match ($this) {
            self::IdFront, self::IdBack, self::ChequeFront, self::ChequeBack, self::Logo => ['image/jpeg', 'image/png'],
            self::PaymentProof => ['image/jpeg', 'image/png', 'application/pdf'],
            self::Signature => ['image/png'],
            self::ContractPdf, self::Statement => ['application/pdf'],
        };
    }

    /** How large a file of this type may be. */
    public static function maxBytes(string $mime): int
    {
        return $mime === 'application/pdf' ? self::PDF_BYTES : self::IMAGE_BYTES;
    }

    /** Opening or storing one of these is written to the audit log: a person's identity document. */
    public function audited(): bool
    {
        return $this === self::IdFront || $this === self::IdBack;
    }

    /** Who in the workspace may open it. Identity documents stay with the people who run the business. */
    public function openedBy(TenantRole $role): bool
    {
        if ($this->audited()) {
            return in_array($role, [TenantRole::Owner, TenantRole::Manager, TenantRole::Accountant], true);
        }

        return true;
    }
}

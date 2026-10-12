<?php

namespace App\Actions\Paperwork;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\FileKind;
use App\Support\Files;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a photo with a contract (Win Plan K5, CT22): the customer's ID, the signed paper contract or the invoice. Files
 * checks what it is, draws it again without its location data and keeps it private. At most twelve on a contract,
 * counted under a lock.
 */
final class AddContractPhoto
{
    public const MAX_PER_CONTRACT = 12;

    public function __construct(private readonly CurrentTenant $current, private readonly Files $files) {}

    /** @throws ValidationException (field `file`) when it is not a picture, is too large, or the contract is full */
    public function handle(Contract $contract, FileKind $kind, UploadedFile|string $photo, User $by): StoredFile
    {
        Entitlements::for($contract->tenant)->assertEnabled(Feature::Guarantors);

        if (! in_array($kind, FileKind::CONTRACT_PHOTOS, true)) {
            throw ValidationException::withMessages(['kind' => __('Choose what the photo shows.')]);
        }

        return $this->current->use($contract->tenant, fn () => DB::transaction(function () use ($contract, $kind, $photo, $by): StoredFile {
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($locked->photos()->count() >= self::MAX_PER_CONTRACT) {
                throw ValidationException::withMessages(['file' => __('Most photos on a contract: :count.', ['count' => self::MAX_PER_CONTRACT])]);
            }

            return $this->files->put($photo, $kind, $locked, $by);
        }));
    }
}

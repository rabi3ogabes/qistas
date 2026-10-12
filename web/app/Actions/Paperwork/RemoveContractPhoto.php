<?php

namespace App\Actions\Paperwork;

use App\Models\Contract;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Audit;
use App\Support\FileKind;
use App\Support\Files;

/**
 * Takes a photo off a contract (Win Plan K5): its bytes leave storage, its record stays marked deleted. Only a photo
 * of that very contract; anything else is not found. Works whether the feature is on or off.
 */
final class RemoveContractPhoto
{
    public function __construct(private readonly Files $files) {}

    public function handle(Contract $contract, StoredFile $photo, User $by): void
    {
        abort_unless(
            $photo->subject_type === $contract->getMorphClass() && $photo->subject_id === $contract->id && in_array($photo->kind, FileKind::CONTRACT_PHOTOS, true),
            404,
        );

        $this->files->delete($photo);

        Audit::record('file.removed', $photo, ['kind' => $photo->kind->value, 'contract_id' => $contract->id], $photo->tenant_id, $by->id);
    }
}

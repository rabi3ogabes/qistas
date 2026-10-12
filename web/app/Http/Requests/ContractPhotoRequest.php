<?php

namespace App\Http\Requests;

use App\Support\FileKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A photo for a contract (Win Plan K5): what it shows (the ID's front or back, the signed contract, the invoice) and
 * the picture. What the file really is, and its size, are checked again by Files from its bytes.
 */
final class ContractPhotoRequest extends FormRequest
{
    protected $errorBag = 'photo';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(array_map(fn (FileKind $kind) => $kind->value, FileKind::CONTRACT_PHOTOS))],
            'file' => ['required', 'file', 'max:'.(FileKind::IMAGE_BYTES / 1024)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.required' => __('Choose what the photo shows.'),
            'kind.in' => __('Choose what the photo shows.'),
            'file.required' => __('Choose a photo.'),
            'file.max' => __('This picture is larger than :mb MB.', ['mb' => FileKind::IMAGE_BYTES / 1024 / 1024]),
        ];
    }

    public function kind(): FileKind
    {
        return FileKind::from((string) $this->validated('kind'));
    }

    public function photo(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}

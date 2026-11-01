<?php

namespace App\Support;

use App\Models\User;
use App\Rules\SafeUploadRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared "attach a few photos/GIFs" handling for ParentMessage, Announcement and Complaint -- one validation
 * rule set and one storage call, instead of three near-identical copies. Never video (see
 * RATE_LIMITING_AND_ABUSE_PROTECTION.md-style reasoning: no processing/compression pipeline exists, and this
 * school's own networks are the slow, constrained ones that rule change was written for); never any file
 * type SafeUpload wouldn't already accept for a photo.
 */
class AttachmentUpload
{
    public const MAX_PER_POST = 4;

    public static function rules(): array
    {
        return [
            'images' => ['nullable', 'array', 'max:'.self::MAX_PER_POST],
            'images.*' => [new SafeUploadRule(['jpg', 'png', 'gif'])],
        ];
    }

    /**
     * Stores each uploaded image once and attaches it to $model -- and, for a broadcast that creates several
     * records from the same uploaded files in one request, to every model in $also too, without re-uploading
     * or re-processing the same file more than once.
     *
     * @param  list<\Illuminate\Http\UploadedFile>  $files
     * @param  list<Model>  $also
     */
    public static function attach(array $files, Model $model, User $uploader, string $directory, array $also = []): void
    {
        foreach ($files as $file) {
            $path = SafeUpload::storePublicAttachment($file, $directory);
            [, $mime] = SafeUpload::servingType($path);

            foreach ([$model, ...$also] as $target) {
                $target->attachments()->create(['uploaded_by' => $uploader->id, 'path' => $path, 'mime' => $mime]);
            }
        }
    }
}

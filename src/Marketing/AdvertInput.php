<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * An advert as a person typed it, every value as text, with a picture uploaded (its temporary file) or the one there is
 * removed: Adverts checks each and says why any is refused, by its field (name, kind, headline, words, media_address,
 * picture, notes).
 */
final class AdvertInput
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $kind = 'words',
        public readonly string $headline = '',
        public readonly string $words = '',
        public readonly string $mediaAddress = '',
        public readonly string $notes = '',
        public readonly ?string $picturePath = null,
        public readonly bool $removePicture = false,
        public readonly ?string $uploadProblem = null,
    ) {
    }

    /**
     * @param array<string,mixed> $input a form's fields by their names; remove_picture ticked to remove the picture there is
     * @param UploadedFile|null $picture the picture field's upload, if one was chosen
     */
    public static function fromForm(array $input, ?UploadedFile $picture = null): self
    {
        $path = null;
        $problem = null;
        if ($picture !== null && $picture->getError() !== UPLOAD_ERR_NO_FILE) {
            if ($picture->isValid()) {
                $path = $picture->getPathname();
            } else {
                $problem = in_array($picture->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'The picture is larger than the site takes: choose a smaller one.'
                    : 'The picture did not arrive: choose it again.';
            }
        }

        return new self(Rows::typed($input['name'] ?? ''), Rows::typed($input['kind'] ?? 'words'), Rows::typed($input['headline'] ?? ''), Rows::typed($input['words'] ?? ''),
            Rows::typed($input['media_address'] ?? ''), Rows::typed($input['notes'] ?? ''), $path, in_array($input['remove_picture'] ?? null, ['1', 'on', 'yes', 'true'], true), $problem);
    }

    /** An advert as its edit form starts: what it holds now. */
    public static function of(Advert $advert): self
    {
        return new self($advert->name, $advert->kind, $advert->headline, $advert->words, $advert->mediaAddress ?? '', $advert->notes);
    }
}

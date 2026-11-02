<?php

namespace App\Http\Requests\Mobile\Websites;

use App\Domain\Website\WebsiteImageStore;
use Illuminate\Foundation\Http\FormRequest;

class UploadWebsiteImageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The picture, sent as `multipart/form-data`. JPEG, PNG or WebP, 2 MB at most (see `image_limits`). */
            'image' => [
                'required', 'file',
                'mimetypes:'.implode(',', WebsiteImageStore::ACCEPTED_MIME_TYPES),
                'max:'.(int) (WebsiteImageStore::MAX_BYTES / 1024),
            ],
        ];
    }
}

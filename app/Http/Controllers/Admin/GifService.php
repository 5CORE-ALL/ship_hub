<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class GifService
{
    /**
     * Save Base64 GIF to storage and return public URL
     *
     * @param string $base64
     * @return string|false Returns public URL on success, false on failure
     */
    public function saveBase64Pdf(string $base64)
    {
        // Decode Base64
        $data = base64_decode($base64);

        if ($data === false) {
            return false;
        }

        // Generate unique filename with .pdf extension
        $filename = 'file_' . Str::random(10) . '.pdf';

        // Save to storage/app/public
        $filePath = 'public/' . $filename;
        if (Storage::put($filePath, $data)) {
            // Return public URL
            return asset('storage/' . $filename);
        }

        return false;
    }
}

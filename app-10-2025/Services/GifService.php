<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use FPDF;

class GifService
{
    /**
     * Save Base64 GIF to storage and return public URL
     *
     * @param string $base64
     * @return string|false Returns public URL on success, false on failure
     */
    // public function saveBase64Gif(string $base64)
    // {
    //     $data = base64_decode($base64);

    //     if ($data === false) {
    //         return false;
    //     }

    //     // Generate unique filename
    //     $filename = 'gif_' . Str::random(10) . '.gif';

    //     // Save to storage/app/public
    //     $filePath = 'public/' . $filename;
    //     if (Storage::put($filePath, $data)) {
    //         // Return public URL
    //         return asset('storage/' . $filename);
    //     }

    //     return false;
    // }
    // public function saveBase64Gif(string $base64)
    // {
    //     $data = base64_decode($base64);

    //     if ($data === false) {
    //         return false;
    //     }

    //     // Save temporary GIF
    //     $gifTemp = storage_path('app/public/tmp_' . Str::random(8) . '.gif');
    //     if (!file_put_contents($gifTemp, $data)) {
    //         return false;
    //     }

    //     // Get dimensions of GIF
    //     [$width, $height] = getimagesize($gifTemp);
    //     if (!$width || !$height) {
    //         @unlink($gifTemp);
    //         return false;
    //     }

    //     // Define 4x6 inch in mm
    //     $wInch = 101.6; // 4 in
    //     $hInch = 152.4; // 6 in

    //     // Orientation check
    //     $orientation = ($width > $height) ? 'L' : 'P';

    //     // Generate PDF path
    //     $pdfFilename = 'label_' . Str::random(10) . '.pdf';
    //     $pdfFullPath = storage_path('app/public/' . $pdfFilename);

    //     try {
    //         if ($orientation === 'L') {
    //             $pdf = new \FPDF('L', 'mm', [$hInch, $wInch]); // 152.4 x 101.6
    //             $pdf->AddPage();
    //             $pdf->Image($gifTemp, 0, 0, 152.4, 101.6);
    //         } else {
    //             $pdf = new \FPDF('P', 'mm', [$wInch, $hInch]); // 101.6 x 152.4
    //             $pdf->AddPage();
    //             $pdf->Image($gifTemp, 0, 0, 101.6, 152.4);
    //         }

    //         $pdf->Output('F', $pdfFullPath);
    //     } catch (\Exception $e) {
    //         @unlink($gifTemp);
    //         return false;
    //     }

    //     // Delete temp gif
    //     @unlink($gifTemp);

    //     if (!file_exists($pdfFullPath)) {
    //         return false;
    //     }

    //     return asset('storage/' . $pdfFilename);
    // }
    public function saveBase64Gif(string $base64)
{
    $data = base64_decode($base64);

    if ($data === false) {
        return false;
    }

    // Save temporary GIF in labels folder
    $gifTemp = storage_path('app/public/labels/tmp_' . Str::random(8) . '.gif');
    if (!file_put_contents($gifTemp, $data)) {
        return false;
    }

    // Get dimensions of GIF
    [$width, $height] = getimagesize($gifTemp);
    if (!$width || !$height) {
        @unlink($gifTemp);
        return false;
    }

    // 4x6 inch in mm
    $wInch = 101.6; // 4 in
    $hInch = 152.4; // 6 in

    // Orientation
    $orientation = ($width > $height) ? 'L' : 'P';

    // PDF filename in labels folder
    $pdfFilename = 'labels/label_' . Str::random(10) . '.pdf';
    $pdfFullPath = storage_path('app/public/' . $pdfFilename);

    try {
        if ($orientation === 'L') {
            $pdf = new \FPDF('L', 'mm', [$hInch, $wInch]);
            $pdf->AddPage();
            $pdf->Image($gifTemp, 0, 0, $hInch, $wInch);
        } else {
            $pdf = new \FPDF('P', 'mm', [$wInch, $hInch]);
            $pdf->AddPage();
            $pdf->Image($gifTemp, 0, 0, $wInch, $hInch);
        }

        $pdf->Output('F', $pdfFullPath);
    } catch (\Exception $e) {
        @unlink($gifTemp);
        return false;
    }

    // Delete temp GIF
    @unlink($gifTemp);

    if (!file_exists($pdfFullPath)) {
        return false;
    }

    return asset('storage/' . $pdfFilename);
}


}

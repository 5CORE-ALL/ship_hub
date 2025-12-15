<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pdf; 
use App\Models\Shipment; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
class PdfController extends Controller
{
 
   public function index(Request $request)
    {
        $date = $request->input('filter_date', now()->format('Y-m-d'));

        
        // Filter PDFs by date (for view if needed)
        $pdfs = Pdf::whereDate('upload_date', $date)
                   ->orderBy('upload_date', 'desc')
                   ->paginate(10);
        $shipHubCount = \App\Models\Shipment::whereDate('ship_date', $date)->count();
        $manualCount = Pdf::whereDate('upload_date', $date)->count(); 
        return view('admin.pdf.index', compact('pdfs', 'shipHubCount', 'manualCount', 'date'));
    }
    public function view(Pdf $pdf)
    {
        if (!$pdf->file_path || !Storage::disk('public')->exists($pdf->file_path)) {
            abort(404, 'PDF not found.');
        }

        return Storage::disk('public')->response($pdf->file_path);
    }
       public function destroy(Pdf $pdf)
    {
        // Debug: Log entry and PDF details
        Log::info('Destroy called', [
            'pdf_id' => $pdf->id ?? 'NULL_PDF',
            'exists' => $pdf->exists ?? false,
            'file_path' => $pdf->file_path ?? 'none',
            'label_url' => $pdf->label_url ?? 'none'
        ]);

        if (!$pdf->exists) {
            Log::error('PDF not found or not loaded');
            return redirect()->back()->with('error', 'PDF not found.');
        }

        try {
            // File deletion (unchanged)
            $deleteLocalFile = function ($path) {
                if ($path && !Str::startsWith($path, ['http://', 'https://'])) {
                    $deleted = Storage::disk('public')->delete($path);
                    Log::info('File delete attempt', ['path' => $path, 'success' => $deleted]);
                }
            };
            $deleteLocalFile($pdf->file_path);
            $deleteLocalFile($pdf->label_url);

            // DB deletion
            $deleted = $pdf->delete(); // Returns true/false
            Log::info('DB delete result', ['success' => $deleted, 'pdf_id' => $pdf->id]);

            if (!$deleted) {
                throw new \Exception('DB deletion failed silently');
            }

            return redirect()->back()
                ->with('success', 'PDF deleted successfully.');

        } catch (\Exception $e) {
            Log::error('Delete exception', [
                'pdf_id' => $pdf->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()
                ->with('error', 'Failed to delete PDF: ' . $e->getMessage());
        }
    }
    public function create()
    {
        return view('admin.pdf.create'); 
    }
public function download(Request $request)
{
    $types = explode(',', $request->input('types', [])); 
    $date = $request->input('filter_date', now()->format('Y-m-d'));

    $pdfs = collect();
    $shipments = collect();

    // Fetch PDFs if "manual" selected
    if (in_array('manual', $types)) {
        $pdfs = Pdf::whereDate('upload_date', $date)->get();
    }

    // Fetch ShipHub shipments if "shiphub" selected
    if (in_array('shiphub', $types)) {
        // $shipments = Shipment::whereDate('ship_date', $date)->get();
        $shipments = Shipment::select(
        'shipments.*',
        'orders.order_number'
    )
    ->join('orders', 'orders.id', '=', 'shipments.order_id')
    ->whereDate('shipments.ship_date', $date)
    ->get();
    }

    // If nothing found
    if ($pdfs->isEmpty() && $shipments->isEmpty()) {
        return redirect()->back()->with('error', 'No PDFs or ShipHub labels found for download.');
    }

    // Ensure temp directory exists
    $tempPath = storage_path('app/temp');
    if (!file_exists($tempPath)) {
        mkdir($tempPath, 0777, true);
    }

    $zip = new \ZipArchive();
    $zipFileName = 'shipping_labels_' . $date . '.zip';
    $zipPath = $tempPath . '/' . $zipFileName;

    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === TRUE) {

        // Add manual PDFs
        foreach ($pdfs as $pdf) {
            if ($pdf->label_url) {
                // Convert public URL → storage path
                $relativePath = str_replace(url('/storage') . '/', '', $pdf->label_url);
                $filePath = storage_path('app/public/' . $relativePath);

                if (file_exists($filePath)) {
                    $fileName = 'manual_' . ($pdf->original_name ?? basename($filePath));
                    $zip->addFile($filePath, $fileName);
                }
            }
        }

        // Add ShipHub shipment PDFs
        foreach ($shipments as $shipment) {
            if ($shipment->label_url) {
                // Clean URL to relative path if needed
                $relativePath = str_replace(url('/storage') . '/', '', $shipment->label_url);

                // Handle both: full URL or direct "labels/..." path
                if (!str_starts_with($relativePath, 'labels/')) {
                    $relativePath = 'labels/' . basename($relativePath);
                }

                $filePath = storage_path('app/public/' . $relativePath);

                if (file_exists($filePath)) {
                    // Ensure PDF extension in filename
                    $fileName = 'shiphub_' . ($shipment->order_number ?? basename($filePath));
                    if (!str_ends_with($fileName, '.pdf')) {
                        $fileName .= '.pdf';
                    }

                    $zip->addFile($filePath, $fileName);
                }
            }
        }

        $zip->close();
        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    return redirect()->back()->with('error', 'Failed to create zip.');
}

// public function download(Request $request)
// {
//     $types = explode(',', $request->input('types', [])); 
//     $date = $request->input('filter_date', now()->format('Y-m-d'));

//     $pdfs = collect();
//     $shipments = collect();

//     // Fetch PDFs if "manual" selected
//     if (in_array('manual', $types)) {
//         $pdfs = Pdf::whereDate('upload_date', $date)->get();
//     }

//     // Fetch ShipHub shipments if "shiphub" selected
//     if (in_array('shiphub', $types)) {
//         $shipments = Shipment::whereDate('ship_date', $date)->get();
//     }

//     // If nothing found
//     if ($pdfs->isEmpty() && $shipments->isEmpty()) {
//         return redirect()->back()->with('error', 'No PDFs or ShipHub labels found for download.');
//     }

//     // Ensure temp directory exists
//     $tempPath = storage_path('app/temp');
//     if (!file_exists($tempPath)) {
//         mkdir($tempPath, 0777, true);
//     }

//     $zip = new \ZipArchive();
//     $zipFileName = 'shipping_labels_' . $date . '.zip';
//     $zipPath = $tempPath . '/' . $zipFileName;

//     if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === TRUE) {
//         // Add manual PDFs
//         foreach ($pdfs as $pdf) {
//             if ($pdf->label_url) {
//                 $relativePath = str_replace(url('/storage') . '/', '', $pdf->label_url);
//                 $filePath = storage_path('app/public/' . $relativePath);
//                 if (file_exists($filePath)) {
//                     $fileName = 'manual_' . ($pdf->original_name ?? basename($filePath));
//                     $zip->addFile($filePath, $fileName);
//                 }
//             }
//         }

//         // Add ShipHub shipment PDFs
//         foreach ($shipments as $shipment) {
//             if ($shipment->label_url) {
//                 $relativePath = str_replace(url('/storage') . '/', '', $shipment->label_url);
//                 $filePath = storage_path('app/public/' . $relativePath);
//                 if (file_exists($filePath)) {
//                     $fileName = 'shiphub_' . ($shipment->order_id ?? basename($filePath));
//                     $zip->addFile($filePath, $fileName);
//                 }
//             }
//         }

//         $zip->close();
//         return response()->download($zipPath)->deleteFileAfterSend(true);
//     }

//     return redirect()->back()->with('error', 'Failed to create zip.');
// }

    /**
     * Handle multiple PDF upload.
     */
public function upload(Request $request)
{
    $validator = Validator::make($request->all(), [
        'pdf_files' => 'required|file|mimes:pdf|max:10240', // Single file now
        'upload_date' => 'required|date',
    ]);

    if ($validator->fails()) {
        if ($request->ajax()) {
            return response()->json(['success' => false, 'error' => $validator->errors()->first()], 422);
        }
        return redirect()->back()->withErrors($validator)->withInput();
    }

    $uploadDate = $request->input('upload_date');
    $file = $request->file('pdf_files');

    try {
        // Generate unique filename
        $filename = time() . '_' . uniqid() . '.pdf';
        $path = $file->storeAs('pdfs', $filename, 'public');

        // Save to DB
        Pdf::create([
            'label_url' => $path,
            'original_name' => $file->getClientOriginalName(),
            'created_by' => auth()->id(),
            'upload_date' => $uploadDate,
        ]);

        if ($request->ajax()) {
            return response()->json(['success' => true, 'message' => 'PDF uploaded successfully!']);
        }
        return redirect()->route('pdfs.index')->with('success', 'PDF uploaded successfully!');
    } catch (\Exception $e) {
        if ($request->ajax()) {
            return response()->json(['success' => false, 'error' => 'Upload failed: ' . $e->getMessage()], 500);
        }
        return redirect()->back()->with('error', 'Upload failed: ' . $e->getMessage());
    }
}
}
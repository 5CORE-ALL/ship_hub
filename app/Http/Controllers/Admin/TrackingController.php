<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceTrackingStat;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    /**
     * Display a listing of VTR (Valid Tracking Rate) analytics from the database.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $results = MarketplaceTrackingStat::where('marketplace_name','!=','ebay2')->orderBy('marketplace_name', 'asc')->get();

        // Calculate overall stats
        $totalOrders = $results->sum('total_orders');
        $totalValid = $results->sum('valid_tracking');
        $totalInvalid = $results->sum('invalid_tracking');
        $overallRate = $totalOrders > 0 ? round(($totalValid / $totalOrders) * 100, 1) : 0;

        return view('admin.tracking.index', compact('results', 'totalOrders', 'totalValid', 'totalInvalid', 'overallRate'));
    }

    /**
     * Show detailed VTR data for a specific marketplace stat.
     *
     * @param int $id
     * @return \Illuminate\View\View|\Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $stat = MarketplaceTrackingStat::findOrFail($id);

        return view('admin.tracking.show', compact('stat'));
    }

    /**
     * Trigger the VTR fetch command manually (for admin actions).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function fetch()
    {
        \Artisan::call('ebay:vtr-fetch');

        return redirect()->route('admin.tracking.index')
            ->with('success', 'VTR data fetched and updated successfully.');
    }
}
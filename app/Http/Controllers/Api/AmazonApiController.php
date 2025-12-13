<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class AmazonApiController extends Controller
{
      public function getCampaignReports(Request $request)
    {
        $request->validate([
            'ad_type' => ['nullable', 'string'],
            'marketplace' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $perPage = $request->input('per_page', 20);

        $query = DB::table('campaign_reports as cr')
            ->select(
                'cr.campaign_name',
                'cr.campaign_id',
                'cr.campaign_budget_amount',
                'cr.campaign_status',
                'cr.ad_type',
                'cr.marketplace',
                
                DB::raw('MAX(cr.start_date) AS campaign_start_date'),
                DB::raw('MAX(cr.end_date) AS campaign_end_date'),

                // L1
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.clicks ELSE 0 END) AS clicks_L1'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.cost ELSE 0 END) AS spend_L1'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.impressions ELSE 0 END) AS impressions_L1'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.orders ELSE 0 END) AS orders_L1'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.sales ELSE 0 END) AS sales_L1'),

                DB::raw('(SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.cost END)
                        / NULLIF(SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.clicks END), 0)
                        ) AS cpc_L1'),
                DB::raw("
                    CASE WHEN SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.sales END) > 0
                        THEN ((SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.cost END)
                            / SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.sales END)) * 100)
                        WHEN SUM(CASE WHEN DATE(cr.end_date) = CURDATE() - INTERVAL 1 DAY THEN cr.cost END) > 0 THEN 100
                        ELSE 0 
                    END AS acos_L1
                "),

                // L7
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.clicks ELSE 0 END) AS clicks_L7'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.cost ELSE 0 END) AS spend_L7'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.impressions ELSE 0 END) AS impressions_L7'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.orders ELSE 0 END) AS orders_L7'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.sales ELSE 0 END) AS sales_L7'),

                DB::raw('(SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.cost END)
                        / NULLIF(SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.clicks END), 0)
                        ) AS cpc_L7'),
                DB::raw("
                    CASE WHEN SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.sales END) > 0
                        THEN ((SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.cost END)
                            / SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.sales END)) * 100)
                        WHEN SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 7 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.cost END) > 0 THEN 100
                        ELSE 0 
                    END AS acos_L7
                "),
                
                // L30
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.clicks ELSE 0 END) AS clicks_L30'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.cost ELSE 0 END) AS spend_L30'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.impressions ELSE 0 END) AS impressions_L30'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.orders ELSE 0 END) AS orders_L30'),
                DB::raw('SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.sales ELSE 0 END) AS sales_L30'),

                DB::raw('(SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.cost END)
                        / NULLIF(SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.clicks END), 0)
                        ) AS cpc_L30'),
                DB::raw("
                    CASE WHEN SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.sales END) > 0
                        THEN ((SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.cost END)
                            / SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.sales END)) * 100)
                        WHEN SUM(CASE WHEN DATE(cr.end_date) >= CURDATE() - INTERVAL 30 DAY AND DATE(cr.end_date) < CURDATE() THEN cr.cost END) > 0 THEN 100
                        ELSE 0 
                    END AS acos_L30
                ")
            )
            ->groupBy(
                'cr.campaign_name',
                'cr.campaign_id',
                'cr.campaign_budget_amount',
                'cr.campaign_status',
                'cr.ad_type',
                'cr.marketplace'
            );

        if ($request->filled('ad_type')) {
            $query->where('cr.ad_type', $request->ad_type);
        }

        if ($request->filled('marketplace')) {
            $query->where('cr.marketplace', $request->marketplace);
        }

        return response()->json($query->paginate($perPage));
    }

    public function getCampaignTimeSeries(Request $request)
    {
        $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'campaign_name' => ['nullable', 'string'],   
            'ad_type' => ['nullable', 'string'],
            'marketplace' => ['nullable', 'string'],
        ]);

        $startDate = $request->input('start_date');
        $endDate   = $request->input('end_date');

        $query = DB::table('campaign_reports as cr')
            ->select(
                'cr.campaign_name',
                'cr.ad_type',
                'cr.marketplace',
                DB::raw('SUM(cr.impressions) AS total_impressions'),
                DB::raw('SUM(cr.clicks) AS total_clicks'),
                DB::raw('SUM(cr.cost) AS total_spend'),
                DB::raw('SUM(cr.sales) AS total_sales'),
                DB::raw('SUM(cr.orders) AS total_orders')
            )
            ->whereDate('cr.end_date', '>=', $startDate)
            ->whereDate('cr.end_date', '<=', $endDate)
            ->groupBy(
                'cr.campaign_name',
                'cr.ad_type',
                'cr.marketplace'
            );
            
            
        if ($request->filled('campaign_name')) {
            $query->where('cr.campaign_name', $request->campaign_name);
        }

        if ($request->filled('ad_type')) {
            $query->where('cr.ad_type', $request->ad_type);
        }

        if ($request->filled('marketplace')) {
            $query->where('cr.marketplace', $request->marketplace);
        }

        return response()->json($query->get());
    }
}

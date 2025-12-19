<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ContactMessage;
use App\Models\CourierSummary;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\Facades\DataTables;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
class AdminController extends Controller
{
    public function dashboard()
  {
     
    $branches   = Branch::count();
    $companies  = Company::count();
    $all_admin  = User::where('role', 'Admin')->count();
    $all_manager = User::where('role', 'Manager')->count();
    $all_staff  = User::where('role', 'Staff')->count();
    $orderItems = DB::table('order_items')
    ->select(
        'order_id',
        DB::raw("
            CASE 
                WHEN COUNT(DISTINCT sku) = 1 THEN 
                    CONCAT(MAX(sku), '-', SUM(quantity_ordered), 'pcs')
                ELSE 
                    CONCAT(COUNT(DISTINCT sku), ' SKUs')
            END AS item_sku
        ")
    )
    ->groupBy('order_id');

$all_courier = DB::table('orders as o')
    ->join('shipments as s', function ($join) {
        $join->on('s.order_id', '=', 'o.id')
             ->where('s.label_status', '=', 'active');
    })
    ->joinSub($orderItems, 'oi', function ($join) {
        $join->on('oi.order_id', '=', 'o.id');
    })
    ->where('o.order_status', 'shipped')
    ->where('o.printing_status', 1)
    ->count();
    $topLabelDay = DB::table('shipments')
    ->select(
        DB::raw('DATE(created_at) as label_date'),
        DB::raw('COUNT(id) as total_labels')
    )
    ->whereNotNull('label_id')
    ->groupBy(DB::raw('DATE(created_at)'))
    ->orderByDesc('total_labels')
    ->limit(1)
    ->first();
    $all_message = ContactMessage::count();
    // $pending_orders = Order::whereIn('order_status', [
    //     'Unshipped',
    //     'unshipped',
    //     'PartiallyShipped',
    //     'Accepted',
    //     'awaiting_shipment',
    // ])->count();
    $carrier = DB::table('carriers_list')
        ->where('account_number', 'kkalra-11331bl')
        ->select('balance')
        ->first();

    $balance = $carrier ? $carrier->balance : 0;
    $pending_orders = Order::query()
        ->join('order_items', 'orders.id', '=', 'order_items.order_id')
        ->leftJoin('order_shipping_rates', function ($join) {
            $join->on('orders.id', '=', 'order_shipping_rates.order_id')
                 ->where('order_shipping_rates.is_cheapest', 1);
        })
        ->where('orders.printing_status', 0)
        ->whereNotIn('orders.marketplace', ['walmart-s','ebay-s'])
        ->where(function ($q) {
            $q->whereNotIn('orders.source_name', ['ebay', 'ebay2', 'ebay3'])
              ->orWhereNull('orders.source_name');
        })
        ->whereIn('orders.marketplace', ['ebay1','ebay3','walmart','PLS','shopify','Best Buy USA',"Macy's, Inc.",'Reverb','amazon'])
        ->where('orders.queue',0)
        ->where('marked_as_ship',0)
        ->whereIn('orders.order_status', [
            'Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment','Created','Acknowledged','AWAITING_SHIPMENT','paid'
        ])
        ->where(function($query) {
            $query->whereNotIn('cancel_status', ['CANCELED', 'IN_PROGRESS'])
                  ->orWhereNull('cancel_status');
        })
        ->distinct('orders.id')
        ->count('orders.id');

  $orderItemsSub = DB::table('order_items')
    ->select(
        'order_id',
        DB::raw("CASE WHEN COUNT(id) > 1 THEN SUM(quantity_ordered) ELSE MAX(sku) END AS item_sku")
    )
    ->groupBy('order_id');

$total_labels_created = Shipment::join('orders as o', 'shipments.order_id', '=', 'o.id')
    ->joinSub($orderItemsSub, 'oi', function ($join) {
        $join->on('oi.order_id', '=', 'shipments.order_id');
    })
    ->where('shipments.void_status', 'active')
    ->where('o.label_source', 'api')
    ->where('o.order_status', 'shipped')
    ->whereIn('o.printing_status', [1, 2])
    ->count();
    $fromDate =now()->toDateString();
    $toDate   =  now()->toDateString();
    $orderItems = DB::table('order_items')
        ->select('order_id', DB::raw("SUM(weight) as total_weight"))
        ->groupBy('order_id');

    $query = DB::table('orders as o')
        ->join('shipments as s', function ($join) {
            $join->on('s.order_id', '=', 'o.id')
                 ->where('s.label_status', 'active');
        })
        ->joinSub($orderItems, 'oi', function ($join) {
            $join->on('oi.order_id', '=', 'o.id');
        })
        ->where('o.order_status', 'shipped')
        ->where('o.printing_status', 2)
        ->whereBetween(DB::raw('DATE(s.created_at)'), [$fromDate, $toDate]);



    $shipped_orders = $query->count();

    $connected_marketplaces = Order::query()
    ->whereIn('marketplace', ['ebay1','ebay3','walmart','Reverb','PLS','shopify','Best Buy USA',"Macy's, Inc.",'amazon'])
    ->distinct('marketplace')
    ->count('marketplace');
    $dailySummary = DB::table('bulk_shipping_histories')
    ->selectRaw('DATE(created_at) as day')
    ->selectRaw('SUM(order_count) as total_labels')
    ->selectRaw('SUM(success) as total_success')
    ->selectRaw('SUM(failed) as total_failed')
    ->whereDate('created_at', Carbon::today())
    ->groupBy(DB::raw('DATE(created_at)'))
    ->orderBy('day', 'desc')
    ->get();

    return view('admin.dashboard', compact(
        'branches',
        'companies',
        'all_admin',
        'all_manager',
        'all_staff',
        'all_courier',
        'all_message',
        'pending_orders',
        'shipped_orders',
        'connected_marketplaces',
        'total_labels_created',
        'dailySummary',
        'balance',
        'topLabelDay'
    ));
  }


    public function allManager(Request $request)
    {
        if($request->ajax()){
            $all_manager = "";
            $query = User::where('role', 'Manager')->leftJoin('branches', 'users.branch_id', 'branches.id');

            if($request->status){
                $query->where('users.status', $request->status);
            }

            $all_manager = $query->select('users.*', 'branches.branch_name')->get();

            return DataTables::of($all_manager)
            ->addIndexColumn()
            ->editColumn('branch_name', function($row){
                return'
                <span class="badge bg-dark">'.$row->branch_name.'</span>
                ';
            })
            ->editColumn('status', function($row){
                if($row->status == 'Active'){
                    $status = '
                    <span class="badge bg-success">'.$row->status.'</span>
                    <button type="button" data-id="'.$row->id.'" class="btn btn-warning btn-sm statusBtn"><i class="bi bi-hand-thumbs-down-fill"></i></button>
                    ';
                }else{
                    $status = '
                    <span class="badge bg-warning">'.$row->status.'</span>
                    <button type="button" data-id="'.$row->id.'" class="btn btn-success btn-sm statusBtn"><i class="bi bi-hand-thumbs-up-fill"></i></button>
                    ';
                };
                return $status;
            })
            ->addColumn('action', function ($row) {
                $btn = '
                    <button type="button" data-id="'.$row->id.'" class="btn btn-warning btn-sm editBtn" data-bs-toggle="modal" data-bs-target="#editModal"><i class="bi bi-pencil-fill"></i></button>
                    <button type="button" data-id="'.$row->id.'" class="btn btn-success btn-sm viewBtn" data-bs-toggle="modal" data-bs-target="#viewModal"><i class="bi bi-eye"></i></button>
                ';
                return $btn;
            })
            ->rawColumns(['branch_name', 'status', 'action'])
            ->make(true);
        }

        $branchs = Branch::where('status', 'Active')->get();
        return view('admin.manager.index', compact('branchs'));
    }

    public function managerRegister(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*' => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                'status' => 400,
                'error'=> $validator->errors()->toArray()
            ]);
        }else{
            User::create($request->except(['password', 'password_confirmation'])+[
                'role' => 'Manager',
                'password' => Hash::make($request->password),
            ]);
        }
    }

    public function managerEdit(string $id)
    {
        $manager = User::where('id', $id)->first();
        return response()->json($manager);
    }

    public function managerUpdate(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            '*' => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                'status' => 400,
                'error'=> $validator->errors()->toArray()
            ]);
        }else{
            $manager = User::findOrFail($id);
            $manager->update($request->all());
        }
    }

    public function managerStatus($id)
    {
        $user = User::findOrFail($id);
        if($user->status == "Active"){
            $user->status = "Inactive";
        }else{
            $user->status = "Active";
        }
        $user->save();
    }

    public function managerView($id)
    {
        $manager = User::where('id', $id)->first();
        return view('admin.manager.view', compact('manager'));
    }
}

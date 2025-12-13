<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SalesChannel;

class StoreConnectController extends Controller
{
     public function stores()
     {
           $salesChannels = SalesChannel::where('status', 'active')->get();
           return view('admin.setting.stores',compact('salesChannels'));
     }

}

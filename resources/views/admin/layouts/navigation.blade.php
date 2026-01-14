<style>
/* Sidebar container */
.sidebar {
    width: 70px; /* collapsed width */
    background-color: #1e1e2d;
    height: 100vh;
    overflow-x: hidden;
    overflow-y: auto;
    transition: width 0.3s ease;
    position: fixed;
    z-index: 100;
}

/* Expand sidebar on hover */
.sidebar:hover {
    width: 240px;
}

/* Menu links */
.metismenu a {
    display: flex;
    align-items: center;
    color: #b5b5c3;
    padding: 10px 15px;
    white-space: nowrap;
    overflow: hidden;
    text-decoration: none;
    border-radius: 8px;
    transition: background 0.2s, color 0.2s;
}

/* Show text only when expanded */
.sidebar:not(:hover) .menu-title {
    display: none;
}

.metismenu a:hover {
    background-color: #0d6efd;
    color: #fff;
}

.metismenu .parent-icon {
    width: 24px;
    text-align: center;
    font-size: 18px;
    margin-right: 10px;
}

/* Section labels */
.menu-section {
    color: #888;
    font-size: 11px;
    text-transform: uppercase;
    margin: 15px 10px 5px 20px;
    transition: opacity 0.2s;
}

/* Hide labels when collapsed */
.sidebar:not(:hover) .menu-section {
    opacity: 0;
}

/* Scrollbar styling */
.sidebar::-webkit-scrollbar {
    width: 4px;
}
.sidebar::-webkit-scrollbar-thumb {
    background-color: #444;
    border-radius: 4px;
}

</style>
<ul class="metismenu" id="menu">
    <li>
        <a href="{{ route('dashboard') }}">
            <div class="parent-icon"><i class="bi bi-house"></i></div>
            <div class="menu-title">Dashboard</div>
        </a>
    </li>

    @if (Auth::user()->role == "Super Admin")
    <!-- <li class="menu-label">Super Admin Panel</li> -->
    @can('view_users')
    <li>
        <a href="javascript:;" class="has-arrow">
            <div class="parent-icon"><i class="bi bi-gear"></i></div>
            <div class="menu-title">Setting</div>
        </a>
        <ul>
            @can('view_users')
            <li>
                <a href="{{ route('users.index') }}"><i class="bi bi-globe"></i>Users</a>
            </li>
            @endcan
        </ul>
    </li>
    @endcan
<!--   <li>
        <a href="javascript:;" class="has-arrow">
            <div class="parent-icon"><i class="bi bi-file"></i></div>
            <div class="menu-title">Report</div>
        </a>
        <ul>
            <li>
                <a href="{{ route('report.courier') }}"><i class="bi bi-bag"></i>Courier</a>
            </li>
            <li>
                <a href="{{ route('report.income') }}"><i class="bi bi-calculator"></i>Income</a>
            </li>
        </ul>
    </li> -->

    <li>
        <a href="javascript:;" class="has-arrow">
            <div class="parent-icon"><i class="bi bi-cart"></i></div>
            <div class="menu-title">Orders</div>
        </a>
       <ul>
          <li>
          <a href="{{ route('manual-orders.index') }}">
              <i class="bi bi-plus-circle"></i> Manual Orders
          </a>
      </li>
      <li>
          <a href="{{ route('doba-orders.index') }}">
              <i class="bi bi-box-seam"></i> DOBA Orders
          </a>
      </li>
    <li>
      <a href="{{ route('awaiting-shipment.index') }}" class="has-arrow">
        <i class="bi bi-clock"></i> Awaiting Shipment
    </a>
    </li>
  
 <!--    <li>
        <a href="#" class="has-arrow"><i class="bi bi-truck"></i>On Hold</a> -->
        <!-- <ul>
            <li><a href="#" class="has-arrow"><i class="bi bi-gear"></i>Manage Orders</a></li>
        </ul> -->
    <!-- </li> -->
<!--     <li>
        <a href="#" class="has-arrow"><i class="bi bi-check-circle"></i>Awaiting Shipment</a> -->
        <!-- <ul>
            <li><a href="#" class="has-arrow"><i class="bi bi-gear"></i>Manage Orders</a></li>
        </ul> -->
    <!-- </li> -->
        <li>
            <a href="{{ route('awaiting-print.index') }}" class="has-arrow">
                <i class="bi bi-printer"></i> Awaiting Print
            </a>
        </li>
        <a href="{{ route('shipped-orders.index') }}" class="has-arrow"><i class="bi bi-check-circle"></i>Printed</a>
      </li> 
      <li>
            <a href="{{ route('dispatch.report.index') }}"><i class="bi bi-truck"></i>Manage Dispatches</a>
        </li>
       
<!--     <li>
        <a href="#" class="has-arrow"><i class="bi bi-check-circle"></i>Cancelled</a> -->
        <!-- <ul>
            <li><a href="#" class="has-arrow"><i class="bi bi-gear"></i>Manage Orders</a></li>
        </ul> -->
    <!-- </li> -->
    <!-- <li>
        <a href="#" class="has-arrow"><i class="bi bi-check-circle"></i>Orders Alerts</a> -->
        <!-- <ul>
            <li><a href="#" class="has-arrow"><i class="bi bi-gear"></i>Manage Orders</a></li>
        </ul> -->
    <!-- </li> -->
</ul>
    </li>
    <li>
      <!--   <a href="javascript:;" class="has-arrow">
            <div class="parent-icon"><i class="bi bi-file-earmark-break-fill"></i></div>
            <div class="menu-title">Page</div>
        </a> -->
      <!--   <ul>
            <li>
                <a href="{{ route('about.us.page') }}"><i class="bi bi-file-earmark-person"></i>About Us</a>
            </li>
            <li>
                <a href="{{ route('privacy.policy.page') }}"><i class="bi bi-shield-exclamation"></i>Privacy Policy</a>
            </li>
            <li>
                <a href="{{ route('terms.of.service.page') }}"><i class="bi bi-book"></i>Terms of Service</a>
            </li>
        </ul> -->
    </li>
    <!-- <li>
            <a href="javascript:;" class="has-arrow">
                <div class="parent-icon"><i class="bi bi-clock-history"></i></div>
                <div class="menu-title">History</div>
            </a>
            <ul>
                <li>
                     <a href="{{ route('bulk.label.history') }}"><i class="bi bi-cart-check"></i>Bulk Label History</a>
                </li>
            </ul>
    </li> -->
   <!--  <li>
            <a href="{{ route('tracking.index') }}">
                <div class="parent-icon"><i class="bi bi-graph-up"></i></div>
                <div class="menu-title">Tracking Stats</div>
            </a>
        </li> -->
<li>
    <a href="javascript:;" class="has-arrow">
        <div class="parent-icon"><i class="bi bi-bar-chart-line"></i></div>
        <div class="menu-title">Reports</div>
    </a>
    <ul>
        <li>
            <a href="{{ route('cancellation-report.index') }}">
                <i class="bi bi-x-circle"></i>Cancellation Report
            </a>
        </li>
        <li>
            <a href="{{ route('bulk.label.history') }}"><i class="bi bi-cart-check"></i>Bulk Label History</a>
        </li>
        <li>
            <a href="{{ route('dispatch.report.index') }}"><i class="bi bi-truck"></i>Dispatch Report</a>
        </li>
        <li>
            <a href="{{ route('tracking.index') }}">
                <i class="bi bi-graph-up"></i>Tracking Stats
            </a>
        </li>
        <li>
            <a href="{{ route('pdfs.index') }}">
                <i class="bi bi-file-earmark-pdf"></i> Shipping PDFs
            </a>
        </li>
    </ul>
</li>


    @endif

    @if (Auth::user()->role == "Super Admin" || Auth::user()->role == "Admin")
   <!--  <li class="menu-label">Admin Panel</li>
    <li>
        <a href="{{ route('branch.index') }}">
            <div class="parent-icon"><i class="bi bi-shop"></i></div>
            <div class="menu-title">Branch</div>
        </a>
    </li> -->
    <!-- <li>
        <a href="{{ route('all.manager') }}">
            <div class="parent-icon"><i class="bi bi-people-fill"></i></div>
            <div class="menu-title">All Manager</div>
        </a>
    </li>
    <li>
        <a href="{{ route('unit.index') }}">
            <div class="parent-icon"><i class="bi bi-bag"></i></div>
            <div class="menu-title">Unit</div>
        </a>
    </li>
    <li>
        <a href="{{ route('cost.index') }}">
            <div class="parent-icon"><i class="bi bi-calculator"></i></div>
            <div class="menu-title">Cost</div>
        </a>
    </li>
    <li>
        <a href="{{ route('company.index') }}">
            <div class="parent-icon"><i class="bi bi-briefcase"></i></div>
            <div class="menu-title">Company</div>
        </a>
    </li> -->
    <!-- <li>
        <a href="javascript:;" class="has-arrow">
            <div class="parent-icon"><i class="bi bi-globe2"></i></div>
            <div class="menu-title">Frontend</div>
        </a>
        <ul>
            <li>
                <a href="{{ route('service.index') }}"><i class="bi bi-hdd-rack"></i>Service</a>
            </li>
            <li>
                <a href="{{ route('testimonial.index') }}"><i class="bi bi-blockquote-left"></i>Testimonial</a>
            </li>
        </ul>
    </li> -->
  <!--   <li>
        <a href="{{ route('contact.message.index') }}">
            <div class="parent-icon"><i class="bi bi-chat"></i></div>
            <div class="menu-title">Contact Message</div>
        </a>
    </li> -->
    @endif

    @if (Auth::user()->role == "Manager")
    <li class="menu-label">Manager Panel</li>
    <li>
        <a href="{{ route('all.staff') }}">
            <div class="parent-icon"><i class="bi bi-people-fill"></i></div>
            <div class="menu-title">All Staff</div>
        </a>
    </li>
    <li>
        <a href="javascript:;" class="has-arrow">
            <div class="parent-icon"><i class="bi bi-cursor-fill"></i>
            </div>
            <div class="menu-title">Send Courier List</div>
        </a>
        <ul>
            <li>
                <a href="{{ route('send.courier.list.processing') }}"><i class="bi bi-list-stars"></i>Processing</a>
            </li>
            <li>
                <a href="{{ route('send.courier.list.delivered') }}"><i class="bi bi-card-checklist"></i>Delivered</a>
            </li>
        </ul>
    </li>
    <li>
        <a href="javascript:;" class="has-arrow">
            <div class="parent-icon"><i class="bi bi-gift"></i>
            </div>
            <div class="menu-title">Delivery Courier List</div>
        </a>
        <ul>
            <li>
                <a href="{{ route('delivery.courier.list.processing') }}"><i class="bi bi-list-stars"></i>Processing</a>
            </li>
            <li>
                <a href="{{ route('delivery.courier.list.delivered') }}"><i class="bi bi-card-checklist"></i>Delivered</a>
            </li>
        </ul>
    </li>
    @endif

    @if (Auth::user()->role == "Staff")
    <li class="menu-label">Staff Panel</li>
    <li>
        <a href="javascript:;" class="has-arrow">
            <div class="parent-icon"><i class="bi bi-arrow-right-square-fill"></i>
            </div>
            <div class="menu-title">Send Courier</div>
        </a>
        <ul>
            <li>
                <a href="{{ route('send.courier') }}"><i class="bi bi-cursor-fill"></i>Send Courier</a>
            </li>
            <li>
                <a href="{{ route('send.courier.list') }}"><i class="bi bi-card-checklist"></i>Courier List</a>
            </li>
        </ul>
    </li>
    <li>
        <a href="javascript:;" class="has-arrow">
            <div class="parent-icon"><i class="bi bi-sort-down-alt"></i>
            </div>
            <div class="menu-title">Delivery Courier</div>
        </a>
        <ul>
            <li>
                <a href="{{ route('delivery.courier') }}"><i class="bi bi-gift"></i>Delivery Courier</a>
            </li>
            <li>
                <a href="{{ route('delivery.courier.list') }}"><i class="bi bi-card-checklist"></i>Courier List</a>
            </li>
        </ul>
    </li>
    @endif
</ul>

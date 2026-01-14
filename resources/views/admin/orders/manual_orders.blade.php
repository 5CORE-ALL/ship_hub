@extends('admin.layouts.admin_master')
@section('title', 'Manual Orders')
@section('content')
<style>
/* Apply same gradient to ALL fixed left header columns */
.DTFC_LeftHeadWrapper th {
    background: linear-gradient(to right, #3f51b5, #5c6bc0) !important;
    color: #fff !important;
    font-weight: bold;
}
.btn-primary:disabled {
    background-color: #ccc;
    border-color: #ccc;
    color: #fff;
    cursor: not-allowed;
}
/* Style for DataTables buttons */
.dt-buttons .btn-primary {
    padding: 0.5rem 1.5rem;
    font-weight: 500;
    margin-left: 8px;
}
/* Apply gradient to ALL fixed right header columns */
.DTFC_RightHeadWrapper th {
    background: linear-gradient(to right, #009688, #26a69a) !important;
    color: #fff !important;
    font-weight: bold;
}
/* Normal (scrollable) header columns */
.dataTables_wrapper .dataTable thead th {
    background: linear-gradient(to right, #3f51b5, #5c6bc0) !important;
    color: #fff !important;
    font-weight: bold;
}
/* Style for editable cells */
.editable-cell {
    cursor: pointer;
    transition: background-color 0.2s;
}
.editable-cell:hover {
    background-color: #f0f0f0;
}
.editing-cell {
    background-color: #fff;
    padding: 0;
}
.editing-cell input {
    width: 100%;
    border: none;
    padding: 5px;
    outline: none;
    box-sizing: border-box;
}
/* Orange button and text styles */
.text-orange { color: #ff6a00; }
.btn-orange { background: #ff6a00; color: #fff; border: none; }
.btn-orange:hover { background: #e65c00; }
/* Weight filter button active state */
.weight-filter-btn.active {
    background-color: #3f51b5;
    color: #fff;
    border-color: #3f51b5;
}
/* Verify button styles */
.verify-btn {
    padding: 2px 6px;
    font-size: 0.8rem;
    background-color: #28a745;
    color: #fff;
    border: none;
}
.verify-btn:hover {
    background-color: #218838;
}
.verify-btn:disabled {
    background-color: #ccc;
    cursor: not-allowed;
}
/* Modal styling for professionalism */
.modal-content {
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}
.modal-header {
    background-color: #3f51b5;
    color: #fff;
    border-bottom: 1px solid #e9ecef;
    border-top-left-radius: 8px;
    border-top-right-radius: 8px;
}
.modal-title {
    font-size: 1.25rem;
    font-weight: 600;
}
.modal-body {
    padding: 2rem;
    font-size: 1rem;
    color: #333;
}
.modal-footer {
    border-top: 1px solid #e9ecef;
    justify-content: center;
    padding: 1rem;
}
.btn-primary {
    background-color: #3f51b5;
    border-color: #3f51b5;
    padding: 0.5rem 1.5rem;
    font-weight: 500;
}
.btn-primary:hover {
    background-color: #303f9f;
    border-color: #303f9f;
}
</style>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
<!-- Carrier List Modal -->
<div class="modal fade" id="changeCarrierModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-md">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Change Carrier</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="carrierList" class="list-group list-group-flush overflow-auto" style="max-height: 400px;">
          <!-- Carriers will be loaded here -->
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>
<!-- Order Details Modal -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header bg-primary text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold">Order Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="orderDetailsContent">
                <!-- Dynamic content will be appended here -->
            </div>
            <div class="modal-footer border-0 justify-content-center">
                <button class="btn btn-primary btn-lg shadow-sm" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>
<!-- Edit Address Modal -->
<div class="modal fade" id="editAddressModal" tabindex="-1" aria-labelledby="editAddressModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="editAddressForm">
        <div class="modal-header">
          <h5 class="modal-title" id="editAddressModalLabel">Edit Shipping Address</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="order_id" id="editOrderId">
            <input type="hidden" name="is_address_verified" id="editIsAddressVerified">
            <div class="mb-2">
                <label class="form-label">Recipient Name</label>
                <input type="text" class="form-control" name="recipient_name" id="editRecipientName" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Address 1</label>
                <input type="text" class="form-control" name="ship_address1" id="editShipAddress1" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Address 2</label>
                <input type="text" class="form-control" name="ship_address2" id="editShipAddress2">
            </div>
            <div class="mb-2">
                <label class="form-label">City</label>
                <input type="text" class="form-control" name="ship_city" id="editShipCity" required>
            </div>
            <div class="mb-2">
                <label class="form-label">State</label>
                <input type="text" class="form-control" name="ship_state" id="editShipState" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Postal Code</label>
                <input type="text" class="form-control" name="ship_postal_code" id="editShipPostalCode" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Country</label>
                <input type="text" class="form-control" name="ship_country" id="editShipCountry" required>
            </div>
            <div class="mb-3" id="verifyButtonContainer">
                <button type="button" class="btn verify-btn" id="verifyAddressBtn">Verify Address</button>
                <small class="text-muted">Click to verify the address using our validation service.</small>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Address</button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- Edit SKU and Quantity Modal -->
<div class="modal fade" id="editItemsModal" tabindex="-1" aria-labelledby="editItemsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="editItemsForm">
        <div class="modal-header">
          <h5 class="modal-title" id="editItemsModalLabel">Edit Order Items</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="order_id" id="editItemsOrderId">
          <div id="itemsList">
            <!-- Dynamic content will be appended here -->
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- Weight Info Modal -->
<div class="modal fade" id="weightInfoModal" tabindex="-1" aria-labelledby="weightInfoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="weightInfoModalLabel">Action Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 2rem;"></i>
                    <p class="mt-3 mb-0" style="font-size: 1.1rem; color: #444;">
                        Please ensure the package weight is specified before proceeding.
                        Update the weight in the "Weight" column to enable shipping options.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Understood</button>
            </div>
        </div>
    </div>
</div>
<div class="p-3">
    <div id="loader" class="loader">
        <div class="loader-content">
            <div class="spinner"></div>
            <span id="loaderText">Generating Label...</span>
        </div>
    </div>
   <!--   <div class="page-title d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Manual Orders</h5>
        <div>
           <a href="{{ route('manual-orders.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Manual Orders
            </a>
        </div>
    </div> -->
    <div class="page-title d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Manual Orders</h5>
        <div class="d-flex align-items-center gap-2">
            <!-- Weight Filter Buttons -->
            <div class="btn-group" role="group" aria-label="Weight Filter">
                <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="all">All Weights</button>
                <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="0.25">0.25lb</button>
                <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="0.5">0.5lb</button>
                <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="0.75">0.75lb</button>
                <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="1-6">1lb–5lb</button>
                <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="6-20">6lb–20lb</button>
                <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="20+">>20lb</button>
            </div>
            <!-- Existing Buttons -->
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rightModal">
                <i class="bi bi-funnel"></i> Filter
            </button>
            <button type="button" class="btn btn-primary bulk-buy-shipping-btn" disabled>
                Buy Bulk Shipping
            </button>
            <a href="{{ route('manual-orders.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Manual Orders
            </a>
        </div>
    </div>
    <div class="modal right fade" id="rightModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Filters</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Platform</label>
                        <select class="form-select" id="filterMarketplace">
                            <option value="">-- Select Platform --</option>
                            @foreach($marketplaces as $channel)
                                <option value="{{ strtolower($channel) }}">{{ ucfirst($channel) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">From Date</label>
                        <input type="date" class="form-control" id="filterFromDate">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">To Date</label>
                        <input type="date" class="form-control" id="filterToDate">
                    </div>
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" id="resetFilters">Reset</button>
                        <button type="button" class="btn btn-primary" id="applyFilters">Apply Filters</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12 col-12" id="tableCol">
            <table id="shipmentTable" class="shipment-table table table-bordered nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Marketplace</th>
                        <th>Order Number</th>
                        <th>Order Age</th>
                        <th>Order Date</th>
                        <th>SKU</th>
                        <th>Height (H)</th>
                        <th>Width (W)</th>
                        <th>Length (L)</th>
                        <th>Weight</th>
                        <th>Recipient</th>
                        <th>Qty</th>
                        <th>Order Total</th>
                        <th>Default Rate</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
@endsection
@section('script')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const columnVisibilitySettings = @json($columns);
    let selectedWeightRange = 'all';
    $(document).ready(function() {
        const columnVisibilityMap = {};
        columnVisibilitySettings.forEach(col => {
            columnVisibilityMap[col.column_name] = col.is_visible === 1;
        });
        $(document).on('click', '.buy-shipping-btn', function() {
            let orderId = $(this).data('order-id');
            let url = "{{ route('orders.buy-shipping', ':id') }}".replace(':id', orderId);
            window.open(url, '_blank');
        });
        const shippingServices = @json($services);
        let table = $('#shipmentTable').DataTable({
            processing: true,
            serverSide: true,
            scrollX: true,
            ajax: {
                url: '{{ route("manual-orders.data") }}',
                data: function(d) {
                    d.channels = $('.sales-channel-filter:checked').map(function() {
                        return this.value;
                    }).get();
                    d.marketplace = $('#filterMarketplace').val();
                    d.from_date = $('#filterFromDate').val();
                    d.to_date = $('#filterToDate').val();
                    d.weight_range = selectedWeightRange;
                }
            },
            dom: 'lBfrtip',
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="bi bi-eye"></i> Columns Show/hide',
                    columns: ':not(:last-child)',
                }
            ],
            columns: [
                {
                    data: 'id',
                    title: '<input type="checkbox" id="selectAll">',
                    render: function(data, type, row) {
                        if (row.weight != null && row.default_price != null && parseFloat(row.weight) > 0) {
                            return `<input type="checkbox" class="order-checkbox" value="${data}">`;
                        }
                        return `<a href="#" class="text-info info-icon" data-bs-toggle="modal" data-bs-target="#weightInfoModal" title="Click for info"><i class="fas fa-info-circle"></i></a>`;
                    },
                    orderable: false,
                    searchable: false,
                    className: 'text-center'
                },
                {
                    data: 'marketplace',
                    title: 'Marketplace',
                    render: function(data, type, row) {
                        if (data === 'amazon') {
                            return '<span class="badge bg-success">Amazon</span>';
                        } else if (data === 'Reverb') {
                            return '<span class="badge bg-primary">Reverb</span>';
                        } else if (data === 'walmart') {
                            return '<span class="badge bg-warning text-dark">Walmart</span>';
                        } else if (data === 'TikTok') {
                            return '<span class="badge" style="background-color: #ee1d52; color: #fff;">TikTok</span>';
                        } else if (data === 'Temu') {
                            return '<span class="badge" style="background-color: #ff6200; color: #fff;">Temu</span>';
                        } else if (data === 'shopify') {
                            return '<span class="badge bg-info text-dark">Shopify</span>';
                        } else if (data.startsWith('ebay')) {
                            return '<span class="badge bg-danger">' + data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
                        } else if (data === 'Best Buy USA') {
                            return '<span class="badge" style="background-color: #0046be; color: #fff;">' + data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
                        } else {
                            return '<span class="badge bg-secondary">' + data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
                        }
                    },
                    visible: columnVisibilityMap['marketplace'] !== undefined ? columnVisibilityMap['marketplace'] : true
                },
                {
                    data: 'order_number',
                    title: 'Order Number',
                    visible: columnVisibilityMap['order_number'] !== undefined ? columnVisibilityMap['order_number'] : true
                },
                {
                    data: 'order_date',
                    title: 'Order Age',
                    render: function(data, type, row) {
                        if (!data) return '';
                        const orderDate = new Date(data);
                        const now = new Date();
                        let diffMs = now.getTime() - orderDate.getTime();
                        if (diffMs < 0) diffMs = 0;
                        const diffSec = Math.floor(diffMs / 1000);
                        const diffMin = Math.floor(diffSec / 60);
                        const diffHour = Math.floor(diffMin / 60);
                        const diffDay = Math.floor(diffHour / 24);
                        if (diffSec < 60) return `${diffSec} sec ago`;
                        if (diffMin < 60) return `${diffMin} min ago`;
                        if (diffHour < 24) return `${diffHour} hr ago`;
                        return `${diffDay} day${diffDay > 1 ? 's' : ''} ago`;
                    },
                    visible: columnVisibilityMap['order_date'] !== undefined ? columnVisibilityMap['order_date'] : true
                },
                {
                    data: 'order_date',
                    title: 'Order Date',
                    render: function(data) {
                        if (!data) return '';
                        let date = new Date(data);
                        let options = {
                            year: 'numeric', month: '2-digit', day: '2-digit',
                            hour: '2-digit', minute: '2-digit', second: '2-digit',
                            timeZone: 'America/New_York'
                        };
                        return new Intl.DateTimeFormat('en-GB', options).format(date);
                    },
                    visible: columnVisibilityMap['order_date'] !== undefined ? columnVisibilityMap['order_date'] : true
                },
                {
                    data: 'sku',
                    title: 'SKU',
                    render: function(data, type, row) {
                        return `
                            <span>${data || '—'}</span>
                            <button class="btn btn-sm btn-link text-primary ms-2 edit-items-btn" data-order-id="${row.id}" title="Edit Items">
                                <i class="fas fa-edit"></i>
                            </button>
                        `;
                    },
                    visible: columnVisibilityMap['sku'] !== undefined ? columnVisibilityMap['sku'] : true
                },
                {
                    data: 'height',
                    title: 'Height (H)',
                    className: 'text-end editable-cell',
                    render: function(data) {
                        return data || '—';
                    },
                    visible: columnVisibilityMap['height'] !== undefined ? columnVisibilityMap['height'] : true
                },
                {
                    data: 'width',
                    title: 'Width (W)',
                    className: 'text-end editable-cell',
                    render: function(data) {
                        return data || '—';
                    },
                    visible: columnVisibilityMap['width'] !== undefined ? columnVisibilityMap['width'] : true
                },
                {
                    data: 'length',
                    title: 'Length (L)',
                    className: 'text-end editable-cell',
                    render: function(data) {
                        return data || '—';
                    },
                    visible: columnVisibilityMap['length'] !== undefined ? columnVisibilityMap['length'] : true
                },
                {
                    data: 'weight',
                    title: 'Weight',
                    className: 'text-end editable-cell',
                    render: function(data) {
                        return data || '—';
                    },
                    visible: columnVisibilityMap['weight'] !== undefined ? columnVisibilityMap['weight'] : true
                },
                {
                    data: 'recipient_name',
                    title: 'Recipient',
                    render: function(data, type, row) {
                        if (!data || data.trim() === '') {
                            return '—';
                        }
                        const verificationMark = row.is_address_verified === 0
                            ? ' <i class="fas fa-exclamation-circle text-warning" title="Address not verified"></i>'
                            : '';
                        return `
                            <a href="#" class="recipient-link" data-order-id="${row.id}">
                                ${data}${verificationMark}
                            </a>
                            <button class="btn btn-sm btn-link text-primary ms-2 edit-address-btn" data-order='${JSON.stringify(row).replace(/'/g, "&apos;")}' title="Edit Address">
                                <i class="fas fa-edit"></i>
                            </button>
                        `;
                    },
                    visible: columnVisibilityMap['recipient_name'] !== undefined ? columnVisibilityMap['recipient_name'] : true
                },
                {
                    data: 'quantity',
                    title: 'Qty',
                    className: 'text-end',
                    render: function(data, type, row) {
                        return `
                            <span>${data || '—'}</span>
                            <button class="btn btn-sm btn-link text-primary ms-2 edit-items-btn" data-order-id="${row.id}" title="Edit Items">
                                <i class="fas fa-edit"></i>
                            </button>
                        `;
                    },
                    visible: columnVisibilityMap['quantity'] !== undefined ? columnVisibilityMap['quantity'] : true
                },
                {
                    data: 'order_total',
                    title: 'Order Total',
                    render: $.fn.dataTable.render.number(',', '.', 2, '$'),
                    className: 'text-end',
                    visible: columnVisibilityMap['order_total'] !== undefined ? columnVisibilityMap['order_total'] : true
                },
                {
                    data: null,
                    title: 'Default Rate',
                    render: function(data, type, row) {
                        let defaultCarrier = row.default_carrier || '—';
                        let defaultPrice = row.default_price != null ? `$${parseFloat(row.default_price).toFixed(2)}` : '—';
                        let defaultSource = row.default_source || '—';
                        return `
                            <span>${defaultCarrier} ${defaultPrice} <small class="text-muted">(${defaultSource})</small></span>
                            <button class="btn btn-sm btn-link text-primary ms-2 edit-carrier-btn" data-order='${JSON.stringify(row).replace(/'/g,"&apos;")}' title="Change Carrier">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        `;
                    },
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    visible: columnVisibilityMap['default_carrier'] !== undefined ? columnVisibilityMap['default_carrier'] : true
                }
            ],
            order: [[4, 'asc']],
            pageLength: 50,
            lengthMenu: [50, 25, 50, 100],
        });
        // Set default weight filter
        $('.weight-filter-btn[data-weight-range="all"]').addClass('active');
        // Handle weight filter button clicks
        $('.weight-filter-btn').on('click', function() {
            $('.weight-filter-btn').removeClass('active');
            $(this).addClass('active');
            selectedWeightRange = $(this).data('weight-range');
            table.ajax.reload();
        });
        table.on('column-visibility.dt', function(e, settings, column, state) {
            let columnName = settings.aoColumns[column].data;
            let screenName = 'awaiting_shipment';
            $.ajax({
                url: "{{ route('user-columns.save') }}",
                type: "POST",
                data: {
                    _token: '{{ csrf_token() }}',
                    screen_name: screenName,
                    column_name: columnName,
                    is_visible: state ? 1 : 0,
                    order_index: column
                }
            });
        });
        $('#selectAll').on('change', function() {
            $('.order-checkbox', table.rows().nodes()).prop('checked', this.checked);
            updateBulkButtonState();
        });
        $('#shipmentTable').on('change', '.order-checkbox', function() {
            updateBulkButtonState();
        });
        function updateBulkButtonState() {
            let selectedCount = $('.order-checkbox:checked').length;
            $('.bulk-buy-shipping-btn').prop('disabled', selectedCount === 0);
        }
        $('.bulk-buy-shipping-btn').on('click', function() {
            let selectedOrders = $('.order-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            if (selectedOrders.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Selection Required',
                    text: 'Please select at least one order to buy bulk shipping.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            Swal.fire({
                icon: 'info',
                title: 'Buy Bulk Shipping',
                html: `
                    Are you sure you want to buy shipping for ${selectedOrders.length} orders?
                    <br><br>
                    <div class="alert alert-warning small mt-2">
                        <i class="bi bi-info-circle"></i> Please ensure sufficient balance is available in your shipping carrier account before proceeding.
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Yes',
                cancelButtonText: 'No'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#loader').show();
                    $('body').addClass('loader-active');
                    $.ajax({
                        url: "{{ route('orders.bulk-shipping') }}",
                        type: 'POST',
                        data: {
                            order_ids: selectedOrders,
                            _token: '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            $('#loader').hide();
                            $('body').removeClass('loader-active');
                            if (response.success) {
                                let downloadLink = '';
                                let summaryHtml = '';
                                let failedDetailsHtml = '';
                                
                                // Check if there are any failures (partial success scenario)
                                const hasFailures = response.summary && response.summary.failed_count > 0;
                                const hasFailedDetails = response.failed_details && response.failed_details.length > 0;
                                
                                if (response.labels && response.labels.summary) {
                                    summaryHtml = `<br><br><strong>Summary:</strong><br>
                                        Total Orders Processed: ${response.labels.summary.total_processed}<br>
                                        Successfully Processed: ${response.labels.summary.success_count}<br>
                                        Failed: ${response.labels.summary.failed_count}`;
                                } else if (response.summary) {
                                    summaryHtml = `<br><br><strong>Summary:</strong><br>
                                        Total Orders Processed: ${response.summary.total_processed || 0}<br>
                                        Successfully Processed: ${response.summary.success_count || 0}<br>
                                        Failed: ${response.summary.failed_count || 0}`;
                                }
                                
                                // Show failed details if there are any failures
                                if (hasFailures && hasFailedDetails) {
                                    failedDetailsHtml = '<br><br><strong>Failed Order Details:</strong><ul style="text-align: left; margin-top: 10px; max-height: 300px; overflow-y: auto;">';
                                    response.failed_details.forEach(function(detail) {
                                        const errorMsg = detail.message || detail.error || 'Unknown error';
                                        failedDetailsHtml += `<li style="margin-bottom: 8px;"><strong>Order #${detail.order_id}:</strong> ${errorMsg}</li>`;
                                    });
                                    failedDetailsHtml += '</ul>';
                                }
                                
                                // Determine icon and title based on whether there are failures
                                const icon = hasFailures ? 'warning' : 'success';
                                const title = hasFailures ? 'Partial Success' : 'Success';
                                
                                Swal.fire({
                                    icon: icon,
                                    title: title,
                                    html: (response.message || 'Bulk Label purchase completed!') + downloadLink + summaryHtml + failedDetailsHtml,
                                    confirmButtonText: 'OK',
                                    width: hasFailures ? '700px' : '500px'
                                }).then(() => {
                                    table.ajax.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: response.message || 'Failed to buy bulk Label.',
                                    confirmButtonText: 'OK'
                                });
                            }
                        },
                        error: function(xhr) {
                            $('#loader').hide();
                            $('body').removeClass('loader-active');
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: xhr.responseJSON?.message || 'An error occurred while processing bulk Label.',
                                confirmButtonText: 'OK'
                            });
                        }
                    });
                }
            });
        });
        $('#shipmentTable').on('click', '.editable-cell', function() {
            let $cell = $(this);
            if ($cell.hasClass('editing-cell')) return;
            let $td = $cell;
            let originalValue = $td.text().trim() === '—' ? '' : $td.text().trim();
            let rowData = table.row($td.closest('tr')).data();
            let columnIndex = $td.index();
            let visibleColumns = table.columns().visible().toArray();
            let columnDefs = table.settings().init().columns;
            let visibleColumnIndex = 0;
            let actualColumnIndex = -1;
            for (let i = 0; i < visibleColumns.length; i++) {
                if (visibleColumns[i]) {
                    if (visibleColumnIndex === columnIndex) {
                        actualColumnIndex = i;
                        break;
                    }
                    visibleColumnIndex++;
                }
            }
            let field = columnDefs[actualColumnIndex].data;
            if (!['height', 'width', 'length', 'weight'].includes(field)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Column',
                    text: 'This column is not editable.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            $td.addClass('editing-cell').html(`<input type="number" value="${originalValue}" min="0" step="0.01">`);
            let $input = $td.find('input');
            $input.focus();
            $input.on('blur keypress', function(e) {
                if (e.type === 'blur' || e.key === 'Enter') {
                    let newValue = $(this).val().trim();
                    $td.removeClass('editing-cell').text(newValue || '—');
                    if (newValue !== '' && (isNaN(newValue) || parseFloat(newValue) < 0)) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid Input',
                            text: `Please enter a valid number for ${field}.`,
                            confirmButtonText: 'OK'
                        });
                        $td.text(originalValue || '—');
                        return;
                    }
                    if (newValue !== originalValue) {
                        $.ajax({
                            url: '{{ route("orders.update-dimensions") }}',
                            type: 'POST',
                            data: {
                                order_id: rowData.id,
                                field: field,
                                value: newValue,
                                _token: '{{ csrf_token() }}'
                            },
                            success: function(response) {
                                if (response.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Updated',
                                        text: `${field.charAt(0).toUpperCase() + field.slice(1)} updated successfully!`,
                                        confirmButtonText: 'OK'
                                    });
                                    table.ajax.reload(null, false);
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: response.message || 'Failed to update value.',
                                        confirmButtonText: 'OK'
                                    });
                                    $td.text(originalValue || '—');
                                }
                            },
                            error: function(xhr) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: 'An error occurred while updating the value.',
                                    confirmButtonText: 'OK'
                                });
                                $td.text(originalValue || '—');
                            }
                        });
                    }
                }
            });
            $input.on('keypress', function(e) {
                if (e.key === 'Escape') {
                    $td.removeClass('editing-cell').text(originalValue || '—');
                }
            });
        });
        $('#applyFilters').on('click', function() {
            $('#rightModal').modal('hide');
            table.ajax.reload();
        });
        $('#resetFilters').on('click', function() {
            $('#filterMarketplace').val('');
            $('#filterFromDate').val('');
            $('#filterToDate').val('');
            $('#rightModal').modal('hide');
            table.ajax.reload();
        });
        function fmtDate(d) {
            if (!d) return '—';
            const date = new Date(d);
            if (isNaN(date)) return '—';
            return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }
        function fmtMoney(v, symbol = '$') {
            if (v == null || v === '') return symbol + '0.00';
            const num = parseFloat(v);
            return symbol + (isNaN(num) ? '0.00' : num.toFixed(2));
        }
        $('#shipmentTable').on('click', '.recipient-link', function(e) {
            e.preventDefault();
            let orderId = $(this).data('order-id');
            const url = "{{ route('orders.details', ['orderId' => ':id']) }}".replace(':id', orderId);
            $('#orderDetailsContent').html('');
            $.ajax({
                url: url,
                type: 'GET',
                dataType: 'json',
                success: function(order) {
                    let contentHtml = '<div class="card border-0 shadow-sm p-4 fade-in" style="border-radius: 10px; background: #f9fafb;">';
                    if (order.order_number != null && order.order_number !== '') {
                        contentHtml += `
                            <div class="mb-4">
                                <h5 class="fw-bold mb-0 text-dark">Order #: <span class="text-primary">${order.order_number}</span></h5>
                            </div>
                            <hr class="my-3" style="border-color: #e9ecef;">
                        `;
                    }
                    let recipientHtml = '';
                    if (order.recipient_name != null && order.recipient_name !== '') {
                        recipientHtml += `<p class="mb-2 text-dark font-weight-medium">${order.recipient_name}</p>`;
                    }
                    const addr1 = order.ship_address1 || '';
                    const addr2 = order.ship_address2 || '';
                    const city = order.ship_city || '';
                    const state = order.ship_state || '';
                    const zip = order.ship_postal_code || '';
                    const country = order.ship_country || '';
                    const addressLine = [addr1, addr2].filter(Boolean).join(', ');
                    const cityLine = [city, state, zip].filter(Boolean).join(', ');
                    const fullAddress = [addressLine, cityLine, country].filter(Boolean).join('<br>');
                    if (fullAddress != null && fullAddress !== '') {
                        recipientHtml += `<p class="mb-2 text-dark font-weight-medium">${fullAddress}</p>`;
                    }
                    if (order.recipient_phone != null && order.recipient_phone !== '') {
                        recipientHtml += `<p class="mb-2"><strong>Phone:</strong> <span class="text-dark">${order.recipient_phone}</span></p>`;
                    }
                    if (order.recipient_email != null && order.recipient_email !== '') {
                        recipientHtml += `<p class="mb-0"><strong>Email:</strong> <span class="text-dark">${order.recipient_email}</span></p>`;
                    }
                    if (recipientHtml) {
                        contentHtml += `
                            <div class="mb-4">
                                <h6 class="fw-bold text-secondary mb-3">Recipient</h6>
                                ${recipientHtml}
                            </div>
                        `;
                    }
                    let itemsListHtml = `
                        <table class="table table-sm table-hover" style="background-color: #ffffff; border-radius: 10px; overflow: hidden; border: 1px solid #dee2e6; box-shadow: 0 3px 8px rgba(0,0,0,0.1);">
                            <thead style="background: linear-gradient(to right, #5a7bff, #8aa4ff); color: #fff; font-weight: 500;">
                                <tr>
                                    <th scope="col" class="ps-3 py-2" style="width: 50%; border-top: 0;">Item</th>
                                    <th scope="col" class="text-center py-2" style="width: 30%; border-top: 0;">SKU</th>
                                    <th scope="col" class="text-end pe-3 py-2" style="width: 20%; border-top: 0;">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    if (Array.isArray(order.items) && order.items.length > 0) {
                        order.items.forEach(item => {
                            itemsListHtml += `
                                <tr class="item-row" style="transition: all 0.2s;">
                                    <td class="ps-3 py-2 font-weight-medium">${item.product_name != null && item.product_name !== '' ? item.product_name : '—'}</td>
                                    <td class="text-center py-2 text-muted">${item.sku != null && item.sku !== '' ? item.sku : 'No SKU'}</td>
                                    <td class="text-end pe-3 py-2 font-weight-medium">${item.quantity != null && item.quantity !== '' ? item.quantity : 0}</td>
                                </tr>
                            `;
                        });
                    } else {
                        itemsListHtml += `
                            <tr>
                                <td colspan="3" class="text-center text-muted py-3">No items found for this order.</td>
                            </tr>
                        `;
                    }
                    itemsListHtml += `
                            </tbody>
                        </table>
                    `;
                    contentHtml += `
                        <div class="mb-4">
                            <h6 class="fw-bold text-secondary mb-3">Items</h6>
                            ${itemsListHtml}
                        </div>
                    `;
                    contentHtml += '</div>';
                    $('#orderDetailsContent').html(contentHtml);
                    setTimeout(() => $('#orderDetailsContent .card').addClass('show'), 10);
                    $('#orderDetailsModal').modal('show');
                },
                error: function(xhr) {
                    console.error('Failed to fetch order details', xhr);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'Failed to fetch order details.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $('#shipmentTable').on('click', '.edit-address-btn', function(e) {
            e.preventDefault();
            let order = $(this).data('order');
            if (typeof order === 'string') {
                try { order = JSON.parse(order); } catch (_) {}
            }
            $('#editOrderId').val(order.id || '');
            $('#editIsAddressVerified').val(order.is_address_verified || 0);
            $('#editRecipientName').val(order.recipient_name || '');
            $('#editShipAddress1').val(order.ship_address1 || '');
            $('#editShipAddress2').val(order.ship_address2 || '');
            $('#editShipCity').val(order.ship_city || '');
            $('#editShipState').val(order.ship_state || '');
            $('#editShipPostalCode').val(order.ship_postal_code || '');
            $('#editShipCountry').val(order.ship_country || '');
            if (order.is_address_verified === 1) {
                $('#verifyButtonContainer').hide();
            } else {
                $('#verifyButtonContainer').show();
            }
            $('#editAddressModal').modal('show');
        });
        $('#editAddressForm').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serialize();
            $('#loader').show();
            $('body').addClass('loader-active');
            $.ajax({
                url: "{{ route('orders.update-address') }}",
                type: 'POST',
                data: formData + '&_token=' + $('meta[name="csrf-token"]').attr('content'),
                success: function(response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    if (response.success) {
                        $('#editAddressModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: 'Shipping address updated successfully!',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            table.ajax.reload(null, false);
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to update address.',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'An error occurred while updating the address.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $('#verifyAddressBtn').on('click', function(e) {
            e.preventDefault();
            const recipientName = $('#editRecipientName').val().trim();
            const shipAddress1 = $('#editShipAddress1').val().trim();
            const shipCity = $('#editShipCity').val().trim();
            const shipState = $('#editShipState').val().trim();
            const shipPostalCode = $('#editShipPostalCode').val().trim();
            const shipCountry = $('#editShipCountry').val().trim();
            if (!recipientName || !shipAddress1 || !shipCity || !shipState || !shipPostalCode || !shipCountry) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'All address fields are required to verify the address.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            $('#loader').show();
            $('body').addClass('loader-active');
            const orderId = $('#editOrderId').val();
            $.ajax({
                url: '{{ route("orders.verify-address") }}',
                type: 'POST',
                data: {
                    order_id: orderId,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: 'Address verified successfully!',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            $('#verifyButtonContainer').hide();
                            table.ajax.reload(null, false);
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to verify address.',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'An error occurred while verifying the address.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $('#shipmentTable').on('click', '.edit-items-btn', function(e) {
            e.preventDefault();
            let orderId = $(this).data('order-id');
            $('#editItemsOrderId').val(orderId);
            $('#itemsList').html('<div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading items...</div>');
            $('#editItemsModal').modal('show');
            $.ajax({
                url: "{{ route('orders.details', ['orderId' => ':id']) }}".replace(':id', orderId),
                type: 'GET',
                dataType: 'json',
                success: function(order) {
                    let itemsHtml = '';
                    if (Array.isArray(order.items) && order.items.length > 0) {
                        order.items.forEach((item, index) => {
                            itemsHtml += `
                                <div class="mb-3 border p-3 rounded">
                                    <h6 class="mb-3">Item ${index + 1}: ${item.product_name || '—'}</h6>
                                    <input type="hidden" name="items[${index}][id]" value="${item.id || ''}">
                                    <div class="mb-2">
                                        <label class="form-label">SKU</label>
                                        <input type="text" class="form-control" name="items[${index}][sku]" value="${item.sku || ''}" required maxlength="255">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" class="form-control" name="items[${index}][quantity]" value="${item.quantity || 1}" required min="1" step="1">
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        itemsHtml = '<div class="text-center text-muted py-3">No items found for this order.</div>';
                    }
                    $('#itemsList').html(itemsHtml);
                },
                error: function(xhr) {
                    $('#itemsList').html('<div class="text-center text-danger py-3">Failed to load items.</div>');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'Failed to load order items.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $('#editItemsForm').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serialize();
            var orderId = $('#editItemsOrderId').val();
            $('#loader').show();
            $('body').addClass('loader-active');
            $.ajax({
                url: "{{ route('orders.update-items', ['orderId' => ':orderId']) }}".replace(':orderId', orderId),
                type: 'POST',
                data: formData + '&_token=' + '{{ csrf_token() }}',
                success: function(response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    if (response.success) {
                        $('#editItemsModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: 'Order items updated successfully!',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            table.ajax.reload(null, false);
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to update items.',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'An error occurred while updating the items.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $(document).on('click', '.edit-carrier-btn', function() {
            let row = $(this).data('order');
            $('#carrierList').html(`
                <div class="text-center py-3 text-muted">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    Loading carriers...
                </div>
            `);
            $('#changeCarrierModal').modal('show');
            $.ajax({
                url: '{{ route("orders.get-carriers") }}',
                type: 'POST',
                data: {
                    order_id: row.id,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    console.log('Carriers API Response:', response);
                    if (!response.rates || response.rates.length === 0) {
                        $('#carrierList').html('<div class="text-center text-muted py-3">No carriers available</div>');
                        return;
                    }
                    
                    // Check for USPS rates
                    const uspsRates = response.rates.filter(rate => 
                        rate.carrier && (
                            rate.carrier.toLowerCase().includes('usps') || 
                            rate.carrier.toLowerCase().includes('stamps') ||
                            rate.carrier.toLowerCase().includes('endicia')
                        )
                    );
                    console.log('USPS rates found:', uspsRates.length, uspsRates);
                    
                    let rates = response.rates.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
                    let html = '<div class="list-group">';
                    rates.forEach((rate, index) => {
                        let checked = rate.is_cheapest ? 'checked' : '';
                        html += `
                            <label class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <div>
                                    <input class="form-check-input me-2 select-carrier-radio"
                                           type="radio"
                                           name="selectedCarrier"
                                           id="carrier_${index}"
                                           data-order-id="${row.id}"
                                           data-carrier="${rate.carrier}"
                                           data-service="${rate.service}"
                                           data-rate-id="${rate.id}"
                                           data-price="${rate.price}"
                                           data-source="${rate.source}"
                                           ${checked}>
                                    <strong>${rate.carrier}</strong> - ${rate.service} <small class="text-muted">(${rate.source})</small>
                                </div>
                                <span class="badge bg-primary rounded-pill">$${parseFloat(rate.price).toFixed(2)}</span>
                            </label>
                        `;
                    });
                    html += '</div>';
                    $('#carrierList').html(html);
                },
                error: function(xhr) {
                    console.error(xhr.responseText);
                    $('#carrierList').html('<div class="text-center text-danger py-3">Failed to load carriers</div>');
                }
            });
        });
        $(document).on('change', '.select-carrier-radio', function() {
            let $radio = $(this);
            let orderId = $radio.data('order-id');
            let carrier = $radio.data('carrier');
            let service = $radio.data('service');
            let rate_id = $radio.data('rate-id');
            let price = $radio.data('price');
            $.ajax({
                url: '{{ route("orders.updateCarrier") }}',
                type: 'POST',
                data: {
                    order_id: orderId,
                    carrier: carrier,
                    service: service,
                    price: price,
                    rate_id: rate_id,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Carrier Updated',
                            text: `${carrier} - ${service} selected successfully at $${parseFloat(price).toFixed(2)}`,
                            timer: 2000,
                            showConfirmButton: false
                        });
                        $('#changeCarrierModal').modal('hide');
                        table.ajax.reload(null, false);
                    } else {
                        Swal.fire('Error', response.message || 'Failed to update carrier', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to update carrier', 'error');
                }
            });
        });
        $('#shipmentTable').on('click', '.confirm-shipment-btn', function() {
            const orderId = $(this).data('order-id');
            Swal.fire({
                icon: 'info',
                title: 'Confirm Shipment',
                text: `Are you sure you want to confirm shipment for order #${orderId}?`,
                showCancelButton: true,
                confirmButtonText: 'Yes',
                cancelButtonText: 'No'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: `/admin/orders/${orderId}/confirm-shipment`,
                        type: 'POST',
                        data: { _token: '{{ csrf_token() }}' },
                        success: function(response) {
                            Swal.fire('Success', 'Shipment confirmed successfully!', 'success');
                            table.ajax.reload();
                        },
                        error: function(xhr) {
                            Swal.fire('Error', 'Failed to confirm shipment.', 'error');
                        }
                    });
                }
            });
        });
    });
</script>
@endsection
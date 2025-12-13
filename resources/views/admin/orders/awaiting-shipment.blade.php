@extends('admin.layouts.admin_master')
@section('title', 'Awaiting Shipment')
@section('content')
<style>
    /* Optional custom CSS for sidebar */
    .sidebar {
        background-color: #f8f9fa;
    }
    .sidebar .form-select,
    .sidebar .form-control {
        border-color: #dee2e6;
        transition: border-color 0.2s ease;
    }
    .sidebar .form-select:focus,
    .sidebar .form-control:focus {
        border-color: #007bff;
        box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
    }
    .sidebar .input-group-text {
        display: none;
    }
    .sidebar #rateDisplay {
        font-weight: bold;
        color: #28a745;
    }
    .toolbar {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 10px;
    }
    .toolbar .btn {
        font-size: 13px;
        padding: 6px 10px;
    }
    .filter-row {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 10px;
        font-size: 13px;
    }
    .shipment-table {
        width: 100% !important;
        border-collapse: collapse;
        background: white;
        font-size: 13px;
    }
    .shipment-table th, .shipment-table td {
        padding: 8px 10px;
        border-bottom: 1px solid #ddd;
    }
    .shipment-table thead {
        background: #f3f4f6;
        font-weight: bold;
    }
    .sidebar {
        background: #f8f9fa;
        padding: 10px;
        width: 100%;
        min-height: 200px;
    }
    .sidebar label {
        display: block;
        margin-bottom: 5px;
    }
    .sidebar .form-group {
        margin-bottom: 10px;
    }
    .sidebar .btn {
        width: 100%;
    }
    #tableCol {
        overflow-x: auto;
    }
    /* Modal styles */
    .modal.left .modal-dialog,
    .modal.right .modal-dialog {
        position: fixed;
        margin: auto;
        width: 400px;
        height: 100%;
        transform: translate3d(0%, 0, 0);
    }
    .modal.left .modal-content,
    .modal.right .modal-content {
        height: 100%;
        overflow-y: auto;
    }
    .modal.left .modal-body,
    .modal.right .modal-body {
        padding: 15px 15px 80px;
    }
    .modal.right.fade .modal-dialog {
        right: -400px;
        transition: opacity 0.3s linear, right 0.3s ease-out;
    }
    .modal.right.fade.show .modal-dialog {
        right: 0;
    }
    .modal-content {
        border-radius: 0;
        border: none;
    }
    .modal-header {
        border-bottom: 1px solid #eee;
        background-color: #f8f9fa;
    }
    /* Loader Styles */
    .loader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.5); /* Semi-transparent background */
        display: none; /* Hidden by default */
        align-items: center;
        justify-content: center;
        z-index: 9999; /* High z-index to overlay everything */
        pointer-events: auto; /* Allow loader to capture clicks */
    }
    .loader-content {
        background: #fff;
        padding: 20px 30px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 15px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    }
    .spinner {
        border: 4px solid rgba(0, 0, 0, 0.1);
        border-top: 4px solid #007bff; /* Blue spinner like ShipStation */
        border-radius: 50%;
        width: 30px;
        height: 30px;
        animation: spin 1s linear infinite;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    /* Disable interactions on content when loader is active */
    .loader-active body {
        pointer-events: none; /* Disable all pointer events on body */
    }
</style>
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md">
    <div class="modal-content rounded-3 shadow">
      <div class="modal-body p-4">
        <h5 class="fw-bold mb-3">Order #: <span id="detailOrderNumber"></span></h5>
        <hr>
        <p class="mb-1">Shipped via: <strong id="detailCarrier"></strong> 
          <!-- <button class="btn btn-sm btn-outline-secondary btn-copy" data-target="#detailTracking">Copy</button> -->
        </p>
        <p class="mb-1">Tracking #: <span id="detailTracking"></span></p>
        <p class="mb-1">Shipment Date: <span id="detailOrderDate"></span></p>
        <p class="mb-3">Estimated Delivery: <span id="detailDeliveryDate"></span></p>

        <h6 class="fw-bold">Recipient:</h6>
        <p class="mb-1"><span id="detailRecipient"></span></p>
        <p class="mb-1" id="detailRecipientAddress"></p>
        <p class="mb-3">Phone: <span id="detailRecipientPhone"></span></p>


        <h6 class="fw-bold">Items:</h6>
        <ul class="mb-3" id="detailItemsList"></ul>

    
        <p class="mb-1">Shipping Cost: <span id="detailShippingCost"></span></p>
        <p class="mb-3">Shipping Method: <span id="detailShippingMethod"></span></p>

   
        <div class="d-flex justify-content-center mt-4">
          <button class="btn btn-dark" data-bs-dismiss="modal">OK</button>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="p-3">
    <!-- Loader -->
    <div id="loader" class="loader">
        <div class="loader-content">
            <div class="spinner"></div>
            <span id="loaderText">Generating Label...</span>
        </div>
    </div>

    <!-- Page Title -->
    <div class="page-title d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Awaiting Shipment</h5>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rightModal">
            <i class="bi bi-filter"></i> Filters
        </button>
    </div>

    <!-- Right Sidebar Modal -->
    <div class="modal right fade" id="rightModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Filters</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Marketplace -->
                    <div class="mb-3">
                        <label class="form-label">Marketplace</label>
                       <select class="form-select" id="filterMarketplace">
                        <option value="">-- Select Marketplace --</option>
                       @foreach($marketplaces as $channel)
                            <option value="{{ strtolower($channel) }}">{{ ucfirst($channel) }}</option>
                        @endforeach
                    </select>
                    </div>

                    <!-- Date Range -->
                    <div class="mb-3">
                        <label class="form-label">From Date</label>
                        <input type="date" class="form-control" id="filterFromDate">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">To Date</label>
                        <input type="date" class="form-control" id="filterToDate">
                    </div>

                    <!-- Buttons -->
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" id="resetFilters">Reset</button>
                        <button type="button" class="btn btn-primary" id="applyFilters">Apply Filters</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-9 col-12" id="tableCol">
            <table id="shipmentTable" class="shipment-table table table-bordered nowrap">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Marketplace</th>
                        <th>Order #</th>
                        <th>Age</th>
                        <th>Order Date</th>
                        <!-- <th>Notes</th> -->
                        <th>Gift</th>
                        <th>Item SKU</th>
                        <th>Item Name</th>
                        <!-- <th>Batch</th> -->
                        <th>Recipient</th>
                        <th>Quantity</th>
                        <th>Order Total</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="col-md-3 col-12" id="sidebarCol">
            <div class="sidebar border">
                <h6>Sales Channels</h6>
                <hr>
                <div class="sidebar border p-3 text-center">
                    <h6>No row selected</h6>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
@section('script')
<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- jQuery, DataTables, and Bootstrap JS (assumed already included) -->
<!-- <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> -->
<!-- <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script> -->

<script>
$(document).ready(function() {
    const shippingServices = @json($services);
    let table = $('#shipmentTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ route("orders.awaiting.data") }}',
            data: function(d) {
                d.channels = $('.sales-channel-filter:checked').map(function() {
                    return this.value;
                }).get();
                d.marketplace = $('#filterMarketplace').val();
                d.from_date = $('#filterFromDate').val();
                d.to_date = $('#filterToDate').val();
            }
        },
        columns: [
            {
                data: 'id',
                render: function(data) {
                    return `<input type="checkbox" class="order-checkbox" value="${data}">`;
                },
                orderable: false,
                searchable: false
            },
            {
                data: 'marketplace',
                render: function(data, type, row) {
                    if (data === 'amazon') {
                        return '<span class="badge bg-success">Amazon</span>';
                    } else if (data === 'reverb') {
                        return '<span class="badge bg-primary">Reverb</span>';
                    } else {
                        return '<span class="badge bg-secondary">' + data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
                    }
                }
            },
            { data: 'order_number' },
            { 
                data: 'order_date',  
                render: function(data, type, row) {
                    if (!data) return '';
                    const orderDate = new Date(data);
                    const now = new Date();
                    const diffMs = now - orderDate; 
                    const diffSec = Math.floor(diffMs / 1000);
                    const diffMin = Math.floor(diffSec / 60);
                    const diffHour = Math.floor(diffMin / 60);
                    const diffDay = Math.floor(diffHour / 24);
                    if (diffSec < 60) return `${diffSec} sec ago`;
                    if (diffMin < 60) return `${diffMin} min ago`;
                    if (diffHour < 24) return `${diffHour} hr ago`;
                    return `${diffDay} day${diffDay > 1 ? 's' : ''} ago`;
                }
            },
            {
                data: 'order_date',
                render: function(data) {
                    if (!data) return '';
                    let d = new Date(data);
                    return d.toLocaleDateString('en-GB');
                }
            },
            {
                data: 'is_gift',
                render: function(data) {
                    return data ? 'Yes' : 'No';
                }
            },
            { data: 'item_sku' },
            {
                data: 'item_name',
                render: function(data) {
                    if (!data) return '';
                    var maxLength = 30;
                    var displayText = data.length > maxLength ? data.substr(0, maxLength) + '...' : data;
                    return '<span title="' + data + '">' + displayText + '</span>';
                }
            },
            {
                data: 'recipient_name',
                render: function (data, type, row) {
                    if (!data || data.trim() === '') {
                        return '-';
                    }
                    return `<a href="#"
                               class="recipient-link"
                               data-order='${JSON.stringify(row).replace(/'/g, "&apos;")}'
                            >${data}</a>`;
                }
            },
            { data: 'quantity' },
            { data: 'order_total', render: $.fn.dataTable.render.number(',', '.', 2, '$') }
        ],
        order: [[3, 'desc']],
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100]
    });

    // Filter handling
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

    $('#selectAll').on('change', function() {
        $('.order-checkbox', table.rows().nodes()).prop('checked', this.checked);
        updateSidebar();
    });

    $('#showSidebar').on('change', function() {
        if (this.checked) {
            $('#sidebarCol').show();
            $('#tableCol').removeClass('col-md-12').addClass('col-md-9');
        } else {
            $('#sidebarCol').hide();
            $('#tableCol').removeClass('md-9').addClass('col-md-12');
        }
        setTimeout(function() {
            table.columns.adjust().draw();
        }, 200);
    });

    $('#shipmentTable').on('change', '.order-checkbox', function() {
        updateSidebar();
    });

    function updateSidebar() {
        let selectedOrders = $('.order-checkbox:checked');

        if (selectedOrders.length === 0) {
            $('#sidebarCol .sidebar').html(`
                <div class="sidebar border p-3 text-center">
                    <h6>No row selected</h6>
                </div>
            `);
            return;
        }

        if (selectedOrders.length === 1) {
            let rowData = table.row($(selectedOrders[0]).closest('tr')).data();
            let sidebarContent = `
                <div class="sidebar border p-3">
                    <div class="mb-3">
                        <label class="form-label">Order #:</label>
                        <span class="form-control-plaintext">${rowData.order_number || ''}</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Weight:</label>
                        <div class="input-group">
                            <input type="number" id="pkgWeightLb" value="${rowData.weight || '20'}" min="0" class="form-control w-25"> lb
                            <input type="number" id="pkgWeightOz" value="${rowData.weight_oz || '0'}" min="0" max="15" class="form-control w-25"> oz
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="shippingService" class="form-label">Service:</label>
                        <select id="shippingService" data-order-id="${rowData.id}" class="form-select">
                            ${Object.keys(shippingServices).map(carrier => `
                                <optgroup label="${carrier}">
                                    ${shippingServices[carrier].map(service => `
                                        <option value="${service.service_code}" 
                                            ${rowData.shipping_service === service.service_code ? 'selected' : ''}>
                                            ${service.display_name}
                                        </option>
                                    `).join('')}
                                </optgroup>
                            `).join('')}
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="packageType" class="form-label">Package:</label>
                        <select id="packageType" class="form-select">
                            <option value="">-- Select Package --</option>
                            <option value="YOUR_PACKAGING" ${rowData.package_type === 'YOUR_PACKAGING' ? 'selected' : ''}>Your Packaging</option>
                            <option value="FEDEX_BOX" ${rowData.package_type === 'FEDEX_BOX' ? 'selected' : ''}>FedEx Box</option>
                            <option value="FEDEX_ENVELOPE" ${rowData.package_type === 'FEDEX_ENVELOPE' ? 'selected' : ''}>FedEx Envelope</option>
                            <option value="FEDEX_PAK" ${rowData.package_type === 'FEDEX_PAK' ? 'selected' : ''}>FedEx Pak</option>
                            <option value="FEDEX_TUBE" ${rowData.package_type === 'FEDEX_TUBE' ? 'selected' : ''}>FedEx Tube</option>
                            <option value="FEDEX_10KG_BOX" ${rowData.package_type === 'FEDEX_10KG_BOX' ? 'selected' : ''}>FedEx 10kg Box</option>
                            <option value="FEDEX_25KG_BOX" ${rowData.package_type === 'FEDEX_25KG_BOX' ? 'selected' : ''}>FedEx 25kg Box</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Size:</label>
                        <div class="input-group">
                            <input type="number" id="pkgLength" value="${rowData.length || '8'}" min="0" class="form-control w-25"> L x
                            <input type="number" id="pkgWidth" value="${rowData.width || '6'}" min="0" class="form-control w-25"> W x
                            <input type="number" id="pkgHeight" value="${rowData.height || '2'}" min="0" class="form-control w-25"> H (in)
                        </div>
                    </div>
                    <div class="mb-3">
                        <strong>Estimated Rate:</strong> <span id="rateDisplay" class="ms-2">-</span>
                    </div>
                    <button class="btn btn-success btn-sm create-label-btn">Create + Print Label</button>
                </div>
            `;
            $('#sidebarCol .sidebar').html(sidebarContent);
            // Initialize Select2 for packageType
            $('#packageType').select2({
                placeholder: "-- Select Package --",
                allowClear: true
            });
            // Fetch packages when service changes
            $('#shippingService').on('change', function() {
                let orderId = $(this).data('order-id');
                let rowData = table.rows().data().toArray().find(o => o.id == orderId);
                if (rowData) {
                    fetchpackage(rowData);
                }
            });
        } else {
            let sidebarContent = `
                <div class="sidebar border p-3">
                    <h6>${selectedOrders.length} rows selected</h6>
                    <div class="mb-3">
                        <label class="form-label">Weight:</label>
                        <div class="input-group">
                            <input type="number" id="pkgWeightLb" value="20" min="0" class="form-control w-25"> lb
                            <input type="number" id="pkgWeightOz" value="0" min="0" max="15" class="form-control w-25"> oz
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="shippingService" class="form-label">Service:</label>
                        <select id="shippingService" class="form-select">
                            ${Object.keys(shippingServices).map(carrier => `
                                <optgroup label="${carrier}">
                                    ${shippingServices[carrier].map(service => `
                                        <option value="${service.service_code}">
                                            ${service.display_name}
                                        </option>
                                    `).join('')}
                                </optgroup>
                            `).join('')}
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="packageType" class="form-label">Package:</label>
                        <select id="packageType" class="form-select">
                            <option value="">-- Select Package --</option>
                            <option value="YOUR_PACKAGING">Your Packaging</option>
                            <option value="FEDEX_BOX">FedEx Box</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Size:</label>
                        <div class="input-group">
                            <input type="number" id="pkgLength" value="8" min="0" class="form-control w-25"> L x
                            <input type="number" id="pkgWidth" value="6" min="0" class="form-control w-25"> W x
                            <input type="number" id="pkgHeight" value="2" min="0" class="form-control w-25"> H (in)
                        </div>
                    </div>
                    <div class="mb-3">
                        <strong>Estimated Rate:</strong> <span id="rateDisplay" class="ms-2">-</span>
                    </div>
                    <button class="btn btn-success btn-sm mt-2 create-label-btn" id="bulkCreateLabels">Create + Print Labels</button>
                </div>
            `;
            $('#sidebarCol .sidebar').html(sidebarContent);
            // Initialize Select2 for packageType
            $('#packageType').select2({
                placeholder: "-- Select Package --",
                allowClear: true
            });
            // Fetch packages when service changes
            $('#shippingService').on('change', function() {
                fetchpackage();
            });
        }
    }

    $('#shippingService').select2({
        placeholder: "Select Shipping Service",
        allowClear: true
    });

    // Handle changes for both service and package
    // $('#sidebarCol').on('change', '#shippingService, #packageType', function() {
    //     let orderId = $('#shippingService').data('order-id');
    //     let rowData = table.rows().data().toArray().find(o => o.id == orderId);
    //     if (rowData && $('#shippingService').val() && $('#packageType').val()) {
    //         fetchShippingRate(rowData);
    //     } else {
    //         $('#rateDisplay').text('-');
    //     }
    // });
    $('#sidebarCol').on('change', '#shippingService, #packageType', function() {
    // Check if both shipping service and package type are selected
    if ($('#shippingService').val() && $('#packageType').val()) {
        // Get the first selected order's row data (if any) for default values
        let selectedOrder = $('.order-checkbox:checked').first();
        let rowData = selectedOrder.length ? table.row(selectedOrder.closest('tr')).data() : null;
        fetchShippingRate(rowData);
    } else {
        $('#rateDisplay').text('-');
    }
});

    // function fetchShippingRate(rowData) {
    //     let selectedOrderValues = $('.order-checkbox:checked').map(function() {
    //         let rowData = table.row($(this).closest('tr')).data();
    //         return {
    //             order_number: rowData.order_number,
    //             id: rowData.id
    //         };
    //     }).get();
    //     let payload = {
    //         order_number: rowData.order_number,
    //         weight_lb: $('#pkgWeightLb').val() || rowData.weight || 20,
    //         weight_oz: $('#pkgWeightOz').val() || rowData.weight_oz || 0,
    //         service_code: $('#shippingService').val() || 'FEDEX_GROUND',
    //         length: $('#pkgLength').val() || rowData.length || 8,
    //         width: $('#pkgWidth').val() || rowData.width || 6,
    //         height: $('#pkgHeight').val() || rowData.height || 2,
    //         package_type: $('#packageType').val() || 'YOUR_PACKAGING',
    //         from_postal_code: rowData.from_postal_code || '90210',
    //         from_country_code: rowData.from_country_code || 'US',
    //         to_postal_code: rowData.to_postal_code || '10001',
    //         to_country_code: rowData.to_country_code || 'US',
    //         residential: false,
    //         selectedOrderValues: selectedOrderValues,
    //         _token: '{{ csrf_token() }}'
    //     };

    //     $.ajax({
    //         url: '{{ route("orders.get.rate") }}',
    //         type: 'POST',
    //         data: payload,
    //         success: function(response) {
    //             if (response.success) {
    //                 $('#rateDisplay').text(`$${response.rate}`);
    //             } else {
    //                 $('#rateDisplay').text('Error: ' + (response.message || 'Failed to fetch rate'));
    //             }
    //         },
    //         error: function(xhr) {
    //             $('#rateDisplay').text('API Error');
    //             console.error(xhr.responseText);
    //         }
    //     });
    // }
function fetchShippingRate(rowData) {
    let selectedOrderValues = $('.order-checkbox:checked').map(function() {
        let row = table.row($(this).closest('tr')).data();
        return {
            order_number: row.order_number,
            id: row.id,
            to_postal_code: row.to_postal_code || '10001',
            to_country_code: row.to_country_code || 'US'
        };
    }).get();

    // If no orders are selected, return early
    if (selectedOrderValues.length === 0) {
        $('#rateDisplay').text('-');
        return;
    }

    // Use the first selected order's data for default values if rowData is not provided
    let firstOrder = selectedOrderValues[0];

    let payload = {
        order_ids: selectedOrderValues.map(order => order.id),
        order_number: firstOrder.order_number, // For single order context, if needed by backend
        weight_lb: $('#pkgWeightLb').val() || rowData?.weight || 20,
        weight_oz: $('#pkgWeightOz').val() || rowData?.weight_oz || 0,
        service_code: $('#shippingService').val() || 'FEDEX_GROUND',
        length: $('#pkgLength').val() || rowData?.length || 8,
        width: $('#pkgWidth').val() || rowData?.width || 6,
        height: $('#pkgHeight').val() || rowData?.height || 2,
        package_type: $('#packageType').val() || 'YOUR_PACKAGING',
        from_postal_code: rowData?.from_postal_code || '90210',
        from_country_code: rowData?.from_country_code || 'US',
        to_postal_code: firstOrder.to_postal_code || '10001',
        to_country_code: firstOrder.to_country_code || 'US',
        residential: false,
        selectedOrderValues: selectedOrderValues, // Include all selected orders
        _token: '{{ csrf_token() }}'
    };

    $.ajax({
        url: '{{ route("orders.get.rate") }}',
        type: 'POST',
        data: payload,
        success: function(response) {
            if (response.success) {
                $('#rateDisplay').text(`$${response.rate}`);
            } else {
                $('#rateDisplay').text('Error: ' + (response.message || 'Failed to fetch rate'));
            }
        },
        error: function(xhr) {
            $('#rateDisplay').text('API Error');
            console.error(xhr.responseText);
        }
    });
}
    function fetchpackage(rowData) {
        let serviceCode = $('#shippingService').val();

        $.ajax({
            url: `/admin/carrier-packages/${serviceCode}`,
            method: 'GET',
            success: function(response) {
                $('#packageType').empty().append('<option value="">-- Select Package --</option>');

                if (response.packages && response.packages.length > 0) {
                    response.packages.forEach(pkg => {
                        $('#packageType').append(
                            `<option value="${pkg.package_code}">${pkg.display_name}</option>`
                        );
                    });
                }
                // Reinitialize Select2
                $('#packageType').select2({
                    placeholder: "-- Select Package --",
                    allowClear: true
                });
                // Trigger rate fetch if both are selected
                if ($('#shippingService').val() && $('#packageType').val()) {
                    fetchShippingRate(rowData || table.row($(selectedOrders[0]).closest('tr')).data());
                }
            },
            error: function() {
                $('#packageType').empty().append('<option value="">Error loading packages</option>');
                $('#packageType').select2({
                    placeholder: "Error loading packages",
                    allowClear: true
                });
                $('#rateDisplay').text('-');
            }
        });
    }

    $('#sidebarCol').on('click', '.create-label-btn', function(e) {
        e.preventDefault();
        let $button = $(this);
        let selectedOrders = $('.order-checkbox:checked').map(function() {
            return this.value;
        }).get();

        if (selectedOrders.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Orders Selected',
                text: 'Please select at least one order to create and print labels.',
                confirmButtonText: 'OK'
            });
            return;
        }

        // Update loader text based on number of orders
        $('#loaderText').text(selectedOrders.length > 1 ? 'Generating Labels...' : 'Generating Label...');
        // Show loader and disable interactions
        $('#loader').show();
        $('body').addClass('loader-active');
        // Disable button
        $button.prop('disabled', true);

        $.ajax({
            url: '{{ route("orders.create.print.labels") }}',
            type: 'POST',
            data: {
                order_ids: selectedOrders,
                weight_lb: $('#pkgWeightLb').val(),
                weight_oz: $('#pkgWeightOz').val(),
                service_code: $('#shippingService').val(),
                length: $('#pkgLength').val(),
                width: $('#pkgWidth').val(),
                height: $('#pkgHeight').val(),
                package_type: $('#packageType').val(),
                _token: '{{ csrf_token() }}'
            },
            dataType: 'json',
            success: function(response) {
                // Hide loader and re-enable interactions
                $('#loader').hide();
                $('body').removeClass('loader-active');
                $button.prop('disabled', false);

                if (response.status === 'success') {
                    if (response.url) {
                        let printWindow = window.open(response.url, '_blank');
                        if (printWindow) {
                            printWindow.onload = function() {
                                printWindow.print();
                            };
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Popup Blocked',
                                text: 'Please allow popups for this site to print the label.',
                                confirmButtonText: 'OK'
                            });
                        }
                    }
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: response.message || 'Label created successfully!',
                        confirmButtonText: 'OK'
                    });
                    if (typeof table !== 'undefined') {
                        table.ajax.reload();
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to create label.',
                        confirmButtonText: 'OK'
                    });
                }
            },
            error: function(xhr) {
                // Hide loader and re-enable interactions
                $('#loader').hide();
                $('body').removeClass('loader-active');
                $button.prop('disabled', false);

                let msg = 'An unexpected error occurred while creating the label.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                } else if (xhr.responseText) {
                    try {
                        let parsed = JSON.parse(xhr.responseText);
                        if (parsed.message) msg = parsed.message;
                    } catch (e) {
                        // not JSON, keep default msg
                    }
                }
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: msg,
                    confirmButtonText: 'OK'
                });
                console.error('AJAX Error:', xhr);
            }
        });
    });

    function fmtDate(d) {
        if (!d) return '—';
        const date = new Date(d);
        if (isNaN(date)) return '—';
        return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    // Helper: currency
    function fmtMoney(v, symbol = '$') {
        if (v == null || v === '') return symbol + '0.00';
        const num = parseFloat(v);
        return symbol + (isNaN(num) ? '0.00' : num.toFixed(2));
    }

    $('#shipmentTable').on('click', '.recipient-link', function (e) {
        e.preventDefault();

        let order = $(this).data('order');
        console.log(order,'order');
        if (typeof order === 'string') { try { order = JSON.parse(order); } catch (_) {} }

        // Parse estimated delivery from raw_data (if available)
        let estDelivery = '—';
        try {
            if (order.raw_data) {
                const rd = typeof order.raw_data === 'string' ? JSON.parse(order.raw_data) : order.raw_data;
                const earliest = rd?.shipping_info?.delivery_date?.earliest;
                const latest   = rd?.shipping_info?.delivery_date?.latest;
                // show earliest date (or range if both exist)
                if (earliest && latest && earliest !== latest) {
                    estDelivery = `${fmtDate(earliest)} – ${fmtDate(latest)}`;
                } else if (earliest) {
                    estDelivery = fmtDate(earliest);
                } else if (latest) {
                    estDelivery = fmtDate(latest);
                }
            }
        } catch (_) {}

        // Header
        $('#detailOrderNumber').text(order.order_number || '—');

        // Shipping info
        $('#detailCarrier').text(order.shipping_carrier || '—');
        $('#detailTracking').text(order.tracking_number || '—');
        $('#detailOrderDate').text(fmtDate(order.ship_date || order.order_date));
        $('#detailDeliveryDate').text(estDelivery);

        // Recipient block
        const addr1 = order.ship_address1 || '';
        const addr2 = order.ship_address2 || '';
        const city  = order.ship_city || '';
        const state = order.ship_state || '';
        const zip   = order.ship_postal_code || '';
        const country = order.ship_country || '';
        const addressLine = [addr1, addr2].filter(Boolean).join(', ');
        const cityLine = [city, state, zip].filter(Boolean).join(', ');

        $('#detailRecipient').text(order.recipient_name || '—');
        $('#detailRecipientAddress').html(
            [addressLine, cityLine, country].filter(Boolean).join('<br>')
        );
        $('#detailRecipientPhone').text(order.recipient_phone || '—');

        $('#detailItemsList').html(`
            <li>${order.quantity || 0}x ${order.item_name || '—'} ${order.item_sku ? `(SKU: ${order.item_sku})` : ''}</li>
        `);
        $('#detailShippingCost').text(fmtMoney(order.shipping_cost));
        $('#detailShippingMethod').text(order.shipping_service || '—');

        $('#orderDetailsModal').modal('show');
    });
});
</script>
@endsection
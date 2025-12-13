@extends('admin.layouts.admin_master')
@section('title', 'Printed/Shipped Orders')
@section('content')
<style>
.bootstrap-tagsinput {
  margin: 0;
  width: 100%;
  padding: 0.5rem 0.75rem 0;
  font-size: 1rem;
  line-height: 1.25;
  transition: border-color 0.15s ease-in-out;
  border: 1px solid #ced4da;
  border-radius: 0.375rem;
}
.bootstrap-tagsinput.has-focus {
  background-color: #fff;
  border-color: #5cb3fd;
  box-shadow: 0 0 0 0.2rem rgba(92, 179, 253, 0.25);
}
.bootstrap-tagsinput .label-info {
  display: inline-block;
  background-color: #636c72;
  padding: 0 .4em .15em;
  border-radius: .25rem;
  margin-bottom: 0.4em;
  color: #fff;
}
.bootstrap-tagsinput input {
  margin-bottom: 0.5em;
  border: none;
  outline: none;
  background: transparent;
  width: auto;
  min-width: 100px;
}
.bootstrap-tagsinput .tag [data-role="remove"]:after {
  content: '×';
  font-size: 1.2em;
  padding: 0 2px;
}
.bootstrap-tagsinput .tag [data-role="remove"] {
  margin-left: 8px;
  cursor: pointer;
  color: inherit;
  opacity: 0.5;
}
.bootstrap-tagsinput .tag [data-role="remove"]:hover {
  opacity: 1;
}
</style>
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header bg-primary text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold">Order Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <h5 class="fw-bold mb-3">Order #: <span id="detailOrderNumber" class="text-primary"></span></h5>
                <hr class="my-3">
                <div class="mb-3">
                    <p class="mb-1"><strong>Shipped via:</strong> <span id="detailCarrier" class="text-success"></span></p>
                    <p class="mb-1"><strong>Tracking #:</strong> <span id="detailTracking" class="text-info"></span></p>
                    <p class="mb-1"><strong>Shipment Date:</strong> <span id="detailOrderDate"></span></p>
                    <p class="mb-1"><strong>Estimated Delivery:</strong> <span id="detailDeliveryDate" class="text-warning"></span></p>
                </div>
                <div class="mb-3">
                    <h6 class="fw-bold text-secondary">Recipient:</h6>
                    <p class="mb-1"><span id="detailRecipient"></span></p>
                    <p class="mb-1" id="detailRecipientAddress"></p>
                    <p class="mb-1"><strong>Phone:</strong> <span id="detailRecipientPhone"></span></p>
                    <p class="mb-0"><strong>Email:</strong> <span id="detailRecipientEmail"></span></p>
                </div>
                <div class="mb-3">
                    <h6 class="fw-bold text-secondary">Items:</h6>
                    <ul class="list-group list-group-flush" id="detailItemsList"></ul>
                </div>
                <div class="mb-3">
                    <p class="mb-1"><strong>Shipping Cost:</strong> <span id="detailShippingCost" class="text-success"></span></p>
                    <p class="mb-0"><strong>Shipping Method:</strong> <span id="detailShippingMethod"></span></p>
                </div>
                <div class="d-flex justify-content-center mt-4">
                    <button class="btn btn-primary btn-lg shadow-sm" data-bs-dismiss="modal">
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="p-3">
    <div id="loader" class="loader">
        <div class="loader-content">
            <div class="spinner"></div>
            <span id="loaderText">Processing...</span>
        </div>
    </div>
    <div class="page-title d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Printed/shipped</h5>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rightModal">
                <i class="bi bi-filter"></i> Filters
            </button>
            <button type="button" class="btn btn-success d-none" id="bulkPrintBtn">
                <i class="bi bi-printer"></i> Bulk Print
            </button>
           <!-- <button type="button" class="btn btn-danger d-none" id="cancelShipmentBtn">
                <i class="bi bi-x-circle"></i> Cancel Shipment
            </button> -->
        </div>
    </div>
    <!-- Filter Tags Container -->
    <div id="filterTags" class="d-flex flex-wrap gap-2 mb-3"></div>
    <div class="modal right fade" id="rightModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Filters</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Order Number Tags Input -->
                    <div class="mb-3">
                        <label class="form-label">Order No.</label>
                        <input type="text" class="form-control" id="filterOrderNumber" placeholder="Enter order numbers, separated by commas, spaces, or Enter">
                        <small class="form-text text-muted">Separate order numbers with a comma, space bar, or enter key</small>
                    </div>
                    <!-- Marketplace -->
                    <div class="mb-3">
                        <label class="form-label">Platform</label>
                       <select class="form-select" id="filterMarketplace" name="filterMarketplace[]" multiple>
                            @foreach($marketplaces as $channel)
                                <option value="{{ strtolower($channel) }}">{{ ucfirst($channel) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Shipping Carrier</label>
                        <select class="form-select" id="filterShippingCarrier">
                            <option value="">-- Select Carrier --</option>
                            @foreach($carrierName as $carrier)
                                <option value="{{ strtolower($carrier) }}">{{ ucfirst($carrier) }}</option>
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
        <div class="col-md-12 col-12">
            <table id="shipmentTable" class="shipment-table table table-bordered nowrap">
                <thead>
                    <tr>
                        <!-- <th><input type="checkbox" id="selectAll"></th> -->
                        <th>Platform</th>
                        <th>Order Id</th>
                        <!-- <th>Age</th> -->
                        <th>Order Date</th>
                        <th>Payment Status</th>
                        <th>Fulfillment Status</th>
                        <th>Item SKU</th>
                        <th>Recipient</th>
                        <th>Quantity</th>
                        <th>Order Total</th>
                        <th>Carrier</th>
                        <th>Service</th>
                        <th>heigth</th>
                        <th>width</th>
                        <th>length</th>
                        <th>weight</th>
                        <th>Tracking ID</th>
                        <th>Shipping Cost</th>
                        <th>Print Count</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
@endsection
@section('script')
<link rel="stylesheet" href="//cdn.jsdelivr.net/bootstrap.tagsinput/0.4.2/bootstrap-tagsinput.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="//cdn.jsdelivr.net/bootstrap.tagsinput/0.4.2/bootstrap-tagsinput.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        let select2Instance = null;
        function initSelect2() {
            if (select2Instance) {
                select2Instance.destroy();
            }
            select2Instance = $('#filterMarketplace').select2({
                placeholder: '-- Select Marketplace --',
                allowClear: true,
                width: '100%',
                dropdownParent: $('#rightModal .modal-body')
            });
        }
        $('#rightModal').on('shown.bs.modal', function () {
            initSelect2();
        });
        const shippingServices = @json($services);
        $('#filterOrderNumber').tagsinput({
            trimValue: true,
            confirmKeys: [13, 44, 32],
            focusClass: 'has-focus'
        });
        $('.bootstrap-tagsinput input').on('focus', function() {
            $(this).closest('.bootstrap-tagsinput').addClass('has-focus');
        }).on('blur', function() {
            $(this).closest('.bootstrap-tagsinput').removeClass('has-focus');
        });
        let table = $('#shipmentTable').DataTable({
            processing: true,
            serverSide: true,
            scrollX: true,
            // scrollY: "400px",
            // fixedColumns: {
            // leftColumns: 2,
            // rightColumns: 1
            // },
            ajax: {
                url: '{{ route("orders.shipped.data") }}',
                data: function(d) {
                    d.channels = $('.sales-channel-filter:checked').map(function() {
                        return this.value;
                    }).get();
                    d.marketplace = $('#filterMarketplace').val();
                    d.order_number = $('#filterOrderNumber').val(); // Comma-separated from tagsinput
                    d.from_date = $('#filterFromDate').val();
                    d.to_date = $('#filterToDate').val();
                    d.status = $('#filterStatus').val();
                    d.shipping_carrier = $('#filterShippingCarrier').val();
                }
            },
            columns: [
                // {
                // data: 'id',
                // render: function (data, type, row) {
                // return `<input type="checkbox" class="order-checkbox" value="${data}">`;
                // },
                // orderable: false,
                // searchable: false
                // },
               {
                        data: 'marketplace',
                        title: 'Marketplace',
                        render: function(data, type, row) {
                            const name = data.charAt(0).toUpperCase() + data.slice(1);
                            if (data === 'amazon') {
                                return '<span><i class="fab fa-amazon me-2 text-primary fs-5"></i>Amazon</span>';
                            } else if (data === 'reverb') {
                                return '<span><i class="fas fa-guitar me-2 text-primary fs-5"></i>' + name + '</span>';
                            } else if (data === 'walmart') {
                                return '<span><i class="fas fa-store me-2 text-warning fs-5"></i>' + name + '</span>';
                            } else if (data === 'tiktok') {
                                return '<span><i class="fab fa-tiktok me-2" style="color:#ee1d52;"></i>' + name + '</span>';
                            } else if (data === 'temu') {
                                return '<span><i class="fas fa-shopping-bag me-2" style="color:#ff6200;"></i>' + name + '</span>';
                            } else if (data === 'shopify') {
                                return '<span><i class="fab fa-shopify me-2 text-success fs-5"></i>' + name + '</span>';
                            } else if (data.startsWith('ebay')) {
                                return '<span><i class="fab fa-ebay me-2 text-danger fs-5"></i>' + name + '</span>';
                            } else if (data === 'best buy usa' || data === 'Best Buy USA') {
                                return '<span><i class="fas fa-bolt me-2" style="color:#0046be;"></i>' + name + '</span>';
                            } else {
                                return '<span><i class="fas fa-globe me-2 text-secondary fs-5"></i>' + name + '</span>';
                            }
                        }
                },
                { data: 'order_number' },
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
                        }
               },
               {
                    data: 'payment_status',
                    render: function (data) {
                        if (!data) return '<span class="badge bg-secondary">Paid</span>';
                        switch (data.toUpperCase()) {
                            case 'PAID':
                                return '<span class="badge bg-success">Paid</span>';
                            case 'PENDING':
                                return '<span class="badge bg-warning text-dark">Pending</span>';
                            case 'REFUNDED':
                                return '<span class="badge bg-info text-dark">Refunded</span>';
                            case 'UNPAID':
                                return '<span class="badge bg-danger">Unpaid</span>';
                            default:
                                return '<span class="badge bg-secondary">' + data + '</span>';
                        }
                    }
                },
                {
                    data: 'fulfillment_status',
                    render: function (data) {
                        if (!data) return '-';
                        switch (data.toUpperCase()) {
                            case 'FULFILLED':
                                return '<span class="badge bg-success">Fulfilled</span>';
                            case 'UNFULFILLED':
                                return '<span class="badge bg-danger">Unfulfilled</span>';
                            case 'PARTIALLY_FULFILLED':
                                return '<span class="badge bg-warning text-dark">Partially Fulfilled</span>';
                            case 'CANCELLED':
                                return '<span class="badge bg-dark">Cancelled</span>';
                            case 'RETURNED':
                                return '<span class="badge bg-info text-dark">Returned</span>';
                            case 'IN_PROGRESS':
                                return '<span class="badge bg-primary">In Progress</span>';
                            default:
                                return '<span class="badge bg-secondary">' + data + '</span>';
                        }
                    }
                },
                {
                    data: 'item_sku',
                    render: function (data, type, row) {
                        return data && data.trim() !== '' ? data : '-';
                    }
               },
               {
                    data: 'recipient_name',
                    render: function (data, type, row) {
                        if (!data || data.trim() === '') {
                            return '-'; // or '' if you prefer empty
                        }
                        return `<a href="#"
                                    class="recipient-link"
                                    data-order='${JSON.stringify(row).replace(/'/g, "&apos;")}'
                                >${data}</a>`;
                    }
                },
                { data: 'quantity', className: 'text-end' },
                {
                    data: 'order_total',
                    render: $.fn.dataTable.render.number(',', '.', 2, '$'),
                    className: 'text-end'
                },
                {
                    data: 'carrier',
                    render: function(data, type, row) {
                        if (!data) return '<span class="badge bg-secondary">N/A</span>'; // empty case
                        data = data.toLowerCase();
                        if (data === 'ups') {
                            return '<span class="badge bg-primary">UPS</span>';
                        } else if (data === 'usps') {
                            return '<span class="badge bg-warning text-dark">USPS</span>';
                        } else if (data === 'fedex') {
                            return '<span class="badge bg-info text-dark">FedEx</span>';
                        } else if (data === 'dhl') {
                            return '<span class="badge bg-danger">DHL</span>';
                        } else {
                            return '<span class="badge bg-secondary">' + data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
                        }
                    }
                },
                { data: 'service_type', className: 'text-end' },
                 { data: 'total_height', className: 'text-end' },
                  { data: 'total_length', className: 'text-end' },
                   { data: 'total_weight', className: 'text-end' },
                    { data: 'total_width', className: 'text-end' },
                {
                    data: 'tracking_number',
                    className: 'text-end',
                    render: function(data, type, row) {
                        if (data && data.trim() !== '') {
                            if (row.tracking_url && row.tracking_url.trim() !== '') {
                                return `<a href="${row.tracking_url}" target="_blank" class="text-decoration-none">
                                            <i class="fa fa-truck"></i> ${data}
                                        </a>`;
                            } else {
                                return `<i class="fa fa-truck text-muted"></i> ${data}`;
                            }
                        }
                        return '-';
                    }
                },
                { data: 'shipping_cost', className: 'text-end' },
                {
                    data: 'print_count',
                    className: 'text-end',
                    render: function(data, type, row) {
                        return data || 0;
                    },
                    orderable: true,
                    searchable: false
                },
              {
                    data: null,
                    render: function(data, type, row) {
                        let buttons = ``;
                        @can('cancel_shipment')
                            buttons += `
                                <button class="btn btn-sm btn-danger action-btn cancel-order-btn"
                                        data-order-id="${row.id}"
                                        data-label-id="${row.label_id || ''}">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            `;
                        @endcan
                        // Reprint button with printer icon
                        buttons += `
                            <button class="btn btn-sm btn-success action-btn print-order-btn"
                                    data-order-id="${row.id}">
                                <i class="bi bi-printer"></i>
                            </button>
                        `;
                        return buttons;
                    },
                    orderable: false,
                    searchable: false
                }
            ],
            order: [[3, 'desc']],
            pageLength: 100,
            lengthMenu: [10, 25, 50, 100]
        });
        // Function to render filter tags
        function renderFilterTags() {
            const $filterTags = $('#filterTags');
            $filterTags.empty();
            // Order Numbers (multiple tags)
            const orderNumbers = $('#filterOrderNumber').tagsinput('items') || [];
            orderNumbers.forEach(function(num) {
                $filterTags.append(`
                    <span class="badge bg-primary filter-tag" data-filter="order_number" data-value="${num}">
                        Order No.: ${num}
                        <button type="button" class="btn-close btn-close-white ms-1" aria-label="Remove"></button>
                    </span>
                `);
            });
            // Marketplace (multiple)
            const marketplaceVals = $('#filterMarketplace').val() || [];
            marketplaceVals.forEach(function(val) {
                if (val) { // Skip empty value
                    const marketplaceText = $('#filterMarketplace option[value="' + val + '"]').text() || val;
                    $filterTags.append(`
                        <span class="badge bg-info text-dark filter-tag" data-filter="marketplace" data-value="${val}">
                            Platform: ${marketplaceText}
                            <button type="button" class="btn-close btn-close-white ms-1" aria-label="Remove"></button>
                        </span>
                    `);
                }
            });
            // Shipping Carrier
            const carrierVal = $('#filterShippingCarrier').val();
            if (carrierVal) {
                const carrierText = $('#filterShippingCarrier option:selected').text() || carrierVal;
                $filterTags.append(`
                    <span class="badge bg-warning text-dark filter-tag" data-filter="shipping_carrier">
                        Carrier: ${carrierText}
                        <button type="button" class="btn-close btn-close-white ms-1" aria-label="Remove"></button>
                    </span>
                `);
            }
            // From Date
            const fromDate = $('#filterFromDate').val();
            if (fromDate) {
                $filterTags.append(`
                    <span class="badge bg-success filter-tag" data-filter="from_date">
                        From: ${fromDate}
                        <button type="button" class="btn-close btn-close-white ms-1" aria-label="Remove"></button>
                    </span>
                `);
            }
            // To Date
            const toDate = $('#filterToDate').val();
            if (toDate) {
                $filterTags.append(`
                    <span class="badge bg-danger filter-tag" data-filter="to_date">
                        To: ${toDate}
                        <button type="button" class="btn-close btn-close-white ms-1" aria-label="Remove"></button>
                    </span>
                `);
            }
        }
        // Event delegation for filter tag removal
        $(document).on('click', '.filter-tag .btn-close', function() {
            const $tag = $(this).closest('.filter-tag');
            const filterType = $tag.data('filter');
            // Clear the corresponding input
            switch (filterType) {
                case 'order_number':
                    const value = $tag.data('value');
                    $('#filterOrderNumber').tagsinput('remove', value);
                    break;
                case 'marketplace':
                    const marketplaceValue = $tag.data('value');
                    const currentMarketplaceVals = $('#filterMarketplace').val() || [];
                    const newMarketplaceVals = currentMarketplaceVals.filter(v => v !== marketplaceValue);
                    $('#filterMarketplace').val(newMarketplaceVals.length ? newMarketplaceVals : null).trigger('change');
                    break;
                case 'shipping_carrier':
                    $('#filterShippingCarrier').val('');
                    break;
                case 'from_date':
                    $('#filterFromDate').val('');
                    break;
                case 'to_date':
                    $('#filterToDate').val('');
                    break;
            }
            // Remove the tag
            $tag.remove();
            // Reload table
            table.ajax.reload();
            // Re-render remaining tags
            renderFilterTags();
        });
        // Filter handling
        $('#applyFilters').on('click', function() {
            $('#rightModal').modal('hide');
            renderFilterTags();
            table.ajax.reload();
        });
        $('#resetFilters').on('click', function() {
            $('#filterOrderNumber').tagsinput('removeAll');
            $('#filterMarketplace').val(null).trigger('change');
            $('#filterFromDate').val('');
            $('#filterToDate').val('');
            $('#filterStatus').val('');
            $('#filterShippingCarrier').val('');
            $('#filterTags').empty();
            $('#rightModal').modal('hide');
            table.ajax.reload();
        });
        $('#selectAll').on('change', function() {
            $('.order-checkbox', table.rows().nodes()).prop('checked', this.checked);
            updateBulkButtons();
        });
        $('#shipmentTable').on('change', '.order-checkbox', function() {
            updateBulkButtons();
        });
        function updateBulkButtons() {
            let selectedOrders = $('.order-checkbox:checked');
            $('#bulkPrintBtn').toggleClass('d-none', selectedOrders.length < 2);
            $('#cancelShipmentBtn').toggleClass('d-none', selectedOrders.length < 1);
        }
        function printLabels(orderIds, $button) {
            if (orderIds.length < 1) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Orders Selected',
                    text: 'Please select at least one order to print.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            $('#loaderText').text(orderIds.length > 1 ? 'Generating Labels...' : 'Generating Label...');
            $('#loader').show();
            $('body').addClass('loader-active');
            $button.prop('disabled', true);
            $.ajax({
                url: '{{ route("orders.print.labelsv1", "s") }}',
                type: 'POST',
                data: {
                    order_ids: orderIds,
                    _token: '{{ csrf_token() }}'
                },
                dataType: 'json',
                success: function(response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    $button.prop('disabled', false);
                    if (response.success) {
                        if (response.label_urls && response.label_urls.length) {
                            response.label_urls.forEach(url => {
                                let printWindow = window.open(url, '_blank');
                                if (printWindow) {
                                    printWindow.onload = function() {
                                        printWindow.print();
                                    };
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Popup Blocked',
                                        text: 'Please allow popups for this site to print the label(s).',
                                        confirmButtonText: 'OK'
                                    });
                                }
                            });
                        }
                        // Swal.fire({
                        // icon: 'success',
                        // title: 'Success',
                        // text: response.message || `Label${orderIds.length > 1 ? 's' : ''} generated and printing initiated successfully!`,
                        // confirmButtonText: 'OK'
                        // });
                        table.ajax.reload();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || `Failed to generate label${orderIds.length > 1 ? 's' : ''}.`,
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    // Hide loader and re-enable button
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    $button.prop('disabled', false);
                    let msg = `An unexpected error occurred while generating label${orderIds.length > 1 ? 's' : ''}.`;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    } else if (xhr.responseText) {
                        try {
                            let parsed = JSON.parse(xhr.responseText);
                            if (parsed.message) msg = parsed.message;
                        } catch (e) {
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
        }
        $('#bulkPrintBtn').on('click', function(e) {
            e.preventDefault();
            let $button = $(this);
            let selectedOrders = $('.order-checkbox:checked').map(function() {
                return this.value;
            }).get();
            if (selectedOrders.length < 2) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Selection',
                    text: 'Please select at least two orders to bulk print.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            printLabels(selectedOrders, $button);
        });
        $('#shipmentTable').on('click', '.print-order-btn', function(e) {
            e.preventDefault();
            let $button = $(this);
            let orderId = $button.data('order-id');
            printLabels([orderId], $button);
        });
        $('#cancelShipmentBtn').on('click', function(e) {
            e.preventDefault();
            let $button = $(this);
            let selectedOrders = $('.order-checkbox:checked').map(function() {
                return this.value;
            }).get();
            if (selectedOrders.length < 1) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Orders Selected',
                    text: 'Please select at least one order to cancel.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            Swal.fire({
                icon: 'warning',
                title: 'Confirm Cancellation',
                text: `Are you sure you want to cancel ${selectedOrders.length} label${selectedOrders.length > 1 ? 's' : ''}?`,
                showCancelButton: true,
                confirmButtonText: 'Yes, Proceed',
                cancelButtonText: 'No',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Cancellation Reason',
                        input: 'textarea',
                        inputPlaceholder: 'Enter the reason for cancellation...',
                        inputAttributes: {
                            autocapitalize: 'off'
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Cancel',
                        cancelButtonText: 'No',
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        inputValidator: (value) => {
                            if (!value || !value.trim()) {
                                return 'Please provide a reason for cancellation!';
                            }
                        }
                    }).then((reasonResult) => {
                        if (reasonResult.isConfirmed) {
                            let reason = reasonResult.value.trim();
                            // Show loader
                            $('#loaderText').text(selectedOrders.length > 1 ? 'Cancelling Labels...' : 'Cancelling Label...');
                            $('#loader').show();
                            $('body').addClass('loader-active');
                            $button.prop('disabled', true);
                            $.ajax({
                                url: '#',
                                type: 'POST',
                                data: {
                                    order_ids: selectedOrders,
                                    reason: reason,
                                    _token: '{{ csrf_token() }}'
                                },
                                dataType: 'json',
                                success: function(response) {
                                    // Hide loader and re-enable button
                                    $('#loader').hide();
                                    $('body').removeClass('loader-active');
                                    $button.prop('disabled', false);
                                    if (response.success) {
                                        Swal.fire({
                                            icon: 'success',
                                            title: 'Success',
                                            text: response.message || `Label${selectedOrders.length > 1 ? 's' : ''} cancelled successfully!`,
                                            confirmButtonText: 'OK'
                                        });
                                        table.ajax.reload();
                                    } else {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Error',
                                            text: response.message || `Failed to cancel label${selectedOrders.length > 1 ? 's' : ''}.`,
                                            confirmButtonText: 'OK'
                                        });
                                    }
                                },
                                error: function(xhr) {
                                    // Hide loader and re-enable button
                                    $('#loader').hide();
                                    $('body').removeClass('loader-active');
                                    $button.prop('disabled', false);
                                    let msg = `An unexpected error occurred while cancelling Label${selectedOrders.length > 1 ? 's' : ''}.`;
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
                        }
                    });
                }
            });
        });
        // Individual Cancel Shipment
        $('#shipmentTable').on('click', '.cancel-order-btn', function(e) {
            e.preventDefault();
            let $button = $(this);
            let orderId = $button.data('order-id');
            let labelId = $button.data('label-id');
            Swal.fire({
                icon: 'warning',
                title: 'Confirm Cancellation',
                text: 'Are you sure you want to cancel this Label?',
                showCancelButton: true,
                confirmButtonText: 'Yes, Proceed',
                cancelButtonText: 'No',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Cancellation Reason',
                        input: 'textarea',
                        inputPlaceholder: 'Enter the reason for cancellation...',
                        inputAttributes: {
                            autocapitalize: 'off'
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Cancel',
                        cancelButtonText: 'No',
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        inputValidator: (value) => {
                            if (!value || !value.trim()) {
                                return 'Please provide a reason for cancellation!';
                            }
                        }
                    }).then((reasonResult) => {
                        if (reasonResult.isConfirmed) {
                            let reason = reasonResult.value.trim();
                            // Reuse the bulk cancel function for single order
                            let selectedOrders = [orderId];
                            $('#loaderText').text('Cancelling Label...');
                            $('#loader').show();
                            $('body').addClass('loader-active');
                            $button.prop('disabled', true);
                            $.ajax({
                                url: '{{ route("orders.cancel.shipments") }}',
                                type: 'POST',
                                data: {
                                    order_ids: selectedOrders,
                                    label_ids: [labelId],
                                    reason: reason,
                                    _token: '{{ csrf_token() }}'
                                },
                                dataType: 'json',
                                success: function(response) {
                                    // Hide loader and re-enable button
                                    $('#loader').hide();
                                    $('body').removeClass('loader-active');
                                    $button.prop('disabled', false);
                                    if (response.success) {
                                        Swal.fire({
                                            icon: 'success',
                                            title: 'Success',
                                            text: response.message || 'Label cancelled successfully!',
                                            confirmButtonText: 'OK'
                                        });
                                        table.ajax.reload();
                                    } else {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Error',
                                            text: response.message || 'Failed to cancel Label.',
                                            confirmButtonText: 'OK'
                                        });
                                    }
                                },
                                error: function(xhr) {
                                    // Hide loader and re-enable button
                                    $('#loader').hide();
                                    $('body').removeClass('loader-active');
                                    $button.prop('disabled', false);
                                    let msg = 'An unexpected error occurred while cancelling the Label.';
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
                        }
                    });
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
        if (typeof order === 'string') {
            try { order = JSON.parse(order); } catch (_) {}
        }
        let estDelivery = '—';
        try {
            if (order.raw_data) {
                const rd = typeof order.raw_data === 'string' ? JSON.parse(order.raw_data) : order.raw_data;
                const earliest = rd?.shipping_info?.delivery_date?.earliest;
                const latest = rd?.shipping_info?.delivery_date?.latest;
                if (earliest && latest && earliest !== latest) {
                    estDelivery = `${fmtDate(earliest)} – ${fmtDate(latest)}`;
                } else if (earliest) {
                    estDelivery = fmtDate(earliest);
                } else if (latest) {
                    estDelivery = fmtDate(latest);
                }
            }
        } catch (_) {}
        $('#detailOrderNumber').text(order.order_number || '—');
        $('#detailCarrier').text(order.shipping_carrier || '—');
        $('#detailTracking').text(order.tracking_number || '—');
        $('#detailOrderDate').text(fmtDate(order.ship_date || order.order_date));
        $('#detailDeliveryDate').text(estDelivery);
        const addr1 = order.ship_address1 || '';
        const addr2 = order.ship_address2 || '';
        const city = order.ship_city || '';
        const state = order.ship_state || '';
        const zip = order.ship_postal_code || '';
        const country = order.ship_country || '';
        const addressLine = [addr1, addr2].filter(Boolean).join(', ');
        const cityLine = [city, state, zip].filter(Boolean).join(', ');
        $('#detailRecipient').text(order.recipient_name || '—');
        $('#detailRecipientAddress').html(
            [addressLine, cityLine, country].filter(Boolean).join('<br>')
        );
        $('#detailRecipientPhone').text(order.recipient_phone || '—');
        $('#detailRecipientEmail').text(order.recipient_email || '—');
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
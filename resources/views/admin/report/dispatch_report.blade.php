@extends('admin.layouts.admin_master')
@section('title', 'Dispatch Report')
@section('content')
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
                    <p class="mb-1 shipped-via-row"><strong>Shipped via:</strong> <span id="detailCarrier" class="text-success"></span></p>
                    <p class="mb-1 tracking-row"><strong>Tracking #:</strong> <span id="detailTracking" class="text-info"></span></p>
                    <p class="mb-1 shipment-date-row"><strong>Shipment Date:</strong> <span id="detailOrderDate"></span></p>
                    <p class="mb-1 estimated-delivery-row"><strong>Estimated Delivery:</strong> <span id="detailDeliveryDate" class="text-warning"></span></p>
                </div>
                <div class="mb-3">
                    <h6 class="fw-bold text-secondary">Recipient:</h6>
                    <p class="mb-1 recipient-name-row"><span id="detailRecipient"></span></p>
                    <p class="mb-1 address-row" id="detailRecipientAddress"></p>
                    <p class="mb-1 phone-row"><strong>Phone:</strong> <span id="detailRecipientPhone"></span></p>
                    <p class="mb-0 email-row"><strong>Email:</strong> <span id="detailRecipientEmail"></span></p>
                </div>
                <div class="mb-3">
                    <h6 class="fw-bold text-secondary">Items:</h6>
                    <ul class="list-group list-group-flush" id="detailItemsList"></ul>
                </div>
                <div class="mb-3">
                    <p class="mb-1 shipping-cost-row"><strong>Shipping Cost:</strong> <span id="detailShippingCost" class="text-success"></span></p>
                    <p class="mb-0 shipping-method-row"><strong>Shipping Method:</strong> <span id="detailShippingMethod"></span></p>
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
        <h5 class="mb-0">Dispatch Report</h5>
        <div class="d-flex gap-2">
            {{-- Single Date Filter --}}
            <form method="GET" action="{{ route('dispatch.report.index') }}" class="d-inline-block mt-0">
                <div class="input-group flex-nowrap date-group">
                    <span class="input-group-text bg-transparent border-0">
                        <i class="fas fa-calendar-alt"></i>
                    </span>
                    <input
                        type="date"
                        class="form-control border-start-0"
                        name="filter_date"
                        id="filter_date"
                        value="{{ request('filter_date', now()->format('Y-m-d')) }}"
                    >
                </div>
            </form>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rightModal">
                <i class="bi bi-filter"></i> Filters
            </button>
            <button type="button" class="btn btn-success d-none" id="bulkDispatchBtn">
                <i class="bi bi-printer"></i> Bulk Dispatch
            </button>
           <!-- <button type="button" class="btn btn-danger d-none" id="cancelShipmentBtn">
                <i class="bi bi-x-circle"></i> Cancel Shipment
            </button> -->
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
                    <!-- Marketplace -->
                    <div class="mb-3">
                        <label class="form-label">Platform</label>
                       <select class="form-select" id="filterMarketplace">
                        <option value="">-- Select Marketplace --</option>
                         @foreach($marketplaces as $channel)
                            <option value="{{ strtolower($channel) }}" {{ request('marketplace') == strtolower($channel) ? 'selected' : '' }}>{{ ucfirst($channel) }}</option>
                         @endforeach
                    </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Shipping Carrier</label>
                        <select class="form-select" id="filterShippingCarrier">
                            <option value="">-- Select Carrier --</option>
                            @foreach($carrierName as $carrier)
                                <option value="{{ strtolower($carrier) }}" {{ request('shipping_carrier') == strtolower($carrier) ? 'selected' : '' }}>{{ ucfirst($carrier) }}</option>
                            @endforeach
                        </select>
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
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Platform</th>
                        <th>Order No.</th>
                        <th>Dispatch Date</th>
                        <th>Dispatch Status</th>
                        <th>Dispatch By</th>
                        <th>Item SKU</th>
                        <th>Recipient</th>
                        <th>Quantity</th>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    const shippingServices = @json($services);
    let table = $('#shipmentTable').DataTable({
        processing: true,
        serverSide: true,
        scrollX: true,
        // scrollY: "1000px",
        ajax: {
            url: '{{ route("dispatch.report.data") }}',
            type: 'GET',
            data: function(d) {
                d.channels = $('.sales-channel-filter:checked').map(function() {
                    return this.value;
                }).get();
                d.marketplace = $('#filterMarketplace').val() || '{{ request("marketplace") }}';
                d.filter_date = $('#filter_date').val() || '{{ request("filter_date", now()->format("Y-m-d")) }}';
                d.shipping_carrier = $('#filterShippingCarrier').val() || '{{ request("shipping_carrier") }}';
            }
        },
        columns: [
            {
                data: 'id',
                render: function (data, type, row) {
                    if (parseInt(row.dispatch_status) === 1) {
                        return '<span class="text-success fw-bold">✓</span>';
                    }
                    return `<input type="checkbox" class="order-checkbox" value="${data}">`;
                },
                orderable: false,
                searchable: false
            },
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
                data: 'dispatch_date',
                render: function(data, type, row) {
                    return fmtDate(data);
                }
           },
           {
                data: 'dispatch_status',
                render: function (data, type, row) {
                    switch (Number(data)) {
                        case 1:
                            return '<span class="badge bg-success">Dispatched</span>';
                        case 0:
                        default:
                            return '<span class="badge bg-secondary">Pending</span>';
                    }
                }
            },
            {
                data: 'dispatched_by_name',
                render: function (data, type, row) {
                    return data && data.trim() !== '' ? data : '-';
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
                data: null,
                render: function(data, type, row) {
                    if (row.dispatch_status == 1) {
                        return `<span class="badge bg-success">Dispatched</span>`;
                    }
                    return `
                        <button class="btn btn-sm btn-success action-btn dispatch-order-btn"
                                data-order-id="${row.id}">
                            <i class="bi bi-printer"></i> Dispatch
                        </button>
                    `;
                },
                orderable: false,
                searchable: false
            }
        ],
        order: [[4, 'asc']],
        pageLength: 100,
        lengthMenu: [10, 25, 50, 100]
    });
    // Handle date filter change without page reload
    $('#filter_date').on('change', function() {
        table.ajax.reload();
    });
    $('#applyFilters').on('click', function() {
        $('#rightModal').modal('hide');
        table.ajax.reload();
    });
    $('#resetFilters').on('click', function() {
        $('#filterMarketplace').val('');
        $('#filterShippingCarrier').val('');
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
        $('#bulkDispatchBtn').toggleClass('d-none', selectedOrders.length < 2);
        $('#cancelShipmentBtn').toggleClass('d-none', selectedOrders.length < 1);
    }
    function dispatchOrders(orderIds, $button) {
    if (orderIds.length < 1) {
        Swal.fire({
            icon: 'warning',
            title: 'No Orders Selected',
            text: 'Please select at least one order to dispatch.',
            confirmButtonText: 'OK'
        });
        return;
    }
    $('#loaderText').text(orderIds.length > 1 ? 'Dispatching Orders...' : 'Dispatching Order...');
    $('#loader').show();
    $('body').addClass('loader-active');
    $button.prop('disabled', true);
    $.ajax({
        url: '{{ route("dispatch.report.dispatch") }}',
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
                Swal.fire({
                    icon: 'success',
                    title: 'Dispatched',
                    text: response.message || `Order${orderIds.length > 1 ? 's' : ''} dispatched successfully!`,
                    confirmButtonText: 'OK'
                });
                table.ajax.reload();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message || `Failed to dispatch order${orderIds.length > 1 ? 's' : ''}.`,
                    confirmButtonText: 'OK'
                });
            }
        },
        error: function(xhr) {
            $('#loader').hide();
            $('body').removeClass('loader-active');
            $button.prop('disabled', false);
            let msg = `An unexpected error occurred while dispatching order${orderIds.length > 1 ? 's' : ''}.`;
            if (xhr.responseJSON && xhr.responseJSON.message) {
                msg = xhr.responseJSON.message;
            } else if (xhr.responseText) {
                try {
                    let parsed = JSON.parse(xhr.responseText);
                    if (parsed.message) msg = parsed.message;
                } catch (e) {}
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
    $('#bulkDispatchBtn').on('click', function(e) {
        e.preventDefault();
        let $button = $(this);
        let selectedOrders = $('.order-checkbox:checked').map(function() {
            return this.value;
        }).get();
        if (selectedOrders.length < 2) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Selection',
                text: 'Please select at least two orders to bulk dispatch.',
                confirmButtonText: 'OK'
            });
            return;
        }
        dispatchOrders(selectedOrders, $button);
    });
    $('#shipmentTable').on('click', '.dispatch-order-btn', function(e) {
        e.preventDefault();
        let $button = $(this);
        let orderId = $button.data('order-id');
        dispatchOrders([orderId], $button);
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
            confirmButtonText: 'Yes, Cancel',
            cancelButtonText: 'No',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loader
                $('#loaderText').text(selectedOrders.length > 1 ? 'Cancelling Label...' : 'Cancelling Label...');
                $('#loader').show();
                $('body').addClass('loader-active');
                $button.prop('disabled', true);
                $.ajax({
                    url: '{{ route("orders.cancel.shipments") }}',
                    type: 'POST',
                    data: {
                        order_ids: selectedOrders,
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
                                text: response.message || `label${selectedOrders.length > 1 ? 's' : ''} cancelled successfully!`,
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
    });
    // Individual Cancel Shipment
    $('#shipmentTable').on('click', '.cancel-order-btn', function(e) {
        e.preventDefault();
        let $button = $(this);
        let orderId = $button.data('order-id');
        Swal.fire({
            icon: 'warning',
            title: 'Confirm Cancellation',
            text: 'Are you sure you want to cancel this Label?',
            showCancelButton: true,
            confirmButtonText: 'Yes, Cancel',
            cancelButtonText: 'No',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
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
    // Reset modal visibility
    $('.shipped-via-row, .tracking-row, .shipment-date-row, .estimated-delivery-row, .recipient-name-row, .address-row, .phone-row, .email-row, .shipping-cost-row, .shipping-method-row').show();
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
    // Shipped via (carrier)
    let carrierText = order.carrier || '—';
    $('#detailCarrier').text(carrierText);
    if (carrierText === '—') {
        $('.shipped-via-row').hide();
    }
    // Tracking
    let trackingText = order.tracking_number || '—';
    $('#detailTracking').text(trackingText);
    if (trackingText === '—') {
        $('.tracking-row').hide();
    }
    // Shipment Date
    let shipmentDateText = fmtDate(order.ship_date || order.order_date);
    $('#detailOrderDate').text(shipmentDateText);
    if (shipmentDateText === '—') {
        $('.shipment-date-row').hide();
    }
    // Estimated Delivery
    $('#detailDeliveryDate').text(estDelivery);
    if (estDelivery === '—') {
        $('.estimated-delivery-row').hide();
    }
    // Recipient Name
    let recipientName = order.recipient_name || '—';
    $('#detailRecipient').text(recipientName);
    if (recipientName === '—') {
        $('.recipient-name-row').hide();
    }
    // Address
    const addr1 = order.ship_address1 || '';
    const addr2 = order.ship_address2 || '';
    const city = order.ship_city || '';
    const state = order.ship_state || '';
    const zip = order.ship_postal_code || '';
    const country = order.ship_country || '';
    const addressLine = [addr1, addr2].filter(Boolean).join(', ');
    const cityLine = [city, state, zip].filter(Boolean).join(', ');
    let addressHtml = [addressLine, cityLine, country].filter(Boolean).join('<br>');
    $('#detailRecipientAddress').html(addressHtml);
    if (!addressHtml.trim()) {
        $('.address-row').hide();
    }
    // Phone
    let phoneText = order.recipient_phone || '—';
    $('#detailRecipientPhone').text(phoneText);
    if (phoneText === '—') {
        $('.phone-row').hide();
    }
    // Email
    let emailText = order.recipient_email || '—';
    $('#detailRecipientEmail').text(emailText);
    if (emailText === '—') {
        $('.email-row').hide();
    }
    // Items
    $('#detailItemsList').html(`
        <li>${order.quantity || 0}x ${order.item_name || '—'} ${order.item_sku ? `(SKU: ${order.item_sku})` : ''}</li>
    `);
    // Shipping Cost
    let shippingCostText = fmtMoney(order.shipping_cost);
    $('#detailShippingCost').text(shippingCostText);
    if (shippingCostText === '$0.00') {
        $('.shipping-cost-row').hide();
    }
    // Shipping Method
    let shippingMethodText = order.shipping_service || '—';
    $('#detailShippingMethod').text(shippingMethodText);
    if (shippingMethodText === '—') {
        $('.shipping-method-row').hide();
    }
    $('#orderDetailsModal').modal('show');
});
});
</script>
@endsection
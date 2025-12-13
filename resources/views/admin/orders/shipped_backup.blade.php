
@extends('admin.layouts.admin_master')
@section('title', 'Shipped')
@section('content')
<link rel="stylesheet" href="{{ asset('admin/css/awaiting_shipment.css') }}">
<!-- Order Receipt Modal -->
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
    <div id="loader" class="loader">
        <div class="loader-content">
            <div class="spinner"></div>
            <span id="loaderText">Processing...</span>
        </div>
    </div>

    <div class="page-title d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Shipped</h5>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rightModal">
                <i class="bi bi-filter"></i> Filters
            </button>
            <button type="button" class="btn btn-success d-none" id="bulkPrintBtn">
                <i class="bi bi-printer"></i> Bulk Print
            </button>
            <button type="button" class="btn btn-danger d-none" id="cancelShipmentBtn">
                <i class="bi bi-x-circle"></i> Cancel Shipment
            </button>
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
                        <label class="form-label">Marketplace</label>
                       <select class="form-select" id="filterMarketplace">
                        <option value="">-- Select Marketplace --</option>
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
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Marketplace</th>
                        <th>Order #</th>
                        <th>Age</th>
                        <th>Order Date</th>
                        <th>Item SKU</th>
                        <th>Recipient</th>
                        <th>Quantity</th>
                        <th>Order Total</th>
                        <th>Carrier Name</th>
                        <th>Tracking ID</th>
                        <th>Shipping Cost</th>
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
        scrollY: "400px",
        ajax: {
            url: '{{ route("orders.shipped.data") }}',
            data: function(d) {
                d.channels = $('.sales-channel-filter:checked').map(function() {
                    return this.value;
                }).get();
                d.marketplace = $('#filterMarketplace').val();
                d.from_date = $('#filterFromDate').val();
                d.to_date = $('#filterToDate').val();
                d.status = $('#filterStatus').val();
                d.shipping_carrier = $('#filterShippingCarrier').val();
            }
        },
        columns: [
            {
                data: 'id',
                render: function (data, type, row) {
                    const isFedExOrUps = row.shipping_carrier && 
                        (row.shipping_carrier.toLowerCase() === 'fedex' ||
                         row.shipping_carrier.toLowerCase() === 'ups');
                    if (!isFedExOrUps) return '';

                    return `<input type="checkbox" class="order-checkbox" value="${data}">`;
                },
                orderable: false,
                searchable: false
            },
            {
                    data: 'marketplace',
                    title: 'Marketplace',
                    render: function(data, type, row) {
                        if (data === 'amazon') {
                            return '<span class="badge bg-success">Amazon</span>';
                        } else if (data === 'reverb') {
                            return '<span class="badge bg-primary">Reverb</span>';
                        } else if (data === 'walmart') {
                            return '<span class="badge bg-warning text-dark">Walmart</span>';
                        } 
                         else if (data === 'tiktok') {
                           return '<span class="badge badge-tiktok">TikTok</span>';
                        }   
                        else if (data === 'temu') {
                            return '<span class="badge badge-temu">Temu</span>';
                        }              
                        else if (data === 'shopify') {
                            return '<span class="badge bg-info text-dark">Shopify</span>';
                        } else if (data.startsWith('ebay')) {
                            return '<span class="badge bg-danger">' + data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
                        } else {
                            return '<span class="badge bg-secondary">' + data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
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
                data: 'order_date',
                render: function(data) {
                    if (!data) return '';
                    let d = new Date(data);
                    return d.toLocaleDateString('en-GB');
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
                        return '-'; 
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
                data: 'service_type',
                render: function(data, type, row) {
                    if (!data) return '<span class="badge bg-secondary">N/A</span>'; 

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
            {
                data: 'tracking_number',
                    className: 'text-end',
                    render: function (data, type, row) {
                        return data && data.trim() !== '' ? data : '-';
                    }
            },
            { data: 'shipping_cost', className: 'text-end' },
            {
                data: null,
                render: function(data, type, row) {
                    const cancelDisabled = !row.tracking_number || row.status !== 'shipped' ? 'disabled' : '';
                    const isFedExOrUps = row.shipping_carrier && 
                        (row.shipping_carrier.toLowerCase() === 'fedex' ||
                         row.shipping_carrier.toLowerCase() === 'ups');

                    return isFedExOrUps ? `
                        <button class="btn btn-sm btn-danger action-btn cancel-order-btn" data-order-id="${row.id}">
                            <i class="bi bi-x-circle"></i> Cancel
                        </button>
                        <button class="btn btn-sm btn-success action-btn print-order-btn" data-order-id="${row.id}">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    ` : '';
                },
                orderable: false,
                searchable: false
            }

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
        $('#filterStatus').val('');
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
            url: '{{ route("orders.print.labels") }}',
            type: 'POST',
            data: {
                order_ids: orderIds,
                _token: '{{ csrf_token() }}'
            },
            dataType: 'json',
            success: function(response) {
                // Hide loader and re-enable button
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
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: response.message || `Label${orderIds.length > 1 ? 's' : ''} generated and printing initiated successfully!`,
                        confirmButtonText: 'OK'
                    });
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
            text: `Are you sure you want to cancel ${selectedOrders.length} shipment${selectedOrders.length > 1 ? 's' : ''}?`,
            showCancelButton: true,
            confirmButtonText: 'Yes, Cancel',
            cancelButtonText: 'No',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loader
                $('#loaderText').text(selectedOrders.length > 1 ? 'Cancelling Shipments...' : 'Cancelling Shipment...');
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
                                text: response.message || `Shipment${selectedOrders.length > 1 ? 's' : ''} cancelled successfully!`,
                                confirmButtonText: 'OK'
                            });
                            table.ajax.reload();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || `Failed to cancel shipment${selectedOrders.length > 1 ? 's' : ''}.`,
                                confirmButtonText: 'OK'
                            });
                        }
                    },
                    error: function(xhr) {
                        // Hide loader and re-enable button
                        $('#loader').hide();
                        $('body').removeClass('loader-active');
                        $button.prop('disabled', false);

                        let msg = `An unexpected error occurred while cancelling shipment${selectedOrders.length > 1 ? 's' : ''}.`;
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
            text: 'Are you sure you want to cancel this shipment?',
            showCancelButton: true,
            confirmButtonText: 'Yes, Cancel',
            cancelButtonText: 'No',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                // Reuse the bulk cancel function for single order
                let selectedOrders = [orderId];
                $('#loaderText').text('Cancelling Shipment...');
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
                                text: response.message || 'Shipment cancelled successfully!',
                                confirmButtonText: 'OK'
                            });
                            table.ajax.reload();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || 'Failed to cancel shipment.',
                                confirmButtonText: 'OK'
                            });
                        }
                    },
                    error: function(xhr) {
                        // Hide loader and re-enable button
                        $('#loader').hide();
                        $('body').removeClass('loader-active');
                        $button.prop('disabled', false);

                        let msg = 'An unexpected error occurred while cancelling the shipment.';
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
  console.log(order,'order');
  if (typeof order === 'string') { try { order = JSON.parse(order); } catch (_) {} }
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
  $('#detailOrderNumber').text(order.order_number || '—');

  $('#detailCarrier').text(order.shipping_carrier || '—');
  $('#detailTracking').text(order.tracking_number || '—');
  $('#detailOrderDate').text(fmtDate(order.ship_date || order.order_date));
  $('#detailDeliveryDate').text(estDelivery);


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

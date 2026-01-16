@extends('admin.layouts.admin_master')
@section('title', 'Orders Awaiting Fulfillment')

@section('content')
<meta name="csrf-token" content="{{ csrf_token() }}">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
    .card { border: 1px solid #e1e1e1; border-radius: 4px; box-shadow: none; }
    .card-body { padding: 1rem; }
    .form-select, .form-control { border: 1px solid #ced4da; border-radius: 4px; display: inline-block; padding: 0.25rem 1rem; font-size: 0.875rem; margin-right:0.5rem; }
    .input-group-text { border: 1px solid #ced4da; border-left: none; border-radius: 0 4px 4px 0; background-color: #fff; padding: 0.375rem 0.75rem; margin-left: -1px; }
    .btn-primary { background-color: #5cb85c; border-color: #5cb85c; border-radius: 0; padding: 0.5rem 1.5rem; }
    .btn-primary:hover { background-color: #4cae4c; border-color: #4cae4c; }
    .sticky-top { top: 20px; }
    .text-muted { color: #6c757d !important; font-size: 0.875rem; }
    .shipping-option { display: flex; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid #e1e1e1; }
    .shipping-option:last-child { border-bottom: none; }
    .shipping-label { font-weight: 600; margin-right: 1rem; flex: 1; }
    .shipping-details { flex: 3; margin-right: 1rem; }
    .shipping-price { font-weight: 600; color: #000; margin-left: auto; }
    .d-flex > div { flex: 1; text-align: left; }
    .d-flex > div:last-child { text-align: right; }
    .hover-shadow:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    #screenLoader { display: none; position: fixed; z-index: 9999; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(255,255,255,0.7); display: flex; justify-content: center; align-items: center; }
    #screenLoader .spinner-border { width: 3rem; height: 3rem; border-width: 0.4rem; }
    .edit-address { cursor: pointer; margin-left: 10px; color: #0d6efd; }
</style>

<!-- Full-screen Loader -->
<div id="screenLoader">
    <div class="spinner-border text-success" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<div class="container mt-3">
    <div class="row g-2">
        <!-- Main Content -->
        <div class="col-md-8">

            <!-- Ship From -->
            <div class="card mb-3 shadow-sm border-light">
                <div class="card-body">
                    <h5 class="fw-bold mb-3">
                        <i class="fas fa-warehouse me-2 text-primary"></i> Ship From
                    </h5>
                   <select class="form-select" name="ship_from" id="shipFromSelect">
                    <option value="">-- Select Warehouse --</option>
                    @foreach($shippers as $shipper)
                        <option value="{{ $shipper->id }}" selected>
                            {{ $shipper->name }} - {{ $shipper->address }}, {{ $shipper->city }}, {{ $shipper->state }}, {{ $shipper->postal_code }}, {{ $shipper->country }}
                        </option>
                    @endforeach
                </select>
                </div>
            </div>

            <!-- Shipping Address -->
            <div class="card mb-3 shadow-sm border-light">
                <div class="card-body d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="fw-bold mb-3">
                            <i class="bi bi-geo-alt-fill me-2 text-primary"></i> Shipping Address
                        </h5>
                        <div class="d-flex flex-column gap-1" id="addressDisplay">
                            <span class="fw-semibold">{{ $order->recipient_name }}</span>
                            <span>{{ $order->ship_address1 }}</span>
                            @if($order->ship_address2)
                                <span>{{ $order->ship_address2 }}</span>
                            @endif
                            <span>{{ $order->ship_city }}, {{ $order->ship_state }} {{ $order->ship_postal_code }}</span>
                            <span>{{ $order->ship_country }}</span>
                        </div>
                    </div>
                    <div>
                        <i class="fas fa-edit fa-lg edit-address" data-bs-toggle="modal" data-bs-target="#editAddressModal"></i>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="fw-bold mb-4">Items</h5>
                    @foreach($order->items as $item)
                        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 p-3 border rounded shadow-sm hover-shadow">
                            
                            <div class="d-flex align-items-center flex-grow-1">
                                <div>
                                    <div class="fw-semibold">{{ $item->product_name }}</div>
                                    <div class="text-muted small">SKU: {{ $item->sku }}</div>
                                  @php
                                        $values = json_decode($order->cost->Values ?? '{}', true);
                                        $qty    = $item->quantity_ordered ?? 1;

                                        // Weight rule
                                        if ($qty == 1) {
                                            $weightLb = $values['wt_decl'] ?? 0;
                                        } else {
                                            $weightLb = ($values['wt_act'] ?? 0) * $qty;
                                        }

                                        // Dimensions
                                        $length = $values['l'] ?? 0;
                                        $width  = $values['w'] ?? 0;
                                        $height = $values['h'] ?? 0;
                                    @endphp

                                    <div class="text-muted small">
                                        Dimensions: {{ $length }} x {{ $width }} x {{ $height }} in,
                                        Weight: {{ $weightLb }} lb
                                    </div>

                                </div>
                            </div>

                            <div class="text-center mx-6">
                                <div class="fw-semibold">Qty</div>
                                <div>{{ $item->quantity_ordered }}</div>
                            </div>

                            <div class="text-end mx-6">
                                <div class="fw-semibold">Unit Price</div>
                                <div>${{ number_format($item->unit_price, 2) }}</div>

                                <div class="fw-semibold mt-1">Item Tax</div>
                                <div>${{ number_format($item->item_tax, 2) }}</div>

                                <div class="fw-semibold mt-1">Total</div>
                                <div>${{ number_format($item->unit_price + $item->item_tax, 2) }}</div>
                            </div>
                            
                        </div>
                    @endforeach
                </div>
            </div>

                @php
                    $values = json_decode($order->cost->Values ?? '{}', true);
                    $qty    = $order->items[0]->quantity_ordered ?? 1;

                    // Weight rule (in pounds)
                    if ($qty == 1) {
                        $weightLb = $values['wt_decl'] ?? 0; // declared weight
                    } else {
                        $weightLb = ($values['wt_act'] ?? 0) * $qty; // actual weight * qty
                    }

                    // Dimensions
                    $length = $values['l'] ?? 0;
                    $width  = $values['w'] ?? 0;
                    $height = $values['h'] ?? 0;
                @endphp

                <div class="card mb-2">
                    <div class="card-body">
                        <h5 class="fw-bold mb-2">Weight:</h5>
                        <div class="d-flex align-items-center mb-3">
                            <input type="number" min="0" step="0.01"
                                   class="form-control numeric-input"
                                   id="pounds"
                                   value="{{ $weightLb }}"
                                   aria-label="Pounds">
                            <span class="input-group-text">lb</span>
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <input type="number" min="0" step="0.01"
                                   class="form-control numeric-input"
                                   id="ounces"
                                   value="0"
                                   aria-label="Ounces">
                            <span class="input-group-text">Ounces</span>
                        </div>

                        <h5 class="fw-bold mb-2">Size:</h5>
                        <div class="d-flex align-items-center">
                            <input type="number" min="0" step="0.01"
                                   class="form-control numeric-input"
                                   id="length"
                                   value="{{ $length }}"
                                   aria-label="Length">
                            <span class="input-group-text">L x</span>

                            <input type="number" min="0" step="0.01"
                                   class="form-control numeric-input"
                                   id="width"
                                   value="{{ $width }}"
                                   aria-label="Width">
                            <span class="input-group-text">W x</span>

                            <input type="number" min="0" step="0.01"
                                   class="form-control numeric-input"
                                   id="height"
                                   value="{{ $height }}"
                                   aria-label="Height">
                            <span class="input-group-text">H (in)</span>
                        </div>
                    </div>
                </div>

            <div class="card mb-2">
                <div class="card-body">
                    <h5 class="fw-bold mb-2">Shipping Provider</h5>
                    <select class="form-select" id="labelPlatformSelect">
                        <option value="shipstation" selected>ShipStation</option>
                        <!-- Sendle temporarily disabled -->
                    </select>
                </div>
           </div>

            <!-- Shipping Service -->
            <div class="card mb-2">
                <div class="card-body">
                    <h5 class="fw-bold mb-2">Shipping service</h5>
                    <div class="shipping-options">
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Sidebar -->
        <div class="col-md-3">
            <div class="card sticky-top p-3">
                <h5 class="fw-bold mb-3">Summary</h5>
                <!-- <p class="mb-1">Subtotal: <span class="float-end">${{ $order->order_total }}</span></p> -->
                <p class="mb-1">Shipping: <span class="float-end" id="shippingAmount">$0.00</span></p>
                <!-- <p class="fw-bold mb-3">Total: <span class="float-end" id="totalAmount">${{ $order->order_total }}</span></p> -->
                <button class="btn btn-primary w-100" id="buyNowBtn">Buy Shipping Now</button>
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
            <input type="hidden" name="order_id" value="{{ $order->id }}">
            <div class="mb-2">
                <label class="form-label">Recipient Name</label>
                <input type="text" class="form-control" name="recipient_name" value="{{ $order->recipient_name }}" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Address 1</label>
                <input type="text" class="form-control" name="ship_address1" value="{{ $order->ship_address1 }}" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Address 2</label>
                <input type="text" class="form-control" name="ship_address2" value="{{ $order->ship_address2 }}">
            </div>
            <div class="mb-2">
                <label class="form-label">City</label>
                <input type="text" class="form-control" name="ship_city" value="{{ $order->ship_city }}" required>
            </div>
            <div class="mb-2">
                <label class="form-label">State</label>
                <input type="text" class="form-control" name="ship_state" value="{{ $order->ship_state }}" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Postal Code</label>
                <input type="text" class="form-control" name="ship_postal_code" value="{{ $order->ship_postal_code }}" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Country</label>
                <input type="text" class="form-control" name="ship_country" value="{{ $order->ship_country }}" required>
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
@endsection
@section('script')
<script>
$(document).ready(function() {
    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
    });

    // function updateSummary(shippingPrice) {
    //     var subtotal = parseFloat("{{ $order->order_total }}");
    //     $('#shippingAmount').text(`$${shippingPrice.toFixed(2)}`);
    //     $('#totalAmount').text(`$${(subtotal + shippingPrice).toFixed(2)}`);
    // }
    // function updateSummary(shippingPrice) {
    //     var subtotal = parseFloat("{{ $order->order_total ?? 0 }}");
    //     if (isNaN(subtotal)) subtotal = 0;
    //     $('#shippingAmount').text(`$${shippingPrice.toFixed(2)}`);
    //     $('#totalAmount').text(`$${(subtotal + shippingPrice).toFixed(2)}`);
    // }
    function updateSummary(shippingPrice) {
    $('#shippingAmount').text(`$${shippingPrice.toFixed(2)}`);
   }

    $('#labelPlatformSelect').on('change', function() {
       fetchShippingOptions();
    });

    function fetchShippingOptions() {
        var orderId = "{{ $order->id }}";
        var length = $('input[aria-label="Length"]').val() || 0;
        var width  = $('input[aria-label="Width"]').val() || 0;
        var height = $('input[aria-label="Height"]').val() || 0;
        var Pounds  = parseFloat($('input[aria-label="Pounds"]').val() || 0);
        var ounce   = parseFloat($('input[aria-label="Ounces"]').val() || 0);
        var weight = Pounds + (ounce / 16);
        var shipFrom = $('#shipFromSelect').val();
        var platform = $('#labelPlatformSelect').val();

        $('#screenLoader').show();

        $.ajax({
            url: "{{ route('orders.shipping-options') }}",
            type: 'POST',
            dataType: 'json',
            data: {
                _token: '{{ csrf_token() }}',
                order_id: orderId,
                length: length,
                width: width,
                height: height,
                weight: weight,
                ship_from: shipFrom,
                platform: platform
            },
            success: function(response) {
                $('#screenLoader').hide();
                if(response.success) {
                    var $optionsContainer = $('.shipping-options');
                    $optionsContainer.empty();

                    $.each(response.options, function(index, opt) {
                        var $option = $(`
                            <div class="shipping-option">
                                <input type="radio" name="shipping" id="${opt.rate_id}" class="me-2">
                                <label for="${opt.rate_id}" class="shipping-label">${opt.name}</label>
                                <div class="shipping-details">
                                    <p class="text-muted mb-0">${opt.estimated_time} day(s)</p>
                                    <p class="text-muted mb-0">${opt.description}</p>
                                    <p class="text-muted mb-0">
                                        Dimensions: ${opt.length} x ${opt.width} x ${opt.height} in,
                                        Weight: ${opt.weight} lb
                                    </p>
                                </div>
                                <span class="shipping-price">$${parseFloat(opt.price).toFixed(2)} USD</span>
                            </div>
                        `);
                        $optionsContainer.append($option);
                    });

                    // Auto select first option
                    var $firstOption = $('input[name="shipping"]').first();
                    $firstOption.prop('checked', true);

                    // Update summary with first option price
                    var firstPriceText = $firstOption.closest('.shipping-option').find('.shipping-price').text();
                    var firstShipping = parseFloat(firstPriceText.replace('$','').replace(' USD',''));
                    updateSummary(firstShipping);

                    // On change event
                    $('input[name="shipping"]').off('change').on('change', function() {
                        var priceText = $(this).closest('.shipping-option').find('.shipping-price').text();
                        var shipping = parseFloat(priceText.replace('$','').replace(' USD',''));
                        updateSummary(shipping);
                    });
                }
            },
            error: function(xhr, status, error) {
                $('#screenLoader').hide();
                console.error(error);
            }
        });
    }

    fetchShippingOptions();

    $('.numeric-input, #shipFromSelect').on('input change', function() {
        fetchShippingOptions();
    });

    $('#editAddressForm').on('submit', function(e){
        e.preventDefault();
        var formData = $(this).serialize();
        $.post("{{ route('orders.update-address') }}", formData, function(response){
            if(response.success) {
                $('#editAddressModal').modal('hide');
                $('#addressDisplay').html(`
                    <span class="fw-semibold">${response.address.recipient_name}</span>
                    <span>${response.address.ship_address1}</span>
                    ${response.address.ship_address2 ? `<span>${response.address.ship_address2}</span>` : ''}
                    <span>${response.address.ship_city}, ${response.address.ship_state} ${response.address.ship_postal_code}</span>
                    <span>${response.address.ship_country}</span>
                `);
            } else {
                alert('Failed to update address.');
            }
        }, 'json');
    });
$('#buyNowBtn').on('click', function() {
    var orderId = "{{ $order->id }}";
    var selectedRate = $('input[name="shipping"]:checked').attr('id');

    var length = parseFloat($('#length').val() || 0);
    var width  = parseFloat($('#width').val() || 0);
    var height = parseFloat($('#height').val() || 0);
    var pounds = parseFloat($('#pounds').val() || 0);
    var ounces = parseFloat($('#ounces').val() || 0);
    var platform = $('#labelPlatformSelect').val();
    var shipFrom = $('#shipFromSelect').val();
    var weight = pounds + (ounces / 16);
    console.log("Package Values =>", {
        length: length,
        width: width,
        height: height,
        weight: weight,
        pounds: pounds,
        ounces: ounces,
        platform: platform
    });

    if (!selectedRate) {
        Swal.fire({
            icon: 'warning',
            title: 'No Shipping Option Selected',
            text: 'Please select a shipping option first.'
        });
        return;
    }
    if (!length || !width || !height || !weight) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Package Details',
            text: 'Length, width, height, and weight must be greater than 0.'
        });
        return;
    }
    
    if (!pounds && !ounces) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Weight',
            text: 'Please enter weight in pounds or ounces.'
        });
        return;
    }

    $('#screenLoader').show();

    $.ajax({
        url: "{{ route('orders.buy-label') }}",
        type: "POST",
        data: {
            _token: "{{ csrf_token() }}",
            order_id: orderId,
            rate_id: selectedRate,
            platform:platform,
            length:length,
            width:width,
            height:height,
            weight:weight,
            shipFrom:shipFrom
        },
        success: function(response) {
            $('#screenLoader').hide();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Label Purchased',
                    text: 'Your shipping label has been created successfully.'
                }).then(() => {
                    if (response.label_url) {
                        window.open(response.label_url, '_blank'); 
                    }
                      window.location.href = "{{ route('shipped-orders.index') }}";
                });
            } else {
                let displayMsg = response.error?.message || response.message || 'Something went wrong while creating the label.';

                if (displayMsg.includes("Insufficient account balance")) {
                    displayMsg = "Insufficient balance. Kindly refill your wallet.";
                }

                Swal.fire({
                    icon: 'error',
                    title: 'Purchase Failed',
                    text: displayMsg
                });
            }
        },
        error: function(xhr) {
            $('#screenLoader').hide();
            let errMsg = 'Unexpected error occurred. Please try again.';
            try {
                let err = JSON.parse(xhr.responseText);
                errMsg = err.error?.message || err.message || errMsg;
            } catch(e) {}

            if (errMsg.includes("Insufficient account balance")) {
                errMsg = "Insufficient balance. Kindly refill your wallet.";
            }

            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: errMsg
            });
        }
    });
});


});
</script>
@endsection

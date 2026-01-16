@extends('admin.layouts.admin_master')
@section('title', 'Create Manual Order')
@section('content')
<!-- Add jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* Orange button and text styles */
.text-orange { color: #ff6a00; }
.btn-orange { background: #ff6a00; color: #fff; border: none; }
.btn-orange:hover { background: #e65c00; }
/* Order item form styles */
.order-item-row {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    margin-bottom: 1rem;
    padding: 1.25rem;
    position: relative;
    transition: all 0.3s ease;
}
.order-item-row:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.order-item-row .form-control,
.order-item-row .form-select {
    margin-bottom: 0.75rem;
}
.remove-item-btn {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    font-size: 0.875rem;
    line-height: 1;
}
.remove-item-btn:hover {
    background: #c82333;
}
.add-item-btn {
    background: #28a745;
    border-color: #28a745;
}
.add-item-btn:hover {
    background: #218838;
    border-color: #1e7e34;
}
.item-number {
    font-weight: bold;
    color: #3f51b5;
    margin-bottom: 1rem;
    display: inline-block;
    min-width: 30px;
}
.dimension-group {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}
.dimension-group .form-control {
    flex: 1;
    max-width: 80px;
}
.weight-group {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}
.weight-group .form-control {
    flex: 1;
    max-width: 100px;
}
.price-group {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}
.price-group .form-control {
    flex: 1;
}
.total-preview {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 1rem;
    margin-top: 1rem;
}
.form-section {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.section-header {
    background: linear-gradient(135deg, #3f51b5 0%, #5c6bc0 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 6px 6px 0 0;
    margin: -1.5rem -1.5rem 1.5rem -1.5rem;
}
.section-title {
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.required-field::after {
    content: " *";
    color: #dc3545;
}
.form-label {
    font-weight: 500;
    color: #333;
    margin-bottom: 0.5rem;
}
.form-control {
    border-radius: 6px;
    border: 1px solid #ced4da;
    padding: 0.625rem 0.75rem;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}
.form-control:focus {
    border-color: #3f51b5;
    box-shadow: 0 0 0 0.2rem rgba(63, 81, 181, 0.25);
}
.input-group-text {
    background-color: #f8f9fa;
    border: 1px solid #ced4da;
    border-right: none;
    border-radius: 6px 0 0 6px;
}
.btn-primary {
    background: linear-gradient(135deg, #3f51b5 0%, #5c6bc0 100%);
    border: none;
    border-radius: 6px;
    padding: 0.625rem 1.25rem;
    font-weight: 500;
    transition: all 0.2s ease;
}
.btn-primary:hover {
    background: linear-gradient(135deg, #303f9f 0%, #4a5fa5 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(63, 81, 181, 0.3);
}
.btn-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    border: none;
}
.btn-success:hover {
    background: linear-gradient(135deg, #218838 0%, #1ea085 100%);
}
</style>

<div class="container-fluid px-4 py-3">
    <div class="row">
        <div class="col-12">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1 text-dark fw-bold">Create Manual Order</h1>
                    <p class="mb-0 text-muted">Fill in the order details below to create a new manual order</p>
                </div>
                <a href="{{ route('manual-orders.index') }}" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Orders
                </a>
            </div>

            <form id="manualOrderForm" novalidate>
                @csrf
                <input type="hidden" name="order_items" id="orderItemsData">
                
                <!-- Order Information Section -->
                <div class="form-section">
                    <div class="section-header">
                        <h3 class="section-title mb-0">
                            <i class="bi bi-info-circle"></i>
                            Order Information
                        </h3>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Platform/Marketplace</label>
                            <select class="form-select" name="marketplace" id="marketplace" required>
                                <option value="">Select Platform</option>
                                <!-- <option value="amazon">Amazon</option> --> <!-- DISABLED: No longer maintaining Amazon orders shipment -->
                                <option value="ebay">eBay</option>
                                <option value="walmart">Walmart</option>
                                <option value="shopify">Shopify</option>
                                <option value="reverb">Reverb</option>
                                <option value="tiktok">TikTok Shop</option>
                                <option value="temu">Temu</option>
                                <option value="manual">Manual</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Order Number</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-hash"></i>
                                </span>
                                <input type="text" class="form-control" name="order_number" id="orderNumber" 
                                       placeholder="Enter order number" required maxlength="50">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Order Date</label>
                            <input type="datetime-local" class="form-control" name="order_date" id="orderDate" 
                                   value="{{ now()->format('Y-m-d\TH:i') }}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Order Total</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" name="order_total" id="orderTotal" 
                                       step="0.01" min="0" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Order Notes</label>
                            <textarea class="form-control" name="order_notes" id="orderNotes" 
                                      rows="2" placeholder="Additional order notes (optional)"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Shipping Address Section -->
                <div class="form-section">
                    <div class="section-header">
                        <h3 class="section-title mb-0">
                            <i class="bi bi-geo-alt"></i>
                            Shipping Address
                        </h3>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Recipient Name</label>
                            <input type="text" class="form-control" name="recipient_name" id="recipientName" 
                                   placeholder="Full name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Recipient Phone</label>
                            <input type="tel" class="form-control" name="recipient_phone" id="recipientPhone" 
                                   placeholder="(123) 456-7890">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Recipient Email</label>
                            <input type="email" class="form-control" name="recipient_email" id="recipientEmail" 
                                   placeholder="example@email.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company (Optional)</label>
                            <input type="text" class="form-control" name="ship_company" id="shipCompany" 
                                   placeholder="Company name">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label required-field">Address Line 1</label>
                            <input type="text" class="form-control" name="ship_address1" id="shipAddress1" 
                                   placeholder="Street address" required>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" class="form-control" name="ship_address2" id="shipAddress2" 
                                   placeholder="Apartment, suite, unit, etc. (optional)">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label required-field">City</label>
                            <input type="text" class="form-control" name="ship_city" id="shipCity" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label required-field">State</label>
                            <input type="text" class="form-control" name="ship_state" id="shipState" required>
                        </div>
                        <div class="col-md-5 mb-3">
                            <label class="form-label required-field">ZIP/Postal Code</label>
                            <input type="text" class="form-control" name="ship_postal_code" id="shipPostalCode" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Country</label>
                            <select class="form-select" name="ship_country" id="shipCountry" required>
                                <option value="">Select Country</option>
                                <option value="US" selected>United States</option>
                                <option value="CA">Canada</option>
                                <option value="GB">United Kingdom</option>
                                <option value="AU">Australia</option>
                                <option value="DE">Germany</option>
                                <option value="FR">France</option>
                                <option value="MX">Mexico</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Address Notes</label>
                            <textarea class="form-control" name="ship_notes" id="shipNotes" 
                                      rows="2" placeholder="Delivery instructions (optional)"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Order Items Section -->
                <div class="form-section">
                    <div class="section-header">
                        <h3 class="section-title mb-0">
                            <i class="bi bi-box-seam"></i>
                            Order Items
                        </h3>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0 fw-semibold text-muted">Add order items with dimensions and weight</h6>
                        <button type="button" class="btn btn-success add-item-btn" id="addItemBtn">
                            <i class="bi bi-plus-circle me-2"></i>Add Item
                        </button>
                    </div>

                    <div id="itemsContainer">
                        <!-- Dynamic order items will be added here -->
                    </div>

                    <div class="total-preview mt-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="h6 mb-0 fw-bold">Items Total:</span>
                            <span class="h5 mb-0 text-primary fw-bold" id="itemsTotal">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="text-muted">Number of Items:</span>
                            <span class="text-muted" id="itemsCount">0</span>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                    <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                        <i class="bi bi-arrow-left me-2"></i>Cancel
                    </button>
                    <div class="d-flex gap-2">
                        <button type="reset" class="btn btn-outline-secondary">Reset Form</button>
                        <button type="submit" class="btn btn-primary" id="submitOrderBtn">
                            <i class="bi bi-check-circle me-2"></i>Create Order
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    let itemCounter = 0;

    // Clear default 0, 0.0, or 0.00 on focus for numeric inputs
    $(document).on('focus', 'input[type="number"]', function() {
        const $input = $(this);
        const value = $input.val();
        if (value === '0' || value === '0.0' || value === '0.00') {
            $input.val('');
        }
    });

    // Restore 0.00 on blur if empty for numeric inputs
    $(document).on('blur', 'input[type="number"]', function() {
        const $input = $(this);
        if ($input.val() === '') {
            $input.val('0.00');
        }
    });
    
    // Add new order item
    $('#addItemBtn').on('click', function() {
        addOrderItem();
    });
    
    // Add first item automatically
    addOrderItem();
    
    // Calculate items total
    $(document).on('input', '.item-price, .item-quantity', function() {
        calculateItemsTotal();
    });
    
    // Form submission
    $('#manualOrderForm').on('submit', function(e) {
    e.preventDefault();

    // Validate required fields
    if (!validateForm()) {
        return;
    }

    // Collect order items data
    let orderItems = collectOrderItems();
    if (orderItems.length === 0) {
        Swal.fire({
            icon: 'error',
            title: 'Validation Error',
            text: 'Please add at least one order item.',
            confirmButtonText: 'OK'
        });
        return;
    }

    // Set hidden field with items data
    $('#orderItemsData').val(JSON.stringify(orderItems));

    // Show loading
    $('#submitOrderBtn').prop('disabled', true).html('<i class="bi bi-hourglass-split me-2"></i>Creating Order...');

    $.ajax({
        url: '{{ route("manual-orders.store") }}',
        type: 'POST',
        data: $(this).serialize(),
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Order Created!',
                    text: 'Manual order has been created successfully.',
                    confirmButtonText: 'View Order'
                }).then((result) => {
                    window.location.href = response.redirect_url || '{{ route("manual-orders.index") }}';
                });
            } else {
                // Handle validation errors or custom errors
                let errorText = response.message || 'Failed to create order.';
                if (response.errors) {
                    // Flatten all field errors into a single string
                    errorText = Object.values(response.errors).flat().join('\n');
                }

                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: errorText,
                    confirmButtonText: 'OK'
                });
                $('#submitOrderBtn').prop('disabled', false).html('<i class="bi bi-check-circle me-2"></i>Create Order');
            }
        },
        error: function(xhr) {
            let errorMessage = 'An error occurred while creating the order.';
            if (xhr.responseJSON) {
                if (xhr.responseJSON.errors) {
                    errorMessage = Object.values(xhr.responseJSON.errors).flat().join('\n');
                } else if (xhr.responseJSON.message) {
                    errorMessage = xhr.responseJSON.message;
                }
            }
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: errorMessage,
                confirmButtonText: 'OK'
            });
            $('#submitOrderBtn').prop('disabled', false).html('<i class="bi bi-check-circle me-2"></i>Create Order');
        }
    });
});

    function addOrderItem() {
        itemCounter++;
        const itemHtml = `
            <div class="order-item-row" data-item-id="${itemCounter}">
                <button type="button" class="btn remove-item-btn" title="Remove item">
                    <i class="bi bi-x"></i>
                </button>
                
                <div class="item-number">Item ${itemCounter}</div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label required-field">Product Name</label>
                        <input type="text" class="form-control" name="items[${itemCounter}][product_name]" 
                               placeholder="Product description" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label required-field">SKU</label>
                        <input type="text" class="form-control" name="items[${itemCounter}][sku]" 
                               placeholder="Product SKU" required maxlength="100">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label required-field">Quantity</label>
                        <input type="number" class="form-control item-quantity" name="items[${itemCounter}][quantity]" 
                               value="1" min="1" step="1" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Price per Item</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control item-price" name="items[${itemCounter}][price]" 
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Total Price</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="items[${itemCounter}][total_price]" 
                                   step="0.01" min="0" placeholder="0.00" readonly>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Dimensions (inches)</label>
                        <div class="dimension-group">
                            <input type="number" class="form-control" value="8" name="items[${itemCounter}][height]" 
                                   placeholder="H" step="0.1" min="0">
                            <input type="number" class="form-control" value="6" name="items[${itemCounter}][width]" 
                                   placeholder="W" step="0.1" min="0">
                            <input type="number" class="form-control" value="2" name="items[${itemCounter}][length]" 
                                   placeholder="L" step="0.1" min="0">
                        </div>
                        <small class="text-muted">Height × Width × Length</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Weight (lbs)</label>
                        <div class="input-group">
                            <input type="number" class="form-control" value="20" name="items[${itemCounter}][weight]" 
                                   step="0.01" min="0" placeholder="0.00">
                            <span class="input-group-text">lbs</span>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label">Item Notes</label>
                        <textarea class="form-control" name="items[${itemCounter}][notes]" 
                                  rows="1" placeholder="Special handling instructions (optional)"></textarea>
                    </div>
                </div>
            </div>
        `;
        
        $('#itemsContainer').append(itemHtml);
        calculateItemsTotal();
    }
    
    // Remove order item
    $(document).on('click', '.remove-item-btn', function() {
        if ($('.order-item-row').length > 1) {
            $(this).closest('.order-item-row').remove();
            updateItemNumbers();
            calculateItemsTotal();
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'Minimum Items',
                text: 'At least one item is required.',
                confirmButtonText: 'OK'
            });
        }
    });
    
    function updateItemNumbers() {
        $('.order-item-row').each(function(index) {
            $(this).find('.item-number').text(`Item ${index + 1}`);
            // Update name attributes
            const itemId = index + 1;
            $(this).find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                if (name) {
                    const newName = name.replace(/items\[\d+\]/, `items[${itemId}]`);
                    $(this).attr('name', newName);
                }
            });
        });
        itemCounter = $('.order-item-row').length;
    }
    
    function calculateItemsTotal() {
        let total = 0;
        let count = 0;
        
        $('.order-item-row').each(function() {
            const quantity = parseFloat($(this).find('.item-quantity').val()) || 0;
            const price = parseFloat($(this).find('.item-price').val()) || 0;
            const itemTotal = quantity * price;
            
            // Update item total field
            $(this).find('input[name$="[total_price]"]').val(itemTotal.toFixed(2));
            
            total += itemTotal;
            if (quantity > 0) count++;
        });
        
        $('#itemsTotal').text('$' + total.toFixed(2));
        $('#itemsCount').text(count);
        
        // Sync with order total if only one item
        if (count === 1) {
            $('#orderTotal').val(total.toFixed(2));
        }
    }
    
    function collectOrderItems() {
        let items = [];
        $('.order-item-row').each(function() {
            const $row = $(this);
            const item = {
                product_name: $row.find('input[name$="[product_name]"]').val().trim(),
                sku: $row.find('input[name$="[sku]"]').val().trim(),
                quantity: parseInt($row.find('input[name$="[quantity]"]').val()) || 1,
                price: parseFloat($row.find('input[name$="[price]"]').val()) || 0,
                total_price: parseFloat($row.find('input[name$="[total_price]"]').val()) || 0,
                height: parseFloat($row.find('input[name$="[height]"]').val()) || null,
                width: parseFloat($row.find('input[name$="[width]"]').val()) || null,
                length: parseFloat($row.find('input[name$="[length]"]').val()) || null,
                weight: parseFloat($row.find('input[name$="[weight]"]').val()) || null,
                notes: $row.find('textarea[name$="[notes]"]').val().trim()
            };
            
            // Only add valid items
            if (item.product_name && item.sku && item.quantity > 0) {
                items.push(item);
            }
        });
        return items;
    }
    
    function validateForm() {
        let isValid = true;
        
        // Check required fields
        $('[required]').each(function() {
            const $field = $(this);
            const value = $field.val().trim();
            if (!value) {
                $field.addClass('is-invalid');
                isValid = false;
            } else {
                $field.removeClass('is-invalid');
            }
        });
        
        // Check order items
        let hasValidItems = false;
        $('.order-item-row').each(function() {
            const productName = $(this).find('input[name$="[product_name]"]').val().trim();
            const sku = $(this).find('input[name$="[sku]"]').val().trim();
            const quantity = parseInt($(this).find('input[name$="[quantity]"]').val()) || 0;
            
            if (productName && sku && quantity > 0) {
                hasValidItems = true;
            }
        });
        
        if (!hasValidItems) {
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Please add at least one valid order item with product name, SKU, and quantity.',
                confirmButtonText: 'OK'
            });
            isValid = false;
        }
        
        // Check order total
        const orderTotal = parseFloat($('#orderTotal').val()) || 0;
        if (orderTotal <= 0) {
            $('#orderTotal').addClass('is-invalid');
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Order total must be greater than 0.',
                confirmButtonText: 'OK'
            });
            isValid = false;
        } else {
            $('#orderTotal').removeClass('is-invalid');
        }
        
        if (!isValid) {
            Swal.fire({
                icon: 'warning',
                title: 'Please Fix Errors',
                text: 'Please fill in all required fields and correct any validation errors.',
                confirmButtonText: 'OK'
            });
        }
        
        return isValid;
    }
    
    // Auto-calculate item total when quantity or price changes
    $(document).on('input', '.item-price, .item-quantity', function() {
        const $row = $(this).closest('.order-item-row');
        const quantity = parseFloat($row.find('.item-quantity').val()) || 0;
        const price = parseFloat($row.find('.item-price').val()) || 0;
        const total = quantity * price;
        $row.find('input[name$="[total_price]"]').val(total.toFixed(2));
        calculateItemsTotal();
    });
    
    // Sync order total with items total
    $('#itemsTotal').on('click', function() {
        const itemsTotal = parseFloat($(this).text().replace('$', '')) || 0;
        $('#orderTotal').val(itemsTotal.toFixed(2));
    });
});
</script>

<style>
.is-invalid {
    border-color: #dc3545 !important;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
}
</style>
@endsection
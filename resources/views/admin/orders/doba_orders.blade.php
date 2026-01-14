@extends('admin.layouts.admin_master')
@section('title', 'DOBA Orders')
@section('content')
<style>
.btn-primary:disabled {
    background-color: #ccc;
    border-color: #ccc;
    color: #fff;
    cursor: not-allowed;
}
.dt-buttons .btn-primary {
    padding: 0.5rem 1.5rem;
    font-weight: 500;
    margin-left: 8px;
}
.dataTables_wrapper .dataTable thead th {
    background: linear-gradient(to right, #3f51b5, #5c6bc0) !important;
    color: #fff !important;
    font-weight: bold;
}
.badge {
    padding: 0.35em 0.65em;
    font-size: 0.875em;
    font-weight: 600;
}
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
.label-type-tabs {
    border-bottom: 2px solid #dee2e6;
    margin-bottom: 20px;
}
.label-type-tabs .nav-link {
    color: #495057;
    border: none;
    border-bottom: 3px solid transparent;
    padding: 10px 20px;
    font-weight: 500;
}
.label-type-tabs .nav-link.active {
    color: #3f51b5;
    border-bottom-color: #3f51b5;
    background-color: transparent;
}
</style>

<!-- Upload Label Modal -->
<div class="modal fade" id="uploadLabelModal" tabindex="-1" aria-labelledby="uploadLabelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="uploadLabelForm" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadLabelModalLabel">Upload Customer Label</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="order_id" id="uploadOrderId">
                    <div class="mb-3">
                        <label class="form-label">Label File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="label_file" id="labelFile" accept=".pdf,.jpg,.jpeg,.png" required>
                        <small class="form-text text-muted">Accepted formats: PDF, JPG, JPEG, PNG (Max 10MB)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">SKU to Edit</label>
                        <input type="text" class="form-control" name="sku" id="labelSku" placeholder="Enter SKU to edit in label">
                        <small class="form-text text-muted">Leave empty to use order SKU</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload Label</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit SKU Modal -->
<div class="modal fade" id="editSkuModal" tabindex="-1" aria-labelledby="editSkuModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editSkuForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="editSkuModalLabel">Edit SKU in Label</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="order_id" id="editSkuOrderId">
                    <div class="mb-3">
                        <label class="form-label">SKU <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="sku" id="editSkuInput" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update SKU</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="p-3">
    <div class="page-title d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">DOBA Orders</h5>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
                <i class="bi bi-funnel"></i> Filter
            </button>
            <button type="button" class="btn btn-primary" id="moveToPurchaseLabelBtn" disabled>
                Move to Purchase Label
            </button>
        </div>
    </div>

    <!-- Label Type Tabs -->
    <ul class="nav nav-tabs label-type-tabs" id="labelTypeTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab" data-label-type="all">
                All Orders
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="required-tab" data-bs-toggle="tab" data-bs-target="#required" type="button" role="tab" data-label-type="required">
                Label Required
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="provided-tab" data-bs-toggle="tab" data-bs-target="#provided" type="button" role="tab" data-label-type="provided">
                Label Provided
            </button>
        </li>
    </ul>

    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Filters</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
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
            <table id="dobaOrdersTable" class="table table-bordered nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Order Number</th>
                        <th>Order Date</th>
                        <th>Age</th>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th>Recipient</th>
                        <th>Quantity</th>
                        <th>Order Total</th>
                        <th>Label Status</th>
                        <th>Actions</th>
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
let selectedLabelType = 'all';
let selectedOrderIds = [];

$(document).ready(function() {
    let table = $('#dobaOrdersTable').DataTable({
        processing: true,
        serverSide: true,
        scrollX: true,
        ajax: {
            url: '{{ route("doba-orders.data") }}',
            data: function(d) {
                d.label_type = selectedLabelType;
                d.from_date = $('#filterFromDate').val();
                d.to_date = $('#filterToDate').val();
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
                render: function(data, type, row) {
                    return `<input type="checkbox" class="order-checkbox" value="${data}">`;
                },
                orderable: false,
                searchable: false
            },
            {
                data: 'order_number',
                title: 'Order Number'
            },
            {
                data: 'order_date',
                title: 'Order Date'
            },
            {
                data: 'order_age',
                title: 'Age',
                className: 'text-center'
            },
            {
                data: 'sku',
                title: 'SKU'
            },
            {
                data: 'product_name',
                title: 'Product Name'
            },
            {
                data: 'recipient_name',
                title: 'Recipient'
            },
            {
                data: 'quantity',
                title: 'Qty',
                className: 'text-center'
            },
            {
                data: 'order_total',
                title: 'Order Total',
                className: 'text-end',
                render: function(data) {
                    return '$' + data;
                }
            },
            {
                data: 'label_status',
                title: 'Label Status',
                orderable: false,
                searchable: false
            },
            {
                data: 'id',
                title: 'Actions',
                orderable: false,
                searchable: false,
                render: function(data, type, row) {
                    let actions = '';
                    
                    if (row.label_type === 'required') {
                        actions += `<button class="btn btn-sm btn-info upload-label-btn" data-order-id="${data}" title="Upload Customer Label">
                            <i class="fas fa-upload"></i>
                        </button> `;
                        actions += `<button class="btn btn-sm btn-primary move-to-purchase-btn" data-order-id="${data}" title="Move to Purchase Label">
                            <i class="fas fa-shopping-cart"></i>
                        </button>`;
                    } else if (row.label_type === 'provided') {
                        if (row.doba_label_file) {
                            actions += `<button class="btn btn-sm btn-info download-label-btn" data-order-id="${data}" title="Download Label">
                                <i class="fas fa-download"></i>
                            </button> `;
                        }
                        actions += `<button class="btn btn-sm btn-warning edit-sku-btn" data-order-id="${data}" data-sku="${row.doba_label_sku || row.sku}" title="Edit SKU">
                            <i class="fas fa-edit"></i>
                        </button> `;
                        actions += `<button class="btn btn-sm btn-success move-to-purchase-btn" data-order-id="${data}" title="Move to Purchase Label">
                            <i class="fas fa-shopping-cart"></i>
                        </button>`;
                    }
                    
                    return actions || '—';
                }
            }
        ]
    });

    // Label type tab change
    $('.label-type-tabs button').on('click', function() {
        selectedLabelType = $(this).data('label-type');
        table.ajax.reload();
        selectedOrderIds = [];
        updateMoveButtonState();
    });

    // Select all checkbox
    $('#selectAll').on('change', function() {
        $('.order-checkbox').prop('checked', this.checked);
        updateSelectedOrders();
    });

    // Individual checkbox change
    $(document).on('change', '.order-checkbox', function() {
        updateSelectedOrders();
    });

    function updateSelectedOrders() {
        selectedOrderIds = $('.order-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        updateMoveButtonState();
    }

    function updateMoveButtonState() {
        const hasRequired = selectedOrderIds.length > 0 && 
            $('.order-checkbox:checked').closest('tr').find('.move-to-purchase-btn').length > 0;
        $('#moveToPurchaseLabelBtn').prop('disabled', !hasRequired || selectedOrderIds.length === 0);
    }

    // Apply filters
    $('#applyFilters').on('click', function() {
        table.ajax.reload();
        $('#filterModal').modal('hide');
    });

    // Reset filters
    $('#resetFilters').on('click', function() {
        $('#filterFromDate').val('');
        $('#filterToDate').val('');
        table.ajax.reload();
    });

    // Move to purchase label (bulk)
    $('#moveToPurchaseLabelBtn').on('click', function() {
        if (selectedOrderIds.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Orders Selected',
                text: 'Please select at least one order to move.'
            });
            return;
        }

        Swal.fire({
            title: 'Move to Purchase Label?',
            text: `Are you sure you want to move ${selectedOrderIds.length} order(s) to purchase label section?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3f51b5',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Move',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '{{ route("doba-orders.move-to-purchase-label") }}',
                    type: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        order_ids: selectedOrderIds
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: response.message
                            });
                            table.ajax.reload();
                            selectedOrderIds = [];
                            updateMoveButtonState();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message
                            });
                        }
                    },
                    error: function(xhr) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: xhr.responseJSON?.message || 'An error occurred'
                        });
                    }
                });
            }
        });
    });

    // Move to purchase label (single)
    $(document).on('click', '.move-to-purchase-btn', function() {
        const orderId = $(this).data('order-id');
        const orderIds = [orderId];

        Swal.fire({
            title: 'Move to Purchase Label?',
            text: 'Are you sure you want to move this order to purchase label section?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3f51b5',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Move',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '{{ route("doba-orders.move-to-purchase-label") }}',
                    type: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        order_ids: orderIds
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: response.message
                            });
                            table.ajax.reload();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message
                            });
                        }
                    },
                    error: function(xhr) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: xhr.responseJSON?.message || 'An error occurred'
                        });
                    }
                });
            }
        });
    });

    // Upload label
    $(document).on('click', '.upload-label-btn', function() {
        const orderId = $(this).data('order-id');
        $('#uploadOrderId').val(orderId);
        $('#uploadLabelForm')[0].reset();
        $('#uploadLabelModal').modal('show');
    });

    $('#uploadLabelForm').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);

        $.ajax({
            url: '{{ route("doba-orders.upload-label") }}',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: response.message
                    });
                    $('#uploadLabelModal').modal('hide');
                    table.ajax.reload();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message
                    });
                }
            },
            error: function(xhr) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: xhr.responseJSON?.message || 'An error occurred'
                });
            }
        });
    });

    // Edit SKU
    $(document).on('click', '.edit-sku-btn', function() {
        const orderId = $(this).data('order-id');
        const currentSku = $(this).data('sku');
        $('#editSkuOrderId').val(orderId);
        $('#editSkuInput').val(currentSku);
        $('#editSkuModal').modal('show');
    });

    $('#editSkuForm').on('submit', function(e) {
        e.preventDefault();

        $.ajax({
            url: '{{ route("doba-orders.edit-sku") }}',
            type: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                order_id: $('#editSkuOrderId').val(),
                sku: $('#editSkuInput').val()
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: response.message
                    });
                    $('#editSkuModal').modal('hide');
                    table.ajax.reload();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message
                    });
                }
            },
            error: function(xhr) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: xhr.responseJSON?.message || 'An error occurred'
                });
            }
        });
    });

    // Download label
    $(document).on('click', '.download-label-btn', function() {
        const orderId = $(this).data('order-id');
        window.open(`{{ url('admin/doba-orders') }}/${orderId}/download-label`, '_blank');
    });
});
</script>
@endsection

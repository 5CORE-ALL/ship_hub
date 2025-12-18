@extends('admin.layouts.admin_master')
@section('title', 'Bulk Label History')
@section('content')
<div class="p-3">

    <div class="page-title d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Bulk Label History</h5>
        <button type="button" class="btn btn-primary btn-sm" id="syncMissingLabelsBtn">
            <i class="fa fa-sync"></i> Sync Missing Labels
        </button>
    </div>

    <div class="row">
        <div class="col-md-12 col-12">
            <table id="bulkLabelHistoryTable" class="table table-bordered nowrap w-100">
                <thead>
                    <tr>
                        <th>Batch No.</th>
                        <th>Label Count</th>
                        <th>Success Count</th>
                        <th>Failed Count</th>
                        <th>Created By</th>
                        <th>Created At</th>
                        <th>Updated At</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Orders Modal -->
<div class="modal fade" id="ordersModal" tabindex="-1" aria-labelledby="ordersModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Orders in Batch <span id="batchId"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <ul id="ordersList" class="list-group">
          <li class="list-group-item text-center">Loading...</li>
        </ul>
      </div>
    </div>
  </div>
</div>
@endsection

@section('script')
<script>
$(document).ready(function() {
    // Initialize DataTable
    let table = $('#bulkLabelHistoryTable').DataTable({
        processing: true,
        serverSide: true,
        scrollX: true,
        ajax: {
            url: '{{ route("bulk.label.history.data") }}',
            type: 'GET'
        },
        columns: [
            { 
                data: 'id', 
                name: 'id',
                render: function (data) {
                    return data + '-5C'; 
                }
            },
            { 
                data: 'order_count', 
                className: 'text-end',
            },
           {
    data: 'success',
    className: 'text-end',
    render: function(data, type, row) {
        // URL for new page (replace with your actual route)
       let url = '{{ route("bulk.label.history.success", ":batch") }}'.replace(':batch', row.id);

        return `
            <a href="javascript:void(0)" class="view-orders fw-bold me-2" data-id="${row.id}">${data}</a>
            <a href="${url}" target="_blank" class="btn btn-sm ">
                <i class="fa fa-eye text-primary"></i>
            </a>
        `;
    }
},
{
    data: 'failed',
    className: 'text-end',
    render: function(data, type, row) {
        if (parseInt(data) > 0) {
            return `
                <a href="javascript:void(0)" class="view-orders-failed fw-bold me-2" data-id="${row.id}" title="View Failed Orders">
                    ${data}
                </a>
               
            `;
        } else {
            return '0'; 
        }
    }
},
            { data: 'user', name: 'user' },
            { 
                data: 'created_at', 
                render: function(data) {
                    if (!data) return '-';
                    return new Date(data).toLocaleString();
                }
            },
            { 
                data: 'updated_at', 
                render: function(data) {
                    if (!data) return '-';
                    return new Date(data).toLocaleString();
                }
            }
        ],
        order: [[0, 'desc']],
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100]
    });

    // Click event for Label Count
    $('#bulkLabelHistoryTable').on('click', '.view-orders', function() {
        let batchId = $(this).data('id');
        $('#batchId').text(batchId);
        $('#ordersModal').modal('show');

        $('#ordersList').html('<li class="list-group-item text-center">Loading...</li>');

        let url = '{{ route("bulk.label.history.orders", ":batch") }}'.replace(':batch', batchId);

        $.ajax({
            url: url,
            type: 'GET',
            success: function(response) {
                if (response.data && response.data.length > 0) {
                    let listItems = '';
                    response.data.forEach(order => {
                        listItems += `
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><strong>${order.order_number}</strong></span>
                                <span class="badge bg-primary text-uppercase">${order.marketplace || 'N/A'}</span>
                            </li>
                        `;
                    });
                    $('#ordersList').html(listItems);
                } else {
                    $('#ordersList').html('<li class="list-group-item text-center">No orders found</li>');
                }
            },
            error: function() {
                $('#ordersList').html('<li class="list-group-item text-center text-danger">Error loading orders</li>');
            }
        });
    });

      $('#bulkLabelHistoryTable').on('click', '.view-orders-failed', function() {
        let batchId = $(this).data('id');
        $('#batchId').text(batchId);
        $('#ordersModal').modal('show');

        $('#ordersList').html('<li class="list-group-item text-center">Loading...</li>');
 let url = '{{ route("bulk.label.history.orders-failed", ":batch") }}'.replace(':batch', batchId);

    $.ajax({
        url: url,
         type: 'GET',
            success: function(response) {
                if (response.data && response.data.length > 0) {
                    let listItems = '';
                    response.data.forEach(order => {
                        listItems += `
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><strong>${order.order_number}</strong></span>
                                <span class="badge bg-primary text-uppercase">${order.marketplace || 'N/A'}</span>
                            </li>
                        `;
                    });
                    $('#ordersList').html(listItems);
                } else {
                    $('#ordersList').html('<li class="list-group-item text-center">No orders found</li>');
                }
            },
            error: function() {
                $('#ordersList').html('<li class="list-group-item text-center text-danger">Error loading orders</li>');
            }
        });
    });

    // Sync missing labels button
    $('#syncMissingLabelsBtn').on('click', function() {
        const btn = $(this);
        const originalHtml = btn.html();
        
        // Confirm before syncing
        if (!confirm('Are you sure you want to sync missing labels to bulk history? This may take a few moments.')) {
            return;
        }
        
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Syncing...');
        
        $.ajax({
            url: '{{ route("bulk.label.history.sync.missing") }}',
            type: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                if (response.success) {
                    const message = response.message + 
                        '\nBatches Created: ' + response.data.synced_batches + 
                        '\nTotal Orders Synced: ' + response.data.total_orders +
                        '\nMissing Shipments: ' + (response.data.missing_shipments || 0);
                    
                    toastr.success(message, 'Sync Completed', {
                        timeOut: 5000,
                        extendedTimeOut: 2000
                    });
                    
                    // Reload the table to show new entries
                    table.ajax.reload(null, false);
                } else {
                    toastr.error(response.message || 'Failed to sync missing labels', 'Sync Failed');
                }
            },
            error: function(xhr) {
                let errorMsg = 'Failed to sync missing labels';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                toastr.error(errorMsg, 'Sync Failed');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalHtml);
            }
        });
    });
});
</script>
@endsection

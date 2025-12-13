{{-- resources/views/admin/orders/cancellation-report.blade.php --}}
{{-- Updated with Recreated Label Status column and filter --}}

@extends('admin.layouts.admin_master')
@section('title', 'Cancellation Report')
@section('content')
<div class="p-3">
    <div id="loader" class="loader">
        <div class="loader-content">
            <div class="spinner"></div>
            <span id="loaderText">Loading...</span>
        </div>
    </div>

    <div class="page-title d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Cancellation Report</h5>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rightModal">
                <i class="bi bi-filter"></i> Filters
            </button>
         <!--    <button type="button" class="btn btn-secondary" id="exportReportBtn">
                <i class="bi bi-download"></i> Export CSV
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
                    <div class="mb-3">
                        <label class="form-label">Platform</label>
                        <select class="form-select" id="filterMarketplace">
                            <option value="">-- Select Marketplace --</option>
                            @foreach($marketplaces as $channel)
                                <option value="{{ strtolower($channel) }}">{{ ucfirst($channel) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Recreated Label</label>
                        <select class="form-select" id="filterRecreatedLabel">
                            <option value="">-- All --</option>
                            <option value="1">Yes</option>
                            <option value="0">No</option>
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
            <table id="cancellationTable" class="table table-bordered nowrap">
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Order ID</th>
                        <th>Order Date</th>
                        <th>Cancellation Date</th>
                        <th>Cancellation Reason</th>
                        <th>Created By</th>
                        <th>Cancelled By</th>
                        <th>Recreated Label</th>
                        <th>Recipient</th>
                        <th>Quantity</th>
                        <th>Order Total</th>
                        <th>Carrier</th>
                        <th>Tracking ID</th>
                        <th>Shipping Cost</th>
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
    let table = $('#cancellationTable').DataTable({
        processing: true,
        serverSide: true,
        scrollX: true,
        // scrollY: "400px",
        ajax: {
            url: '{{ route("orders.cancellation.data") }}',
            data: function(d) {
                d.marketplace = $('#filterMarketplace').val();
                d.recreated_label = $('#filterRecreatedLabel').val();
                d.from_date = $('#filterFromDate').val();
                d.to_date = $('#filterToDate').val();
            }
        },
        columns: [
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
                render: function(data, type, row) {
                    return fmtDate(data);
                }
            },
            {
                data: 'updated_at',
                render: function(data, type, row) {
                    return fmtDate(data);
                }
            },
            {
                data: 'cancelled_reason',
                render: function(data, type, row) {
                    return data || '-';
                }
            },
            {
                data: 'created_by_name',
                render: function(data, type, row) {
                    return data || '-';
                }
            },
            {
                data: 'cancelled_by_name',
                render: function(data, type, row) {
                    return data || '-';
                }
            },
            {
			    data: 'recreated_label',
			    title: 'Recreated Label',
			    render: function(data, type, row) {
			        if (data === 1) {
			            return '<span class="badge bg-success">Yes</span>';
			        } else if (data === 0) {
			            return '<span class="badge bg-danger">No</span>';
			        } else {
			            return '-';
			        }
			    }
			},
            { data: 'recipient_name' },
            { data: 'quantity', className: 'text-end' },
            {
                data: 'order_total',
                render: $.fn.dataTable.render.number(',', '.', 2, '$'),
                className: 'text-end'
            },
            {
                data: 'carrier',
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
            {
                data: 'shipping_cost',
                render: $.fn.dataTable.render.number(',', '.', 2, '$'),
                className: 'text-end'
            }
        ],
        order: [[3, 'desc']],
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100]
    });

    $('#applyFilters').on('click', function() {
        $('#rightModal').modal('hide');
        table.ajax.reload();
    });

    $('#resetFilters').on('click', function() {
        $('#filterMarketplace').val('');
        $('#filterRecreatedLabel').val('');
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
});
</script>
@endsection
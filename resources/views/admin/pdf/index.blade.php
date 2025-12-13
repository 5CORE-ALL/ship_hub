@extends('admin.layouts.admin_master')
@section('title', 'Shipping Labels Summary')
@section('content')
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> {{-- SweetAlert2 CDN --}}
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"> {{-- Font Awesome for icons --}}
<style>
    /* Enhanced styling for beauty */
    body { background-color: #f8f9fa; }
    .card { box-shadow: 0 4px 20px rgba(0,0,0,0.1); border: none; border-radius: 15px; overflow: hidden; }
    .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    .card-title { font-weight: 600; text-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .summary-card { transition: transform 0.3s ease, box-shadow 0.3s ease; border-radius: 12px; }
    .summary-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
    .text-primary { color: #4f46e5 !important; }
    .text-info { color: #0ea5e9 !important; }
    .btn { border-radius: 25px; font-weight: 500; transition: all 0.3s ease; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
    .btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); border: none; }
    .btn-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; }
    .btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); border: none; }
    .btn-outline-primary, .btn-outline-info { border-radius: 20px; }
    .table { border-radius: 10px; overflow: hidden; }
    .table thead th { background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); font-weight: 600; border: none; }
    .table tbody tr { transition: background-color 0.3s ease; }
    .table tbody tr:hover { background-color: #f8fafc; }
    .list-group-item { border: none; border-radius: 10px; margin-bottom: 8px; background: #f8fafc; transition: all 0.3s ease; }
    .list-group-item:hover { background: #e0e7ff; transform: translateX(5px); }
    .form-check-input:checked { background-color: #3b82f6; border-color: #3b82f6; }
    .date-group { max-width: 340px; }
    .date-group .form-control[type="date"] { min-width: 180px; border-radius: 20px; }
    @media (max-width: 576px) { .date-group { max-width: 100%; } }
    .swal2-popup { border-radius: 15px !important; }
</style>
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 p-4">
                    <h4 class="card-title mb-0">
                        <i class="fas fa-tags me-2"></i>Shipping Labels Summary
                    </h4>
                    {{-- Date Filter + Manual Upload --}}
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        {{-- Date Filter --}}
                        <form method="GET" action="{{ route('pdfs.index') }}" class="d-inline-block mt-0">
                            <div class="input-group flex-nowrap date-group">
                                <span class="input-group-text bg-transparent border-0 text-white">
                                    <i class="fas fa-calendar-alt"></i>
                                </span>
                                <input
                                    type="date"
                                    class="form-control border-start-0"
                                    name="filter_date"
                                    id="filter_date"
                                    value="{{ request('filter_date', now()->format('Y-m-d')) }}"
                                    onchange="this.form.submit();"
                                >
                            </div>
                        </form>
                        {{-- Manual Upload Button --}}
                        <a href="{{ route('pdfs.create') }}" class="btn btn-success">
                            <i class="fas fa-upload me-1"></i>+ Manual Upload
                        </a>
                    </div>
                </div>
                <div class="card-body p-4">
                    {{-- Summary Counts (for selected/current date) --}}
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <div class="summary-card d-flex align-items-center bg-white p-4 rounded shadow-sm border-start border-primary border-4">
                                <div class="flex-shrink-0 me-3">
                                    <i class="fas fa-truck fa-2x text-primary"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 text-muted">ShipHub Labels</h6>
                                    <h3 class="text-primary mb-1">{{ request('filter_date', now()->format('Y-m-d')) }}</h3>
                                    <h2 class="mb-0">{{ $shipHubCount ?? 45 }} <span class="badge bg-primary fs-6">Labels</span></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="summary-card d-flex align-items-center bg-white p-4 rounded shadow-sm border-start border-info border-4">
                                <div class="flex-shrink-0 me-3">
                                    <i class="fas fa-file-upload fa-2x text-info"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 text-muted">Manual Uploads</h6>
                                    <h3 class="text-info mb-1">{{ request('filter_date', now()->format('Y-m-d')) }}</h3>
                                    <h2 class="mb-0">{{ $manualCount ?? 12 }} <span class="badge bg-info fs-6">Labels</span></h2>
                                </div>
                            </div>
                        </div>
                    </div>
                    {{-- Manual PDFs List --}}
                    @if($pdfs->count() > 0)
                    <div class="row mb-4">
                        <div class="col-12">
                            <h5 class="mb-3">
                                <i class="fas fa-list me-2 text-info"></i>Manual Uploads List
                                <span class="badge bg-secondary ms-2">{{ request('filter_date', now()->format('Y-m-d')) }}</span>
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th><i class="fas fa-file-pdf me-1"></i>PDF Name</th>
                                            <th><i class="fas fa-clock me-1"></i>Upload Date</th>
                                            <th><i class="fas fa-cogs me-1"></i>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($pdfs as $pdf)
                                        <tr>
                                            <td>
                                                <i class="fas fa-file-pdf text-danger me-2"></i>
                                                {{ $pdf->original_name ?? $pdf->file_name ?? 'Unnamed PDF' }}
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark px-2 py-1">
                                                    {{ optional($pdf->upload_date)->format('Y-m-d H:i:s') ?? 'N/A' }}
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    {{-- Priority: label_url (external URL) --}}
                                                    @if($pdf->label_url ?? false)
                                                       <a href="{{ Storage::url($pdf->label_url) }}" target="_blank" class="btn btn-outline-primary btn-sm me-1">
    <i class="fas fa-external-link-alt"></i> View Label
</a>
                                                    {{-- Fallback: file_path (local file, via secure route) --}}
                                                    @elseif($pdf->file_path ?? false)
                                                        <a href="{{ route('pdfs.view', $pdf->id) }}" target="_blank" class="btn btn-outline-info btn-sm me-1">
                                                            <i class="fas fa-eye"></i> View File
                                                        </a>
                                                    @else
                                                        <span class="text-muted">No file available</span>
                                                    @endif
                                                    {{-- Delete Form --}}
                                                    <form id="delete-form-{{ $pdf->id }}" action="{{ route('pdfs.destroy', $pdf->id) }}" method="POST" style="display: inline;">
                                                        @csrf
                                                        @method('DELETE')
                                                    </form>
                                                    <button type="button" class="btn btn-danger btn-sm delete-pdf" data-pdf-id="{{ $pdf->id }}">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-center mt-4">
                                {{ $pdfs->appends(request()->query())->links() }}
                            </div>
                        </div>
                    </div>
                    @else
                    <div class="row mb-4">
                        <div class="col-12 text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No manual PDFs found for the selected date.</p>
                        </div>
                    </div>
                    @endif
                    {{-- Type Selection Checkboxes --}}
                    <div class="mb-4">
                        <h5 class="mb-3">
                            <i class="fas fa-download me-2 text-primary"></i>Select Types to Download
                        </h5>
                        <div class="list-group">
                            <div class="list-group-item d-flex align-items-center p-3">
                                <div class="form-check me-3">
                                    <input class="form-check-input type-checkbox" type="checkbox" value="shiphub" id="shiphub-type">
                                    <label class="form-check-label fw-semibold" for="shiphub-type">
                                        <i class="fas fa-truck text-primary me-2"></i>ShipHub
                                        <span class="badge bg-primary ms-2">{{ $shipHubCount ?? 45 }} labels</span>
                                    </label>
                                </div>
                            </div>
                            <div class="list-group-item d-flex align-items-center p-3">
                                <div class="form-check me-3">
                                    <input class="form-check-input type-checkbox" type="checkbox" value="manual" id="manual-type">
                                    <label class="form-check-label fw-semibold" for="manual-type">
                                        <i class="fas fa-file-upload text-info me-2"></i>Manual Upload
                                        <span class="badge bg-info ms-2">{{ $manualCount ?? 12 }} labels</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    {{-- Download Selected Button --}}
                    <div class="d-flex justify-content-end mb-3">
                        <button type="button" id="download-selected" class="btn btn-primary px-4" disabled>
                            <i class="fas fa-cloud-download-alt me-2"></i>Download Selected
                        </button>
                    </div>
                    {{-- Bulk Print Button (New Addition for Printing with Date) --}}
                    <div class="d-flex justify-content-end">
                        <button type="button" id="bulkPrintBtn" class="btn btn-success px-4">
                            <i class="fas fa-print me-2"></i>Bulk Print Selected (Date: {{ request('filter_date', now()->format('Y-m-d')) }})
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
$(function () {
    // Enable/disable download button based on selection
    $('.type-checkbox').on('change', function () {
        const checkedCount = $('.type-checkbox:checked').length;
        const btn = $('#download-selected');
        btn.prop('disabled', checkedCount === 0);
        btn.find('i').removeClass('fa-cloud-download-alt fa-spinner fa-spin').addClass(checkedCount === 0 ? 'fa-cloud-download-alt' : 'fa-cloud-download-alt');
    });
    // Build download URL with selected types and date
    $('#download-selected').on('click', function () {
        const checkedTypes = $('.type-checkbox:checked').map(function () {
            return $(this).val();
        }).get();
        if (!checkedTypes.length) return;
        const btn = $(this);
        btn.html('<i class="fas fa-spinner fa-spin me-2"></i>Downloading...').prop('disabled', true);
        const baseUrl = @json(route('pdfs.download'));
        const filterDate = @json(request('filter_date', now()->format('Y-m-d')));
        const query = new URLSearchParams({ types: checkedTypes.join(','), filter_date: filterDate }).toString();
        window.location.href = `${baseUrl}?${query}`;
    });
    // SweetAlert for Delete Confirmation (Event Delegation)
    $(document).on('click', '.delete-pdf', function (e) {
        e.preventDefault();
        const btn = $(this);
        const formId = '#delete-form-' + btn.data('pdf-id');
        const form = $(formId);
        Swal.fire({
            title: 'Are you sure?',
            text: 'This PDF will be permanently deleted!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: '<i class="fas fa-trash"></i> Yes, delete it!',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel',
            buttonsStyling: false,
            customClass: {
                confirmButton: 'btn btn-danger me-2',
                cancelButton: 'btn btn-secondary'
            },
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: 'Deleting...',
                    text: 'Please wait while we remove the PDF.',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => Swal.showLoading()
                });
                // Submit form
                form.submit();
            }
        });
    });

    // Modified printLabels function to pass date (and types) instead of orderIds
    function printLabels(filterDate, checkedTypes, $button) {
        if (!filterDate) {
            Swal.fire({
                icon: 'warning',
                title: 'No Date Selected',
                text: 'Please select a date to print labels.',
                confirmButtonText: 'OK'
            });
            return;
        }
        if (!checkedTypes || checkedTypes.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Types Selected',
                text: 'Please select at least one type to print.',
                confirmButtonText: 'OK'
            });
            return;
        }
        $('#loaderText').text('Generating Labels...');
        $('#loader').show();
        $('body').addClass('loader-active');
        $button.prop('disabled', true);
        $.ajax({
            url: '{{ route("orders.print.labelsv1", "h") }}',
            type: 'POST',
            data: {
                filter_date: filterDate,
                types: checkedTypes.join(','),
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
                    // Swal.fire({
                    // icon: 'success',
                    // title: 'Success',
                    // text: response.message || 'Labels generated and printing initiated successfully!',
                    // confirmButtonText: 'OK'
                    // });
                    if (typeof table !== 'undefined') {
                        table.ajax.reload();
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to generate labels.',
                        confirmButtonText: 'OK'
                    });
                }
            },
            error: function(xhr) {
                $('#loader').hide();
                $('body').removeClass('loader-active');
                $button.prop('disabled', false);
                let msg = 'An unexpected error occurred while generating labels.';
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

    // Bulk Print Button Handler (Modified to pass date and selected types)
    $('#bulkPrintBtn').on('click', function(e) {
        e.preventDefault();
        let $button = $(this);
        const checkedTypes = $('.type-checkbox:checked').map(function () {
            return $(this).val();
        }).get();
        const filterDate = @json(request('filter_date', now()->format('Y-m-d')));
        if (checkedTypes.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Types Selected',
                text: 'Please select at least one type (ShipHub or Manual Upload) to bulk print.',
                confirmButtonText: 'OK'
            });
            return;
        }
        printLabels(filterDate, checkedTypes, $button);
    });
});
</script>
@endsection
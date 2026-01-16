@extends('admin.layouts.admin_master')
@section('title', 'Awaiting Shipment')
@section('content')
<style>
/* Count badges styling - larger and more noticeable */
.page-title .badge {
    font-size: 1.1rem;
    padding: 0.5rem 0.75rem;
    font-weight: 600;
}

/* Hide only the original horizontal header row inside the main table */
#shipmentTable thead {
    display: none !important;
}

/* Ensure table layout is fixed for proper column alignment */
.dataTables_scrollHead .dataTable,
.dataTables_scrollBody .dataTable {
    width: 100% !important;
}

/* Ensure header and body columns have matching widths */
.dataTables_scrollHead .dataTable thead th,
.dataTables_scrollBody .dataTable tbody td {
    box-sizing: border-box !important;
    padding-left: 8px !important;
    padding-right: 8px !important;
}

/* Ensure scroll wrapper maintains proper width */
.dataTables_scrollHead,
.dataTables_scrollBody {
    overflow-x: auto !important;
}

/* Show and style the scroll header (created by DataTables scrollX) - vertical and rotated 180 degrees */
.dataTables_scrollHead {
    display: block !important;
    overflow: hidden !important;
}

.dataTables_scrollHead .dataTable {
    display: block !important;
}

.dataTables_scrollHead .dataTable thead {
    display: table-header-group !important;
}

.dataTables_scrollHead .dataTable thead tr {
    display: table-row !important;
}

.dataTables_scrollHead .dataTable thead th {
    display: table-cell !important;
    writing-mode: vertical-lr;
    text-orientation: mixed;
    transform: rotate(180deg);
    white-space: nowrap;
    padding: 10px 5px !important;
    height: 150px;
    width: auto !important;
    min-width: 40px;
    max-width: none !important;
    background: linear-gradient(to right, #3f51b5, #5c6bc0) !important;
    color: #fff !important;
    font-weight: bold;
    vertical-align: bottom;
    text-align: center !important;
}

/* Vertical headers for fixed columns - rotated 180 degrees */
.DTFC_LeftHeadWrapper th,
.DTFC_RightHeadWrapper th {
    writing-mode: vertical-lr;
    text-orientation: mixed;
    transform: rotate(180deg);
    white-space: nowrap;
    padding: 10px 5px !important;
    height: 150px;
    min-width: 40px;
}

/* Apply same gradient to ALL fixed left header columns */
.DTFC_LeftHeadWrapper th {
    background: linear-gradient(to right, #3f51b5, #5c6bc0) !important;
    color: #fff !important;
    font-weight: bold;
}
.btn-primary:disabled {
    background-color: #ccc;
    border-color: #ccc;
    color: #fff;
    cursor: not-allowed;
}
/* Style for DataTables buttons */
.dt-buttons .btn-primary {
    padding: 0.5rem 1.5rem;
    font-weight: 500;
    margin-left: 8px;
}
/* Apply gradient to ALL fixed right header columns */
.DTFC_RightHeadWrapper th {
    background: linear-gradient(to right, #009688, #26a69a) !important;
    color: #fff !important;
    font-weight: bold;
}
/* Style for editable cells */
.editable-cell {
    cursor: pointer;
    transition: background-color 0.2s;
}
.editable-cell:hover {
    background-color: #f0f0f0;
}
.editing-cell {
    background-color: #fff;
    padding: 0;
}
.editing-cell input {
    width: 100%;
    border: none;
    padding: 5px;
    outline: none;
    box-sizing: border-box;
}
/* Orange button and text styles */
.text-orange { color: #ff6a00; }
.btn-orange { background: #ff6a00; color: #fff; border: none; }
.btn-orange:hover { background: #e65c00; }
/* Weight filter button active state */
.weight-filter-btn.active {
    background-color: #3f51b5;
    color: #fff;
    border-color: #3f51b5;
}
/* Hide theme customizer */
.switcher-body {
    display: none !important;
}

/* Copy order number button styles */
.copy-order-number-btn {
    color: #0d6efd !important;
    background: transparent !important;
    border: none !important;
    padding: 2px 6px !important;
    min-width: 28px !important;
    height: 24px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    font-size: 14px !important;
    opacity: 1 !important;
    visibility: visible !important;
}

.copy-order-number-btn:hover {
    background: #0d6efd !important;
    color: #fff !important;
    transform: scale(1.1);
}

.copy-order-number-btn:active {
    transform: scale(0.95);
}

.copy-order-number-btn i {
    font-size: 12px !important;
    line-height: 1 !important;
    display: block !important;
    width: 100% !important;
    height: 100% !important;
    opacity: 1 !important;
    visibility: visible !important;
}

/* Verify button styles */
.verify-btn {
    padding: 2px 6px;
    font-size: 0.8rem;
    background-color: #28a745;
    color: #fff;
    border: none;
}
.verify-btn:hover {
    background-color: #218838;
}
.verify-btn:disabled {
    background-color: #ccc;
    cursor: not-allowed;
}
/* Modal styling for professionalism */
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
/* Bulk dimension input styles */
.bulk-dimension-inputs {
    display: none;
    gap: 8px;
    align-items: center;
}
.bulk-dimension-inputs.active {
    display: flex;
}
.bulk-dimension-inputs input {
    width: 100px;
}
/* Carrier grouping styles */
.carrier-group-header {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    padding: 1rem;
    font-weight: bold;
    color: #495057;
    margin-bottom: 0;
}
.carrier-group-item {
    border-left: 3px solid #dee2e6;
}
.carrier-group-item:hover {
    background-color: #f8f9fa;
}
</style>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
<!-- Carrier List Modal -->
<div class="modal fade" id="changeCarrierModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Change Carrier</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="carrierList" class="list-group list-group-flush overflow-auto" style="max-height: 400px;">
          <!-- Carriers will be loaded here -->
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>
<!-- Order Details Modal -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header bg-primary text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold">Order Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="orderDetailsContent">
                <!-- Dynamic content will be appended here -->
            </div>
            <div class="modal-footer border-0 justify-content-center">
                <button class="btn btn-primary btn-lg shadow-sm" data-bs-dismiss="modal">OK</button>
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
            <input type="hidden" name="order_id" id="editOrderId">
            <input type="hidden" name="is_address_verified" id="editIsAddressVerified">
            <div class="mb-2">
                <label class="form-label">Recipient Name</label>
                <input type="text" class="form-control" name="recipient_name" id="editRecipientName" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Address 1</label>
                <input type="text" class="form-control" name="ship_address1" id="editShipAddress1" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Address 2</label>
                <input type="text" class="form-control" name="ship_address2" id="editShipAddress2">
            </div>
            <div class="mb-2">
                <label class="form-label">City</label>
                <input type="text" class="form-control" name="ship_city" id="editShipCity" required>
            </div>
            <div class="mb-2">
                <label class="form-label">State</label>
                <input type="text" class="form-control" name="ship_state" id="editShipState" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Postal Code</label>
                <input type="text" class="form-control" name="ship_postal_code" id="editShipPostalCode" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Country</label>
                <input type="text" class="form-control" name="ship_country" id="editShipCountry" required>
            </div>
            <div class="mb-3" id="verifyButtonContainer">
                <button type="button" class="btn verify-btn" id="verifyAddressBtn">Verify Address</button>
                <small class="text-muted">Click to verify the address using our validation service.</small>
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
<!-- Edit SKU and Quantity Modal -->
<div class="modal fade" id="editItemsModal" tabindex="-1" aria-labelledby="editItemsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="editItemsForm">
        <div class="modal-header">
          <h5 class="modal-title" id="editItemsModalLabel">Edit Order Items</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="order_id" id="editItemsOrderId">
          <div id="itemsList">
            <!-- Dynamic content will be appended here -->
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<div class="modal fade" id="recipientInfoModal" tabindex="-1" aria-labelledby="recipientInfoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="recipientInfoModalLabel">Action Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 2rem;"></i>
                    <p class="mt-3 mb-0" style="font-size: 1.1rem; color: #444;">
                        Please ensure the recipient name or address information is specified before proceeding.
                        Click on the recipient link to edit and add the recipient name. The system will automatically fetch recipient data from eDesk when available.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Understood</button>
            </div>
        </div>
    </div>
</div>
<!-- Weight Info Modal -->
<div class="modal fade" id="weightInfoModal" tabindex="-1" aria-labelledby="weightInfoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="weightInfoModalLabel">Action Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 2rem;"></i>
                    <p class="mt-3 mb-0" style="font-size: 1.1rem; color: #444;">
                        Please ensure the package weight is specified before proceeding.
                        Update the weight in the "Weight" column to enable shipping options.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Understood</button>
            </div>
        </div>
    </div>
</div>
<div class="p-3">
    <div id="loader" class="loader">
        <div class="loader-content">
            <div class="spinner"></div>
            <span id="loaderText">Generating Label...</span>
        </div>
    </div>
  
    <!-- Page Title Row -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <h5 class="mb-0">Awaiting Shipment</h5>
            <span class="badge bg-primary" style="font-size: 0.875rem; padding: 0.35rem 0.65rem;">{{ $pendingCount ?? 0 }}</span>
            <span class="badge {{ ($overdueCount ?? 0) > 0 ? 'bg-danger' : 'bg-success' }}" style="font-size: 0.875rem; padding: 0.35rem 0.65rem;">{{ $overdueCount ?? 0 }}</span>
            <i class="bi bi-info-circle text-primary" style="cursor: pointer; font-size: 1.1rem;" data-bs-toggle="modal" data-bs-target="#overdueHistoryModal" title="View Overdue Count History"></i>
        </div>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#rightModal">
            <i class="bi bi-funnel"></i> Filter
        </button>
    </div>

    <!-- Filter and Toggle Buttons Row -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <!-- Weight Filter Buttons -->
        <div class="btn-group btn-group-sm" role="group" aria-label="Weight Filter">
            <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="all">All Weights</button>
            <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="0.25">0.25lb</button>
            <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="0.5">0.5lb</button>
            <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="0.75">0.75lb</button>
            <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="1-6">1lb–5lb</button>
            <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="6-20">6lb–20lb</button>
            <button type="button" class="btn btn-outline-primary weight-filter-btn" data-weight-range="20+">>20lb</button>
        </div>
        
        <!-- CBIN Filter Button -->
        <button type="button" class="btn btn-outline-danger btn-sm cbin-filter-btn" id="cbinFilterBtn" title="Show only rows with red CBIN (DECL) values">
            <i class="bi bi-funnel-fill"></i> Red CBIN (DECL) <span id="redCbinCount" class="badge bg-danger" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">0</span>
        </button>
        
        <!-- Dimension Column Toggle Buttons -->
        <button type="button" class="btn btn-outline-secondary btn-sm dimension-toggle-btn" id="toggleDDimensions" data-dimension-set="d" title="Toggle L (DECL), W (DECL), H (DECL), WT (DECL) columns">
            <i class="bi bi-eye"></i> Show DECL Dimensions
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm dimension-toggle-btn" id="toggleRegularDimensions" data-dimension-set="regular" title="Toggle L, W, H, WT columns">
            <i class="bi bi-eye"></i> Show DIM
        </button>
    </div>

    <!-- Bulk Actions Row -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        @can('buy_shipping')
            <button type="button" class="btn btn-primary btn-sm bulk-buy-shipping-btn">
                Buy Bulk Shipping
            </button>
        @endcan
        <button type="button" class="btn btn-primary btn-sm bulk-mark-ship-btn">
            Bulk Mark as Ship
        </button>
        
        <!-- Bulk Dimension Inputs -->
        <div class="bulk-dimension-inputs d-flex align-items-center gap-2" id="bulkDimensionInputs">
            <input type="number" class="form-control form-control-sm" id="bulkHeight" placeholder="Height" min="0" step="0.01" style="width: 100px;">
            <input type="number" class="form-control form-control-sm" id="bulkLength" placeholder="Length" min="0" step="0.01" style="width: 100px;">
            <input type="number" class="form-control form-control-sm" id="bulkWidth" placeholder="Width" min="0" step="0.01" style="width: 100px;">
            <input type="number" class="form-control form-control-sm" id="bulkWeight" placeholder="Weight" min="0" step="0.01" style="width: 100px;">
            <button type="button" class="btn btn-primary btn-sm bulk-update-dimensions-btn" disabled>Update Dimensions Bulk</button>
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
                    <!-- <div class="mb-3">
                        <label class="form-label">Platform</label>
                        <select class="form-select" id="filterMarketplace">
                            <option value="">-- Select Platform --</option>
                            @foreach($marketplaces as $channel)
                                <option value="{{ strtolower($channel) }}">{{ ucfirst($channel) }}</option>
                            @endforeach
                        </select>
                    </div> -->
                    <div class="mb-3">
                        <label class="form-label">Platform</label>
                       <select class="form-select" id="filterMarketplace" name="filterMarketplace[]" multiple>
                            @php
                                $uniqueMarketplaces = [];
                                $seen = [];
                                foreach($marketplaces as $channel) {
                                    $lower = strtolower($channel);
                                    if (!in_array($lower, $seen)) {
                                        $seen[] = $lower;
                                        $uniqueMarketplaces[] = $channel;
                                    }
                                }
                            @endphp
                            @foreach($uniqueMarketplaces as $channel)
                                <option value="{{ strtolower($channel) }}">{{ ucfirst($channel) }}</option>
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
        <div class="col-md-12 col-12" id="tableCol">
            <table id="shipmentTable" class="shipment-table table table-bordered nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th>Marketplace</th>
                        <th>Order Number</th>
                        <th>Order Age</th>
                        <th>Order Date</th>
                        <th>SKU</th>
                        <th><input type="checkbox" id="selectAllD"></th>
                        <th>Best Rate (DECL)</th>
                        <th><input type="checkbox" id="selectAllO"></th>
                        <th>Best Rate (DIM)</th>
                        <th>Recipient</th>
                        <th>L (DECL)</th>
                        <th>W (DECL)</th>
                        <th>H (DECL)</th>
                        <th>WT (DECL)</th>
                        <th>WT S</th>
                        <th>CBIN (DECL)</th>
                        <th>H</th>
                        <th>W</th>
                        <th>L</th>
                        <th>WT</th>
                        <th>WT ACT</th>
                        <th>WT ACT S</th>
                        <th>INV</th>
                        <th>Qty</th>
                        <th>Order Total</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
    <!-- Edit Dimension Modal -->
    <div class="modal fade" id="editDimensionModal" tabindex="-1" aria-labelledby="editDimensionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editDimensionModalLabel">Edit Dimension</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editDimensionForm">
                        <input type="hidden" id="editDimensionOrderId" name="order_id">
                        <input type="hidden" id="editDimensionField" name="field">
                        <div class="mb-3">
                            <label for="editDimensionValue" class="form-label" id="editDimensionLabel">Value</label>
                            <input type="number" 
                                   class="form-control" 
                                   id="editDimensionValue" 
                                   name="value" 
                                   min="0" 
                                   step="0.01" 
                                   placeholder="Enter value">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveDimensionBtn">Save</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Overdue History Modal -->
    <div class="modal fade" id="overdueHistoryModal" tabindex="-1" aria-labelledby="overdueHistoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="overdueHistoryModalLabel">Overdue Count History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="historyDays" class="form-label">Select Period:</label>
                        <select class="form-select" id="historyDays">
                            <option value="7">Last 7 days</option>
                            <option value="14">Last 14 days</option>
                            <option value="30" selected>Last 30 days</option>
                            <option value="60">Last 60 days</option>
                            <option value="90">Last 90 days</option>
                        </select>
                    </div>
                    <canvas id="overdueHistoryChart" height="100"></canvas>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('script')
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
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
    const columnVisibilitySettings = @json($columns);
    let selectedWeightRanges = ['all'];
    let cbinFilterActive = false;
    
    // Function to check if CBIN should be red - defined before table initialization
    function isCbinRed(row) {
        if (!row) return false;
        
        const length_d = parseFloat(row.length_d) || 0;
        const width_d = parseFloat(row.width_d) || 0;
        const height_d = parseFloat(row.height_d) || 0;
        const weight_d = parseFloat(row.weight_d) || 0;
        const cbin = length_d * width_d * height_d;
        
        if (cbin === 0 || isNaN(cbin)) return false;
        
        // Determine if CBIN should be red based on WT (DECL) ranges
        let isOverLimit = false;
        
        if (weight_d <= 5) {
            isOverLimit = cbin > 172;
        } else if (weight_d > 5 && weight_d <= 8) {
            isOverLimit = cbin > 345;
        } else if (weight_d > 8 && weight_d <= 10) {
            isOverLimit = cbin > 518;
        } else if (weight_d > 10 && weight_d <= 15) {
            isOverLimit = cbin > 691;
        } else if (weight_d > 15 && weight_d <= 20) {
            isOverLimit = cbin > 864;
        } else if (weight_d > 20.01) {
            isOverLimit = true; // Always red if WT (DECL) > 20.01
        }
        
        return isOverLimit;
    }
    
    $(document).ready(function() {
        const columnVisibilityMap = {};
        columnVisibilitySettings.forEach(col => {
            columnVisibilityMap[col.column_name] = col.is_visible === 1;
        });
        $(document).on('click', '.buy-shipping-btn', function() {
            let orderId = $(this).data('order-id');
            let url = "{{ route('orders.buy-shipping', ':id') }}".replace(':id', orderId);
            window.open(url, '_blank');
        });
        const shippingServices = @json($services);
        
        // Function to sync column widths between header and body
        function syncColumnWidths() {
            setTimeout(function() {
                var headerCells = $('.dataTables_scrollHead .dataTable thead th');
                var bodyCells = $('.dataTables_scrollBody .dataTable tbody tr:first td');
                
                if (headerCells.length === bodyCells.length) {
                    headerCells.each(function(index) {
                        var bodyCellWidth = $(bodyCells[index]).outerWidth();
                        if (bodyCellWidth > 0) {
                            $(this).css('width', bodyCellWidth + 'px');
                            $(this).css('min-width', bodyCellWidth + 'px');
                            $(this).css('max-width', bodyCellWidth + 'px');
                        }
                    });
                }
            }, 150);
        }
        
        let table = $('#shipmentTable').DataTable({
            processing: true,
            serverSide: true,
            scrollX: true,
            scrollCollapse: true,
            ajax: {
                url: '{{ route("orders.awaiting.data") }}',
                data: function(d) {
                    d.channels = $('.sales-channel-filter:checked').map(function() {
                        return this.value;
                    }).get();
                    d.marketplace = $('#filterMarketplace').val();
                    d.from_date = $('#filterFromDate').val();
                    d.to_date = $('#filterToDate').val();
                    d.weight_ranges = selectedWeightRanges;
                }
            },
            dom: 'Bfrtipl',
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="bi bi-eye"></i>',
                    titleAttr: 'Show/Hide Columns',
                    columns: ':not(:last-child)',
                }
            ],
            columns: [
                {
                    data: 'marketplace',
                    title: 'Marketplace',
                    render: function(data, type, row) {
                        const name = data.charAt(0).toUpperCase() + data.slice(1);
                        let iconClass = '';
                        if (data === 'amazon') {
                            iconClass = 'fab fa-amazon text-primary fs-5';
                        } else if (data === 'reverb') {
                            iconClass = 'fas fa-guitar text-primary fs-5';
                        } else if (data === 'walmart') {
                            iconClass = 'fas fa-store text-warning fs-5';
                        } else if (data === 'tiktok') {
                            iconClass = 'fab fa-tiktok';
                            return '<span title="' + name + '"><i class="' + iconClass + ' fs-5" style="color:#ee1d52;"></i></span>';
                        } else if (data === 'temu') {
                            iconClass = 'fas fa-shopping-bag';
                            return '<span title="' + name + '"><i class="' + iconClass + ' fs-5" style="color:#ff6200;"></i></span>';
                        } else if (data === 'shopify') {
                            iconClass = 'fab fa-shopify text-success fs-5';
                        } else if (data.startsWith('ebay')) {
                            iconClass = 'fab fa-ebay text-danger fs-5';
                        } else if (data === 'best buy usa' || data === 'Best Buy USA') {
                            iconClass = 'fas fa-bolt';
                            return '<span title="' + name + '"><i class="' + iconClass + ' fs-5" style="color:#0046be;"></i></span>';
                        } else {
                            iconClass = 'fas fa-globe text-secondary fs-5';
                        }
                        return '<span title="' + name + '"><i class="' + iconClass + '"></i></span>';
                    },
                    visible: columnVisibilityMap['marketplace'] !== undefined ? columnVisibilityMap['marketplace'] : true
                },
                {
                    data: 'order_number',
                    title: 'Order Number',
                    render: function(data, type, row) {
                        let icon = row.marked_as_ship == 1 ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-box-seam"></i>';
                        let btnClass = row.marked_as_ship == 1 ? 'btn-success' : 'btn-primary';
                        return `
                            <span title="${data}" style="display: inline-flex; align-items: center; gap: 8px;">
                                <span class="order-number-text">${data || ''}</span>
                                <button class="btn btn-sm copy-order-number-btn" 
                                        data-order-number="${data || ''}"
                                        title="Copy order number"
                                        style="color: #0d6efd; background: transparent; border: none; padding: 2px 6px; min-width: 28px; height: 24px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;">
                                    <i class="fas fa-copy" style="font-size: 12px; display: block; line-height: 1;"></i>
                                </button>
                                <button class="btn btn-xs mark-as-shipped-btn"
                                        data-id="${row.id}" data-order-number="${row.order_number}" data-marked="${row.marked_as_ship}"
                                        title="${data} - ${row.marked_as_ship == 1 ? 'Shipped' : 'Mark as Shipped'}">
                                    ${icon}
                                </button>
                            </span>
                        `;
                    },
                    className: 'text-center',
                    visible: columnVisibilityMap['order_number'] !== undefined ? columnVisibilityMap['order_number'] : true
                },
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
                        if (diffSec < 60) return `${diffSec} sec`;
                        if (diffMin < 60) return `${diffMin} min`;
                        if (diffHour < 24) return `${diffHour} hr`;
                        return `${diffDay} day${diffDay > 1 ? 's' : ''}`;
                    },
                    visible: columnVisibilityMap['order_date'] !== undefined ? columnVisibilityMap['order_date'] : true
                },
                {
                    data: 'order_date',
                    title: 'Order Date',
                    render: function(data) {
                        if (!data) return '';
                        let date = new Date(data);
                        let options = {
                            month: '2-digit', day: '2-digit',
                            hour: '2-digit', minute: '2-digit',
                            timeZone: 'America/New_York'
                        };
                        return new Intl.DateTimeFormat('en-GB', options).format(date);
                    },
                    visible: columnVisibilityMap['order_date'] !== undefined ? columnVisibilityMap['order_date'] : true
                },
                {
                    data: 'sku',
                    title: 'SKU',
                    render: function(data, type, row) {
                        return `
                            <span>${data || '—'}</span>
                            <button class="btn btn-sm btn-link text-primary ms-2 edit-items-btn" data-order-id="${row.id}" title="Edit Items">
                                <i class="fas fa-edit"></i>
                            </button>
                        `;
                    },
                    visible: columnVisibilityMap['sku'] !== undefined ? columnVisibilityMap['sku'] : true
                },
                {
                    data: 'id',
                    title: '<input type="checkbox" id="selectAllD">',
                    render: function(data, type, row) {
                        const hasValidWeight = row.weight != null && row.default_price != null && parseFloat(row.weight) > 0;
                        // Allow if recipient_name exists OR if address data exists (city, state, postal)
                        const hasRecipientName = row.recipient_name && row.recipient_name.trim() !== '' && row.recipient_name !== 'null' && row.recipient_name !== 'NULL';
                        const hasAddressData = (row.ship_city && row.ship_city.trim() !== '') || (row.ship_state && row.ship_state.trim() !== '') || (row.ship_postal_code && row.ship_postal_code.trim() !== '');
                        const hasValidRecipient = hasRecipientName || hasAddressData;
                        const shippingRateFetched = row.shipping_rate_fetched !== undefined ? row.shipping_rate_fetched : (row.shipping_rate_fetched === 0 ? 0 : 1);
                        const hasValidRate = shippingRateFetched == 1 || shippingRateFetched === true;
                        
                        if (hasValidWeight && hasValidRecipient && hasValidRate) {
                            return `<input type="checkbox" class="order-checkbox-d" value="${data}" data-order-id="${data}">`;
                        } else if (!hasValidRecipient) {
                            return `<a href="#" class="text-warning info-icon" data-bs-toggle="modal" data-bs-target="#recipientInfoModal" title="Recipient name or address data is required"><i class="fas fa-user-times"></i></a>`;
                        } else if (!hasValidRate) {
                            return `<a href="#" class="text-info info-icon" title="Rates not fetched yet"><i class="fas fa-info-circle"></i></a>`;
                        } else {
                            return `<a href="#" class="text-info info-icon" data-bs-toggle="modal" data-bs-target="#weightInfoModal" title="Click for info"><i class="fas fa-info-circle"></i></a>`;
                        }
                    },
                    orderable: false,
                    searchable: false,
                    className: 'text-center'
                },
                {
                    data: null,
                    title: 'Best Rate (DECL)',
                    render: function(data, type, row) {
                        const orderId = row.id;
                        const lengthD = parseFloat(row.length_d) || 0;
                        const widthD = parseFloat(row.width_d) || 0;
                        const heightD = parseFloat(row.height_d) || 0;
                        const weightD = parseFloat(row.weight_d) || 0;
                        
                        // Check if we have cached rate for this order (Best Rate D)
                        const cachedRate = row.best_rate_d || null;
                        
                        let displayHtml = '';
                        if (cachedRate && cachedRate.carrier) {
                            // Use best_rate_d from database (rate_type='D')
                            displayHtml = `
                                <span>${cachedRate.carrier}${cachedRate.service ? ' - ' + cachedRate.service : ''} $${parseFloat(cachedRate.price || 0).toFixed(2)}</span>
                            `;
                        } else {
                            // No rate fetched yet for D dimensions
                            displayHtml = '<span class="text-muted">—</span>';
                        }
                        
                        return `
                            <div class="best-rate-d-container" data-order-id="${orderId}">
                                ${displayHtml}
                                <button
                                    class="btn btn-sm btn-link text-primary ms-2 fetch-rate-d-btn"
                                    data-order-id="${orderId}"
                                    data-length="${lengthD}"
                                    data-width="${widthD}"
                                    data-height="${heightD}"
                                    data-weight="${weightD}"
                                    data-ship-to-zip="${row.ship_postal_code || ''}"
                                    data-ship-to-state="${row.ship_state || ''}"
                                    data-ship-to-city="${row.ship_city || ''}"
                                    data-ship-to-country="${row.ship_country || 'US'}"
                                    title="Fetch Best Rate (DECL)"
                                    ${(lengthD === 0 || widthD === 0 || heightD === 0 || weightD === 0) ? 'disabled' : ''}>
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button
                                    class="btn btn-sm btn-link text-primary ms-2 edit-carrier-btn"
                                    data-order-id="${orderId}"
                                    data-rate-type="D"
                                    title="Change Carrier (DECL)">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            </div>
                        `;
                    },
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    visible: columnVisibilityMap['default_carrier'] !== undefined
                        ? columnVisibilityMap['default_carrier']
                        : true
                },
                {
                    data: 'id',
                    title: '<input type="checkbox" id="selectAllO">',
                    render: function(data, type, row) {
                        // Use L, W, H, WT columns (same logic as Best Rate (DIM))
                        // Use 'l' from invent DB, fallback to 'length'
                        const length = parseFloat(row.l) || parseFloat(row.length) || 0;
                        // Use 'w' from invent DB, fallback to 'width'
                        const width = parseFloat(row.w) || parseFloat(row.width) || 0;
                        // Use 'h' from invent DB, fallback to 'height'
                        const height = parseFloat(row.h) || parseFloat(row.height) || 0;
                        // Use 'wt' from invent DB (wt_decl), fallback to 'weight'
                        const weight = parseFloat(row.wt) || parseFloat(row.weight) || 0;
                        const hasValidDimensions = length > 0 && width > 0 && height > 0 && weight > 0;
                        // Allow if recipient_name exists OR if address data exists (city, state, postal)
                        const hasRecipientName = row.recipient_name && row.recipient_name.trim() !== '' && row.recipient_name !== 'null' && row.recipient_name !== 'NULL';
                        const hasAddressData = (row.ship_city && row.ship_city.trim() !== '') || (row.ship_state && row.ship_state.trim() !== '') || (row.ship_postal_code && row.ship_postal_code.trim() !== '');
                        const hasValidRecipient = hasRecipientName || hasAddressData;
                        const cachedRate = row.best_rate_o || null;
                        const hasValidRate = cachedRate && cachedRate.carrier;
                        
                        if (hasValidDimensions && hasValidRecipient && hasValidRate) {
                            return `<input type="checkbox" class="order-checkbox-o" value="${data}" data-order-id="${data}">`;
                        } else if (!hasValidRecipient) {
                            return `<a href="#" class="text-warning info-icon" data-bs-toggle="modal" data-bs-target="#recipientInfoModal" title="Recipient name or address data is required"><i class="fas fa-user-times"></i></a>`;
                        } else if (!hasValidDimensions) {
                            return `<a href="#" class="text-info info-icon" title="Dimensions (L, W, H, WT) are required"><i class="fas fa-info-circle"></i></a>`;
                        } else if (!hasValidRate) {
                            return `<a href="#" class="text-info info-icon" title="Best Rate (DIM) not fetched yet"><i class="fas fa-info-circle"></i></a>`;
                        } else {
                            return `<a href="#" class="text-info info-icon" title="Click for info"><i class="fas fa-info-circle"></i></a>`;
                        }
                    },
                    orderable: false,
                    searchable: false,
                    className: 'text-center'
                },
                {
                    data: null,
                    title: 'Best Rate (DIM)',
                    render: function(data, type, row) {
                        const orderId = row.id;
                        // Use 'l' from invent DB, fallback to 'length' (same logic as L column)
                        const length = parseFloat(row.l) || parseFloat(row.length) || 0;
                        // Use 'w' from invent DB, fallback to 'width' (same logic as W column)
                        const width = parseFloat(row.w) || parseFloat(row.width) || 0;
                        // Use 'h' from invent DB, fallback to 'height' (same logic as H column)
                        const height = parseFloat(row.h) || parseFloat(row.height) || 0;
                        // Use 'wt' from invent DB (wt_decl), fallback to 'weight' (same logic as WT column)
                        const weight = parseFloat(row.wt) || parseFloat(row.weight) || 0;
                        
                        // Check if we have cached rate for this order (Best Rate O)
                        const cachedRate = row.best_rate_o || null;
                        
                        let displayHtml = '';
                        if (cachedRate && cachedRate.carrier) {
                            // Use best_rate_o from database (rate_type='O')
                            displayHtml = `
                                <span>${cachedRate.carrier}${cachedRate.service ? ' - ' + cachedRate.service : ''} $${parseFloat(cachedRate.price || 0).toFixed(2)}</span>
                            `;
                        } else {
                            // No rate fetched yet for O (regular) dimensions
                            displayHtml = '<span class="text-muted">—</span>';
                        }
                        
                        return `
                            <div class="best-rate-o-container" data-order-id="${orderId}">
                                ${displayHtml}
                                <button
                                    class="btn btn-sm btn-link text-primary ms-2 fetch-rate-o-btn"
                                    data-order-id="${orderId}"
                                    data-length="${length}"
                                    data-width="${width}"
                                    data-height="${height}"
                                    data-weight="${weight}"
                                    data-ship-to-zip="${row.ship_postal_code || ''}"
                                    data-ship-to-state="${row.ship_state || ''}"
                                    data-ship-to-city="${row.ship_city || ''}"
                                    data-ship-to-country="${row.ship_country || 'US'}"
                                    title="Fetch Best Rate (DIM)"
                                    ${(length === 0 || width === 0 || height === 0 || weight === 0) ? 'disabled' : ''}>
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button
                                    class="btn btn-sm btn-link text-primary ms-2 edit-carrier-btn"
                                    data-order-id="${orderId}"
                                    data-rate-type="O"
                                    title="Change Carrier (DIM)">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            </div>
                        `;
                    },
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    visible: columnVisibilityMap['best_rate_o'] !== undefined ? columnVisibilityMap['best_rate_o'] : true
                },
                {
                    data: 'recipient_name',
                    title: 'Recipient',
                    render: function(data, type, row) {
                        const verificationMark = row.is_address_verified === 0
                            ? ' <i class="fas fa-exclamation-circle text-warning" title="Address not verified"></i>'
                            : '';
                        const recipientValue = data || '—';
                        // Escape HTML entities
                        function escapeHtml(text) {
                            if (!text) return '';
                            const map = {
                                '&': '&amp;',
                                '<': '&lt;',
                                '>': '&gt;',
                                '"': '&quot;',
                                "'": '&#039;'
                            };
                            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
                        }
                        const escapedRecipient = escapeHtml(recipientValue);
                        return '<div style="display: flex; align-items: center; gap: 5px;">' +
                            '<button class="btn btn-sm btn-link p-0 toggle-recipient-btn" ' +
                            'data-order-id="' + row.id + '" ' +
                            'title="Show/Hide Recipient" ' +
                            'style="color: #6c757d; padding: 0 4px;">' +
                            '<i class="bi bi-eye" style="font-size: 12px;"></i>' +
                            '</button>' +
                            '<span class="recipient-value" data-order-id="' + row.id + '" style="display: none;">' +
                            '<a href="#" class="recipient-link" data-order-id="' + row.id + '">' +
                            escapedRecipient + verificationMark +
                            '</a>' +
                            '</span>' +
                            '<button class="btn btn-sm btn-link text-primary p-0 edit-address-btn" ' +
                            'data-order=\'' + JSON.stringify(row).replace(/'/g, "&apos;") + '\' ' +
                            'title="Edit Address" ' +
                            'style="padding: 0 4px;">' +
                            '<i class="fas fa-edit" style="font-size: 12px;"></i>' +
                            '</button>' +
                            '</div>';
                    },
                    visible: columnVisibilityMap['recipient_name'] !== undefined ? columnVisibilityMap['recipient_name'] : true
                },
                {
                    data: 'length_d',
                    title: 'L (DECL)',
                    className: 'text-end',
                    render: function(data, type, row) {
                        let displayValue = '—';
                        if (data !== null && data !== undefined && data !== '') {
                            const num = parseFloat(data);
                            if (!isNaN(num)) {
                                const decimals = (data.toString().split('.')[1] || '').length;
                                if (decimals > 0) {
                                    displayValue = decimals > 1 ? num.toFixed(decimals - 1) : Math.round(num).toString();
                                } else {
                                    displayValue = num.toString();
                                }
                            } else {
                                displayValue = data;
                            }
                        }
                        return `
                            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 5px;">
                                <span>${displayValue}</span>
                                <button class="btn btn-sm btn-link p-0 edit-dimension-btn" 
                                        data-order-id="${row.id}" 
                                        data-field="length_d" 
                                        data-value="${data || ''}"
                                        data-label="L (DECL)"
                                        title="Edit L (DECL)" 
                                        style="color: #0d6efd; padding: 0 4px;">
                                    <i class="fas fa-edit" style="font-size: 12px;"></i>
                                </button>
                            </div>
                        `;
                    },
                    visible: false
                },
                {
                    data: 'width_d',
                    title: 'W (DECL)',
                    className: 'text-end',
                    render: function(data, type, row) {
                        let displayValue = '—';
                        if (data !== null && data !== undefined && data !== '') {
                            const num = parseFloat(data);
                            if (!isNaN(num)) {
                                const decimals = (data.toString().split('.')[1] || '').length;
                                if (decimals > 0) {
                                    displayValue = decimals > 1 ? num.toFixed(decimals - 1) : Math.round(num).toString();
                                } else {
                                    displayValue = num.toString();
                                }
                            } else {
                                displayValue = data;
                            }
                        }
                        return `
                            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 5px;">
                                <span>${displayValue}</span>
                                <button class="btn btn-sm btn-link p-0 edit-dimension-btn" 
                                        data-order-id="${row.id}" 
                                        data-field="width_d" 
                                        data-value="${data || ''}"
                                        data-label="W (DECL)"
                                        title="Edit W (DECL)" 
                                        style="color: #0d6efd; padding: 0 4px;">
                                    <i class="fas fa-edit" style="font-size: 12px;"></i>
                                </button>
                            </div>
                        `;
                    },
                    visible: false
                },
                {
                    data: 'height_d',
                    title: 'H (DECL)',
                    className: 'text-end',
                    render: function(data, type, row) {
                        let displayValue = '—';
                        if (data !== null && data !== undefined && data !== '') {
                            const num = parseFloat(data);
                            if (!isNaN(num)) {
                                const decimals = (data.toString().split('.')[1] || '').length;
                                if (decimals > 0) {
                                    displayValue = decimals > 1 ? num.toFixed(decimals - 1) : Math.round(num).toString();
                                } else {
                                    displayValue = num.toString();
                                }
                            } else {
                                displayValue = data;
                            }
                        }
                        return `
                            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 5px;">
                                <span>${displayValue}</span>
                                <button class="btn btn-sm btn-link p-0 edit-dimension-btn" 
                                        data-order-id="${row.id}" 
                                        data-field="height_d" 
                                        data-value="${data || ''}"
                                        data-label="H (DECL)"
                                        title="Edit H (DECL)" 
                                        style="color: #0d6efd; padding: 0 4px;">
                                    <i class="fas fa-edit" style="font-size: 12px;"></i>
                                </button>
                            </div>
                        `;
                    },
                    visible: false
                },
                {
                    data: 'weight_d',
                    title: 'WT (DECL)',
                    className: 'text-end',
                    render: function(data, type, row) {
                        let displayValue = '—';
                        if (data !== null && data !== undefined && data !== '') {
                            const num = parseFloat(data);
                            if (!isNaN(num)) {
                                const decimals = (data.toString().split('.')[1] || '').length;
                                if (decimals > 0) {
                                    displayValue = decimals > 1 ? num.toFixed(decimals - 1) : Math.round(num).toString();
                                } else {
                                    displayValue = num.toString();
                                }
                            } else {
                                displayValue = data;
                            }
                        }
                        return `
                            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 5px;">
                                <span>${displayValue}</span>
                                <button class="btn btn-sm btn-link p-0 edit-dimension-btn" 
                                        data-order-id="${row.id}" 
                                        data-field="weight_d" 
                                        data-value="${data || ''}"
                                        data-label="WT (DECL)"
                                        title="Edit WT (DECL)" 
                                        style="color: #0d6efd; padding: 0 4px;">
                                    <i class="fas fa-edit" style="font-size: 12px;"></i>
                                </button>
                            </div>
                        `;
                    },
                    visible: false
                },
                {
                    data: null,
                    title: 'WT S',
                    className: 'text-end',
                    render: function(data, type, row) {
                        const weight_d = parseFloat(row.weight_d) || 0;
                        const quantity = parseFloat(row.quantity) || 0;
                        const wt_s = weight_d * quantity;
                        
                        if (wt_s === 0 || isNaN(wt_s)) return '—';
                        
                        const decimals = (wt_s.toString().split('.')[1] || '').length;
                        if (decimals > 0) {
                            return decimals > 1 ? wt_s.toFixed(decimals - 1) : Math.round(wt_s).toString();
                        }
                        return wt_s.toString();
                    },
                    visible: columnVisibilityMap['wt_s'] !== undefined ? columnVisibilityMap['wt_s'] : true
                },
                {
                    data: null,
                    title: 'CBIN (DECL)',
                    className: 'text-end',
                    render: function(data, type, row) {
                        const length_d = parseFloat(row.length_d) || 0;
                        const width_d = parseFloat(row.width_d) || 0;
                        const height_d = parseFloat(row.height_d) || 0;
                        const weight_d = parseFloat(row.weight_d) || 0;
                        const cbin = length_d * width_d * height_d;
                        
                        if (cbin === 0 || isNaN(cbin)) return '—';
                        
                        const formattedValue = Math.round(cbin);
                        
                        // Determine if CBIN should be red based on WT (DECL) ranges
                        let isOverLimit = false;
                        
                        if (weight_d <= 5) {
                            isOverLimit = cbin > 172;
                        } else if (weight_d > 5 && weight_d <= 8) {
                            isOverLimit = cbin > 345;
                        } else if (weight_d > 8 && weight_d <= 10) {
                            isOverLimit = cbin > 518;
                        } else if (weight_d > 10 && weight_d <= 15) {
                            isOverLimit = cbin > 691;
                        } else if (weight_d > 15 && weight_d <= 20) {
                            isOverLimit = cbin > 864;
                        } else if (weight_d > 20.01) {
                            isOverLimit = true; // Always red if WT (DECL) > 20.01
                        }
                        
                        const styleClass = isOverLimit ? 'text-danger fw-bold' : '';
                        
                        return `<span class="${styleClass}">${formattedValue}</span>`;
                    },
                    visible: columnVisibilityMap['cbm_in'] !== undefined ? columnVisibilityMap['cbm_in'] : true
                },
                {
                    data: 'h',
                    title: 'H',
                    className: 'text-end',
                    render: function(data, type, row) {
                        // Use 'h' from invent DB, fallback to 'height'
                        const value = data !== null && data !== undefined && data !== '' ? data : (row.height || null);
                        if (value === null || value === undefined || value === '') return '—';
                        const num = parseFloat(value);
                        if (isNaN(num)) return value;
                        // Reduce one decimal place: if has decimals, show one less; otherwise keep as is
                        const decimals = (value.toString().split('.')[1] || '').length;
                        if (decimals > 0) {
                            return decimals > 1 ? num.toFixed(decimals - 1) : Math.round(num).toString();
                        }
                        return num.toString();
                    },
                    visible: false
                },
                {
                    data: 'w',
                    title: 'W',
                    className: 'text-end',
                    render: function(data, type, row) {
                        // Use 'w' from invent DB, fallback to 'width'
                        const value = data !== null && data !== undefined && data !== '' ? data : (row.width || null);
                        if (value === null || value === undefined || value === '') return '—';
                        const num = parseFloat(value);
                        if (isNaN(num)) return value;
                        // Reduce one decimal place: if has decimals, show one less; otherwise keep as is
                        const decimals = (value.toString().split('.')[1] || '').length;
                        if (decimals > 0) {
                            return decimals > 1 ? num.toFixed(decimals - 1) : Math.round(num).toString();
                        }
                        return num.toString();
                    },
                    visible: false
                },
                {
                    data: 'l',
                    title: 'L',
                    className: 'text-end',
                    render: function(data, type, row) {
                        // Use 'l' from invent DB, fallback to 'length'
                        const value = data !== null && data !== undefined && data !== '' ? data : (row.length || null);
                        if (value === null || value === undefined || value === '') return '—';
                        const num = parseFloat(value);
                        if (isNaN(num)) return value;
                        // Reduce one decimal place: if has decimals, show one less; otherwise keep as is
                        const decimals = (value.toString().split('.')[1] || '').length;
                        if (decimals > 0) {
                            return decimals > 1 ? num.toFixed(decimals - 1) : Math.round(num).toString();
                        }
                        return num.toString();
                    },
                    visible: false
                },
                {
                    data: 'wt',
                    title: 'WT',
                    className: 'text-end',
                    render: function(data, type, row) {
                        // Use 'wt' from invent DB (wt_decl), fallback to 'weight'
                        const value = data !== null && data !== undefined && data !== '' ? data : (row.weight || null);
                        if (value === null || value === undefined || value === '') return '—';
                        const num = parseFloat(value);
                        if (isNaN(num)) return value;
                        // Reduce one decimal place: if has decimals, show one less; otherwise keep as is
                        const decimals = (value.toString().split('.')[1] || '').length;
                        if (decimals > 0) {
                            return decimals > 1 ? num.toFixed(decimals - 1) : Math.round(num).toString();
                        }
                        return num.toString();
                    },
                    visible: false
                },
                {
                    data: 'wt_act',
                    title: 'WT ACT',
                    className: 'text-end',
                    render: function(data) {
                        if (data === null || data === undefined || data === '') return '—';
                        const num = parseFloat(data);
                        if (isNaN(num)) return data;
                        const decimals = (data.toString().split('.')[1] || '').length;
                        if (decimals > 0) {
                            return decimals > 1 ? num.toFixed(decimals - 1) : Math.round(num).toString();
                        }
                        return num.toString();
                    },
                    visible: columnVisibilityMap['wt_act'] !== undefined ? columnVisibilityMap['wt_act'] : true
                },
                {
                    data: null,
                    title: 'WT ACT S',
                    className: 'text-end',
                    render: function(data, type, row) {
                        const wt_act = parseFloat(row.wt_act) || 0;
                        const quantity = parseFloat(row.quantity) || 0;
                        const wt_act_s = wt_act * quantity;
                        
                        if (wt_act_s === 0 || isNaN(wt_act_s)) return '—';
                        
                        const decimals = (wt_act_s.toString().split('.')[1] || '').length;
                        if (decimals > 0) {
                            return decimals > 1 ? wt_act_s.toFixed(decimals - 1) : Math.round(wt_act_s).toString();
                        }
                        return wt_act_s.toString();
                    },
                    visible: columnVisibilityMap['wt_act_s'] !== undefined ? columnVisibilityMap['wt_act_s'] : true
                },
                {
                    data: 'inv',
                    title: 'INV',
                    className: 'text-end',
                    render: function(data) {
                        if (data === null || data === undefined || data === '') return '—';
                        const num = parseFloat(data);
                        if (isNaN(num)) return data;
                        const value = num.toString();
                        // Make text red if inventory is 0
                        if (num === 0) {
                            return `<span class="text-danger fw-bold">${value}</span>`;
                        }
                        return value;
                    },
                    visible: columnVisibilityMap['inv'] !== undefined ? columnVisibilityMap['inv'] : true
                },
                {
                    data: 'quantity',
                    title: 'Qty',
                    className: 'text-end',
                    render: function(data, type, row) {
                        return `
                            <span>${data || '—'}</span>
                            <button class="btn btn-sm btn-link text-primary ms-2 edit-items-btn" data-order-id="${row.id}" title="Edit Items">
                                <i class="fas fa-edit"></i>
                            </button>
                        `;
                    },
                    visible: columnVisibilityMap['quantity'] !== undefined ? columnVisibilityMap['quantity'] : true
                },
                {
                    data: 'order_total',
                    title: 'Order Total',
                    render: $.fn.dataTable.render.number(',', '.', 2, '$'),
                    className: 'text-end',
                    visible: columnVisibilityMap['order_total'] !== undefined ? columnVisibilityMap['order_total'] : true
                }
            ],
            order: [[4, 'asc']],
            pageLength: 50,
            lengthMenu: [50, 25, 50, 100],
            rowCallback: function(row, data) {
                // Apply CBIN filter using rowCallback
                if (cbinFilterActive && !isCbinRed(data)) {
                    $(row).hide();
                } else {
                    $(row).show();
                }
            },
            drawCallback: function() {
                // Sync column widths between header and body
                syncColumnWidths();
                
                // Best rates are now loaded from database, no need to auto-fetch
                // autoFetchMissingRates(); // Removed - rates are loaded from database
            },
            initComplete: function() {
                // Sync column widths on initialization
                setTimeout(function() {
                    syncColumnWidths();
                }, 200);
            }
        });
        
        // Function to automatically fetch missing rates for orders
        const fetchingRates = new Set(); // Track orders currently being fetched to avoid duplicate requests
        function autoFetchMissingRates() {
            try {
                table.rows({page: 'current'}).every(function() {
                    const row = this.data();
                    if (!row || !row.id) return;
                    
                    const orderId = row.id;
                    
                    // Check if Best Rate (DECL) needs to be fetched
                    // Best Rate (DECL) is available if default_carrier exists and shipping_rate_fetched is true
                    const hasBestRateD = row.default_carrier && row.default_carrier !== '—' && 
                                        (row.shipping_rate_fetched === 1 || row.shipping_rate_fetched === true);
                    const needsBestRateD = !hasBestRateD;
                    
                    // Check if Best Rate (DIM) needs to be fetched
                    // Best Rate (DIM) is available if best_rate_o exists with carrier
                    const hasBestRateO = row.best_rate_o && row.best_rate_o.carrier;
                    const needsBestRateO = !hasBestRateO;
                    
                    // Get DECL dimensions for Best Rate (DECL)
                    const lengthD = parseFloat(row.length_d) || 0;
                    const widthD = parseFloat(row.width_d) || 0;
                    const heightD = parseFloat(row.height_d) || 0;
                    const weightD = parseFloat(row.weight_d) || 0;
                    
                    // Get regular dimensions for Best Rate (DIM) - use L, W, H, WT columns
                    // Use 'l' from invent DB, fallback to 'length' (same logic as L column)
                    const length = parseFloat(row.l) || parseFloat(row.length) || 0;
                    // Use 'w' from invent DB, fallback to 'width' (same logic as W column)
                    const width = parseFloat(row.w) || parseFloat(row.width) || 0;
                    // Use 'h' from invent DB, fallback to 'height' (same logic as H column)
                    const height = parseFloat(row.h) || parseFloat(row.height) || 0;
                    // Use 'wt' from invent DB (wt_decl), fallback to 'weight' (same logic as WT column)
                    const weight = parseFloat(row.wt) || parseFloat(row.weight) || 0;
                    
                    // Fetch Best Rate (DECL) if missing and dimensions are available
                    if (needsBestRateD && lengthD > 0 && widthD > 0 && heightD > 0 && weightD > 0) {
                        const fetchKey = `d_${orderId}`;
                        if (!fetchingRates.has(fetchKey)) {
                            fetchingRates.add(fetchKey);
                            fetchBestRateD(orderId, lengthD, widthD, heightD, weightD, row);
                        }
                    }
                    
                    // Fetch Best Rate (DIM) if missing and dimensions are available
                    if (needsBestRateO && length > 0 && width > 0 && height > 0 && weight > 0) {
                        const fetchKey = `o_${orderId}`;
                        if (!fetchingRates.has(fetchKey)) {
                            fetchingRates.add(fetchKey);
                            fetchBestRateO(orderId, length, width, height, weight, row);
                        }
                    }
                });
            } catch (e) {
                console.error('Error auto-fetching missing rates:', e);
            }
        }
        
        // Function to fetch Best Rate (DECL) automatically
        function fetchBestRateD(orderId, lengthD, widthD, heightD, weightD, row) {
            $.ajax({
                url: '{{ route("orders.fetch-rate-d") }}',
                type: 'POST',
                data: {
                    order_id: orderId,
                    length: lengthD,
                    width: widthD,
                    height: heightD,
                    weight: weightD,
                    ship_to_zip: row.ship_postal_code || '',
                    ship_to_state: row.ship_state || '',
                    ship_to_city: row.ship_city || '',
                    ship_to_country: row.ship_country || 'US',
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    fetchingRates.delete(`d_${orderId}`);
                    if (response.success && response.rate) {
                        const rate = response.rate;
                        // Update the row data
                        const tableRow = table.row(function(idx, data) {
                            return data.id == orderId;
                        });
                        if (tableRow.length) {
                            const rowData = tableRow.data();
                            rowData.best_rate_d = rate;
                            rowData.default_carrier = rate.carrier;
                            rowData.default_service = rate.service;
                            rowData.default_price = rate.price;
                            rowData.default_source = rate.source;
                            rowData.shipping_rate_fetched = true;
                            tableRow.data(rowData);
                            // Reload the specific row to update the display
                            tableRow.invalidate().draw(false);
                        }
                    }
                },
                error: function(xhr) {
                    fetchingRates.delete(`d_${orderId}`);
                    console.error('Failed to auto-fetch Best Rate (DECL) for order', orderId, xhr);
                }
            });
        }
        
        // Function to fetch Best Rate (DIM) automatically
        function fetchBestRateO(orderId, length, width, height, weight, row) {
            $.ajax({
                url: '{{ route("orders.fetch-rate-o") }}',
                type: 'POST',
                data: {
                    order_id: orderId,
                    length: length,
                    width: width,
                    height: height,
                    weight: weight,
                    ship_to_zip: row.ship_postal_code || '',
                    ship_to_state: row.ship_state || '',
                    ship_to_city: row.ship_city || '',
                    ship_to_country: row.ship_country || 'US',
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    fetchingRates.delete(`o_${orderId}`);
                    if (response.success && response.rate) {
                        const rate = response.rate;
                        // Update the row data
                        const tableRow = table.row(function(idx, data) {
                            return data.id == orderId;
                        });
                        if (tableRow.length) {
                            const rowData = tableRow.data();
                            rowData.best_rate_o = rate;
                            tableRow.data(rowData);
                            // Reload the specific row to update the display
                            tableRow.invalidate().draw(false);
                        }
                    }
                },
                error: function(xhr) {
                    fetchingRates.delete(`o_${orderId}`);
                    console.error('Failed to auto-fetch Best Rate (DIM) for order', orderId, xhr);
                }
            });
        }
        
        // Auto-refresh table periodically to check for new orders
        // Refresh every 10 minutes to check for new orders (rates are loaded from database, no need to auto-fetch)
        setInterval(function() {
            // Store current scroll position
            const scrollTop = $('.dataTables_scrollBody').scrollTop();
            const currentPage = table.page.info().page;
            
            // Reload the table to get new orders and best rates from database
            table.ajax.reload(function(json) {
                // Restore scroll position
                setTimeout(function() {
                    $('.dataTables_scrollBody').scrollTop(scrollTop);
                    if (currentPage !== table.page.info().page) {
                        table.page(currentPage).draw(false);
                    }
                }, 100);
                // Best rates are now loaded from database, no need to auto-fetch
            }, false); // false = don't reset pagination
        }, 600000); // 10 minutes (600000 milliseconds)
        
        // Set default weight filter
        $('.weight-filter-btn[data-weight-range="all"]').addClass('active');
        // Handle weight filter button clicks for multiple selection
        $('.weight-filter-btn').on('click', function() {
            const $this = $(this);
            const range = $this.data('weight-range');
            const $allBtn = $('.weight-filter-btn[data-weight-range="all"]');
            if (range === 'all') {
                // Toggle "All Weights" - if activated, deactivate all others
                $this.toggleClass('active');
                if ($this.hasClass('active')) {
                    $('.weight-filter-btn').not('[data-weight-range="all"]').removeClass('active');
                }
            } else {
                // For specific ranges: toggle and deactivate "All" if it was active
                $this.toggleClass('active');
                if ($allBtn.hasClass('active')) {
                    $allBtn.removeClass('active');
                }
            }
            // Collect selected ranges
            let activeSpecificRanges = $('.weight-filter-btn').not('[data-weight-range="all"]').filter('.active').map(function() {
                return $(this).data('weight-range');
            }).get();
            if ($allBtn.hasClass('active') || activeSpecificRanges.length === 0) {
                selectedWeightRanges = ['all'];
            } else {
                selectedWeightRanges = activeSpecificRanges;
            }
            table.ajax.reload();
        });
        // Function to count red CBIN rows from currently loaded data
        function countRedCbin() {
            let count = 0;
            try {
                // Count rows from currently loaded page
                table.rows({page: 'current'}).every(function() {
                    const row = this.data();
                    if (row && isCbinRed(row)) {
                        count++;
                    }
                });
            } catch (e) {
                console.error('Error counting red CBIN:', e);
            }
            $('#redCbinCount').text(count);
        }
        
        // CBIN filter is now handled in rowCallback above
        // Handle CBIN filter button click
        $('#cbinFilterBtn').on('click', function() {
            cbinFilterActive = !cbinFilterActive;
            $(this).toggleClass('active', cbinFilterActive);
            if (cbinFilterActive) {
                $(this).addClass('btn-danger').removeClass('btn-outline-danger');
            } else {
                $(this).addClass('btn-outline-danger').removeClass('btn-danger');
            }
            table.draw();
        });
        
        // Handle dimension column toggle buttons
        // Find columns by their data property
        function getColumnIndices(dataProperties) {
            const indices = [];
            table.columns().every(function(index) {
                const dataSrc = this.dataSrc();
                if (dataProperties.includes(dataSrc)) {
                    indices.push(index);
                }
            });
            return indices;
        }
        
        $('#toggleDDimensions').on('click', function() {
            const $btn = $(this);
            const dColumnIndices = getColumnIndices(['length_d', 'width_d', 'height_d', 'weight_d']);
            
            if (dColumnIndices.length === 0) return;
            
            const allVisible = dColumnIndices.every(idx => table.column(idx).visible());
            
            dColumnIndices.forEach(idx => {
                table.column(idx).visible(!allVisible);
            });
            
            // Update button text and icon
            if (!allVisible) {
                $btn.html('<i class="bi bi-eye-slash"></i> Hide DECL Dimensions');
                $btn.removeClass('btn-outline-secondary').addClass('btn-secondary');
            } else {
                $btn.html('<i class="bi bi-eye"></i> Show DECL Dimensions');
                $btn.removeClass('btn-secondary').addClass('btn-outline-secondary');
            }
            
            // Sync column widths after toggling
            table.columns.adjust().draw(false);
            syncColumnWidths();
        });
        
        $('#toggleRegularDimensions').on('click', function() {
            const $btn = $(this);
            const regularColumnIndices = getColumnIndices(['h', 'w', 'l', 'wt']);
            
            if (regularColumnIndices.length === 0) return;
            
            const allVisible = regularColumnIndices.every(idx => table.column(idx).visible());
            
            regularColumnIndices.forEach(idx => {
                table.column(idx).visible(!allVisible);
            });
            
            // Update button text and icon
            if (!allVisible) {
                $btn.html('<i class="bi bi-eye-slash"></i> Hide DIM');
                $btn.removeClass('btn-outline-secondary').addClass('btn-secondary');
            } else {
                $btn.html('<i class="bi bi-eye"></i> Show DIM');
                $btn.removeClass('btn-secondary').addClass('btn-outline-secondary');
            }
            
            // Sync column widths after toggling
            table.columns.adjust().draw(false);
            syncColumnWidths();
        });
        
        // Update red CBIN count when table data changes
        table.on('draw.dt', function() {
            setTimeout(function() {
                countRedCbin();
            }, 100);
        });
        
        // Initial count update after table is fully initialized
        table.on('init.dt', function() {
            setTimeout(function() {
                countRedCbin();
            }, 500);
        });
        
        // Also update count after ajax reload
        table.on('xhr.dt', function() {
            setTimeout(function() {
                countRedCbin();
            }, 100);
        });
        table.on('column-visibility.dt', function(e, settings, column, state) {
            let columnName = settings.aoColumns[column].data;
            let screenName = 'awaiting_shipment';
            $.ajax({
                url: "{{ route('user-columns.save') }}",
                type: "POST",
                data: {
                    _token: '{{ csrf_token() }}',
                    screen_name: screenName,
                    column_name: columnName,
                    is_visible: state ? 1 : 0,
                    order_index: column
                }
            });
        });
        $('#selectAllD').on('change', function() {
            // Check all visible checkboxes
            $('.order-checkbox-d', table.rows().nodes()).each(function() {
                const $checkbox = $(this);
                $checkbox.prop('checked', $('#selectAllD').prop('checked'));
            });
            updateBulkButtonState();
        });
        $('#selectAllO').on('change', function() {
            // Check all visible checkboxes
            $('.order-checkbox-o', table.rows().nodes()).each(function() {
                const $checkbox = $(this);
                $checkbox.prop('checked', $('#selectAllO').prop('checked'));
            });
            updateBulkButtonState();
        });
        $('#shipmentTable').on('change', '.order-checkbox-d, .order-checkbox-o', function() {
            const $checkbox = $(this);
            const orderId = $checkbox.val();
            const isD = $checkbox.hasClass('order-checkbox-d');
            
            // If this checkbox is checked, uncheck the other type for the same order
            if ($checkbox.is(':checked')) {
                if (isD) {
                    $(`.order-checkbox-o[value="${orderId}"]`).prop('checked', false);
                } else {
                    $(`.order-checkbox-d[value="${orderId}"]`).prop('checked', false);
                }
            }
            
            updateBulkButtonState();
        });
        function updateBulkButtonState() {
            let selectedCountD = $('.order-checkbox-d:checked').length;
            let selectedCountO = $('.order-checkbox-o:checked').length;
            let totalSelectedCount = selectedCountD + selectedCountO;
            $('.bulk-buy-shipping-btn').prop('disabled', totalSelectedCount === 0);
            $('.bulk-update-dimensions-btn').prop('disabled', totalSelectedCount === 0);
            $('#bulkDimensionInputs').toggleClass('active', totalSelectedCount > 0);

        }
        $('.bulk-buy-shipping-btn').on('click', function() {
            // Collect orders with their rate types (D or O)
            let selectedOrders = [];
            
            // Process Best Rate (DECL) checkboxes
            $('.order-checkbox-d:checked').each(function() {
                const $checkbox = $(this);
                const orderId = $(this).val();
                const row = table.row($checkbox.closest('tr')).data();
                
                const orderData = {
                    order_id: parseInt(orderId),
                    rate_type: 'D',
                    rate_id: row.default_rate_id || null,
                    rate_info: {
                        carrier: row.default_carrier || null,
                        service: row.default_service || null,
                        price: row.default_price || null,
                        source: row.default_source || null
                    }
                };
                selectedOrders.push(orderData);
            });
            
            // Process Best Rate (DIM) checkboxes
            $('.order-checkbox-o:checked').each(function() {
                const $checkbox = $(this);
                const orderId = $(this).val();
                const row = table.row($checkbox.closest('tr')).data();
                
                const cachedRate = row.best_rate_o || null;
                const orderData = {
                    order_id: parseInt(orderId),
                    rate_type: 'O',
                    rate_id: cachedRate?.rate_id || null,
                    rate_info: cachedRate ? {
                        carrier: cachedRate.carrier || null,
                        service: cachedRate.service || null,
                        price: cachedRate.price || null
                    } : null
                };
                selectedOrders.push(orderData);
            });
            
            if (selectedOrders.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Orders Selected',
                    text: 'Please select at least one order to proceed.',
                    confirmButtonText: 'OK'
                });
                updateBulkButtonState();
                return;
            }
            
            // Check if any orders have inventory = 0 (informational only)
            const ordersWithNoInventory = selectedOrders.filter(function(orderData) {
                const $checkbox = orderData.rate_type === 'D' 
                    ? $(`.order-checkbox-d[value="${orderData.order_id}"]`)
                    : $(`.order-checkbox-o[value="${orderData.order_id}"]`);
                const row = table.row($checkbox.closest('tr')).data();
                const invValue = parseFloat(row?.inv) || 0;
                return invValue === 0;
            });
            
            if (ordersWithNoInventory.length > 0) {
                const filteredCount = ordersWithNoInventory.length;
                Swal.fire({
                    icon: 'warning',
                    title: 'Orders with No Inventory',
                    html: `${filteredCount} order(s) with INV = 0 are included in the selection.<br>Proceeding with ${selectedOrders.length} order(s).`,
                    showCancelButton: true,
                    confirmButtonText: 'Continue',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (!result.isConfirmed) {
                        return;
                    }
                    proceedWithBulkShipping(selectedOrders);
                });
                return;
            }
            
            proceedWithBulkShipping(selectedOrders);
        });
        
        function proceedWithBulkShipping(selectedOrders) {
            Swal.fire({
                icon: 'info',
                title: 'Buy Bulk Shipping',
                html: `
                    Are you sure you want to buy shipping for ${selectedOrders.length} orders?
                    <br><br>
                    <div class="alert alert-warning small mt-2">
                        <i class="bi bi-info-circle"></i> Please ensure sufficient balance is available in your shipping carrier account before proceeding.
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Yes',
                cancelButtonText: 'No'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#loader').show();
                    $('body').addClass('loader-active');
                    $.ajax({
                        url: "{{ route('orders.bulk-shipping') }}",
                        type: 'POST',
                        data: {
                            orders: selectedOrders,
                            _token: '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            $('#loader').hide();
                            $('body').removeClass('loader-active');
                            
                            // Console debug output
                            console.group('🔍 Bulk Buy Shipping Debug Info');
                            console.log('Response:', response);
                            if (response.labels && response.labels.debug) {
                                console.log('Debug Info:', response.labels.debug);
                                Object.keys(response.labels.debug).forEach(function(orderId) {
                                    console.group(`Order #${orderId}`);
                                    const debug = response.labels.debug[orderId];
                                    Object.keys(debug).forEach(function(step) {
                                        console.log(`${step}:`, debug[step]);
                                    });
                                    console.groupEnd();
                                });
                            }
                            if (response.failed_details && response.failed_details.length > 0) {
                                console.group('❌ Failed Orders Details');
                                response.failed_details.forEach(function(detail) {
                                    console.error(`Order #${detail.order_id}:`, detail.message, detail);
                                });
                                console.groupEnd();
                            }
                            console.groupEnd();
                            
                            if (response.success) {
                                let downloadLink = '';
                                let summaryHtml = '';
                                let failedDetailsHtml = '';
                                let instructionsHtml = '';
                                
                                // Check if there are any failures (partial success scenario)
                                const hasFailures = response.summary && response.summary.failed_count > 0;
                                const hasFailedDetails = response.failed_details && response.failed_details.length > 0;
                                
                                if (response.labels && response.labels.summary) {
                                    summaryHtml = `<br><br><strong>Summary:</strong><br>
                                        Total Orders Processed: ${response.labels.summary.total_processed}<br>
                                        Successfully Processed: ${response.labels.summary.success_count}<br>
                                        Failed: ${response.labels.summary.failed_count}`;
                                } else if (response.summary) {
                                    summaryHtml = `<br><br><strong>Summary:</strong><br>
                                        Total Orders Processed: ${response.summary.total_processed || 0}<br>
                                        Successfully Processed: ${response.summary.success_count || 0}<br>
                                        Failed: ${response.summary.failed_count || 0}`;
                                }
                                
                                // Show failed details if there are any failures
                                if (hasFailures && hasFailedDetails) {
                                    failedDetailsHtml = '<br><br><strong>Failed Order Details:</strong><ul style="text-align: left; margin-top: 10px; max-height: 300px; overflow-y: auto;">';
                                    response.failed_details.forEach(function(detail) {
                                        const errorMsg = detail.message || detail.error || 'Unknown error';
                                        failedDetailsHtml += `<li style="margin-bottom: 8px;"><strong>Order #${detail.order_id}:</strong> ${errorMsg}</li>`;
                                    });
                                    failedDetailsHtml += '</ul>';
                                }
                                
                                // Show instructions if provided
                                if (response.instructions) {
                                    instructionsHtml = response.instructions;
                                }
                                
                                // Determine icon and title based on whether there are failures
                                const icon = hasFailures ? 'warning' : 'success';
                                const title = hasFailures ? 'Partial Success' : 'Success';
                                
                                Swal.fire({
                                    icon: icon,
                                    title: title,
                                    html: (response.message || 'Bulk Label purchase completed!') + downloadLink + summaryHtml + failedDetailsHtml + instructionsHtml,
                                    confirmButtonText: 'OK',
                                    width: hasFailures ? '800px' : '500px',
                                    customClass: {
                                        popup: 'swal-wide'
                                    }
                                }).then(() => {
                                    table.ajax.reload();
                                });
                            } else {
                                // Error response - show message and instructions
                                let errorHtml = response.message || 'Failed to buy bulk Label.';
                                if (response.instructions) {
                                    errorHtml += response.instructions;
                                }
                                
                                // Show failed details if available
                                if (response.failed_details && response.failed_details.length > 0) {
                                    errorHtml += '<br><br><strong>Error Details:</strong><ul style="text-align: left; margin-top: 10px; max-height: 300px; overflow-y: auto;">';
                                    response.failed_details.forEach(function(detail) {
                                        errorHtml += `<li style="margin-bottom: 8px;"><strong>Order #${detail.order_id}:</strong> ${detail.message || detail.error || 'Unknown error'}</li>`;
                                    });
                                    errorHtml += '</ul>';
                                }
                                
                                if (response.labels && response.labels.summary) {
                                    errorHtml += `<br><br><strong>Summary:</strong><br>
                                        Total Orders Processed: ${response.labels.summary.total_processed}<br>
                                        Successfully Processed: ${response.labels.summary.success_count}<br>
                                        Failed: ${response.labels.summary.failed_count}`;
                                }
                                
                                // Determine icon based on error type
                                let icon = 'error';
                                if (response.error_type === 'validation_error') {
                                    icon = 'warning';
                                } else if (response.error_type === 'concurrency_error') {
                                    icon = 'info';
                                }
                                
                                Swal.fire({
                                    icon: icon,
                                    title: 'Bulk Shipping Error',
                                    html: errorHtml,
                                    confirmButtonText: 'OK',
                                    width: '700px',
                                    customClass: {
                                        popup: 'swal-wide'
                                    }
                                });
                            }
                        },
                        error: function(xhr) {
                            $('#loader').hide();
                            $('body').removeClass('loader-active');
                            
                            let errorMessage = 'An error occurred while processing bulk Label.';
                            let instructions = '';
                            
                            if (xhr.responseJSON) {
                                errorMessage = xhr.responseJSON.message || errorMessage;
                                if (xhr.responseJSON.instructions) {
                                    instructions = xhr.responseJSON.instructions;
                                } else {
                                    // Default instructions for network/server errors
                                    instructions = '<div style="text-align: left; margin-top: 15px; padding: 15px; background-color: #f8d7da; border-radius: 5px; border-left: 4px solid #dc3545;">'
                                        + '<strong>What to do next?</strong><br>'
                                        + '1. Check your internet connection<br>'
                                        + '2. Wait a few moments and try again<br>'
                                        + '3. If the problem persists, contact technical support<br>'
                                        + '</div>';
                                }
                            }
                            
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                html: errorMessage + instructions,
                                confirmButtonText: 'OK',
                                width: '700px',
                                customClass: {
                                    popup: 'swal-wide'
                                }
                            });
                        }
                    });
                }
            });
        }
        $('.bulk-mark-ship-btn').on('click', function() {
    let selectedOrders = $('.order-checkbox-d:checked, .order-checkbox-o:checked').map(function() {
        return $(this).val();
    }).get();

    if (selectedOrders.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Selection Required',
            text: 'Please select at least one order to mark as shipped.',
            confirmButtonText: 'OK'
        });
        return;
    }

    Swal.fire({
        icon: 'info',
        title: 'Bulk Mark as Shipped',
        html: `
            Are you sure you want to mark ${selectedOrders.length} orders as shipped?
            <br><br>
            <div class="alert alert-warning small mt-2">
                <i class="bi bi-info-circle"></i> This action will update the shipping status of selected orders.
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes',
        cancelButtonText: 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#loader').show();
            $('body').addClass('loader-active');

            $.ajax({
                url: "{{ route('orders.bulk-mark-shipped') }}", 
                type: 'POST',
                data: {
                    order_ids: selectedOrders,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');

                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            html: response.message || `${selectedOrders.length} orders marked as shipped successfully.`,
                            confirmButtonText: 'OK'
                        }).then(() => {
                            table.ajax.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to mark orders as shipped.',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'An error occurred while marking orders as shipped.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        }
    });
});

       $('.bulk-update-dimensions-btn').on('click', function () {
    let selectedOrders = $('.order-checkbox-d:checked, .order-checkbox-o:checked').map(function () {
        return $(this).val();
    }).get();
    if (selectedOrders.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Selection Required',
            text: 'Please select at least one order to update dimensions.',
            confirmButtonText: 'OK'
        });
        return;
    }
    let height = $('#bulkHeight').val().trim();
    let length = $('#bulkLength').val().trim();
    let width = $('#bulkWidth').val().trim();
    let weight = $('#bulkWeight').val().trim();
    if (!height && !length && !width && !weight) {
        Swal.fire({
            icon: 'warning',
            title: 'Input Required',
            text: 'Please provide at least one dimension value to update.',
            confirmButtonText: 'OK'
        });
        return;
    }
    if ((height && (isNaN(height) || parseFloat(height) < 0)) ||
        (length && (isNaN(length) || parseFloat(length) < 0)) ||
        (width && (isNaN(width) || parseFloat(width) < 0)) ||
        (weight && (isNaN(weight) || parseFloat(weight) < 0))) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Input',
            text: 'Please enter valid positive numbers for dimensions.',
            confirmButtonText: 'OK'
        });
        return;
    }
    Swal.fire({
        icon: 'info',
        title: 'Update Bulk Dimensions',
        text: `Are you sure you want to update dimensions for ${selectedOrders.length} orders?`,
        showCancelButton: true,
        confirmButtonText: 'Yes',
        cancelButtonText: 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#loader').show();
            $('#loaderText').text('Updating Dimensions...'); // Ensure loader text is specific
            $('body').addClass('loader-active');
            $.ajax({
                url: "{{ route('orders.bulk-update-dimensions') }}",
                type: 'POST',
                data: {
                    order_ids: selectedOrders,
                    height: height || null,
                    length: length || null,
                    width: width || null,
                    weight: weight || null,
                    _token: '{{ csrf_token() }}'
                },
                success: function (response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    if (response.success) {
                        $('#bulkHeight').val('');
                        $('#bulkLength').val('');
                        $('#bulkWidth').val('');
                        $('#bulkWeight').val('');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: response.message || 'Dimensions updated successfully for selected orders!',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            table.ajax.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to update dimensions.',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function (xhr) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'An error occurred while updating dimensions.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        }
    });
});

    // Handle edit dimension button click
    $(document).on('click', '.edit-dimension-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const orderId = $(this).data('order-id');
        const field = $(this).data('field');
        const currentValue = $(this).data('value');
        const label = $(this).data('label');
        
        // Set modal values
        $('#editDimensionOrderId').val(orderId);
        $('#editDimensionField').val(field);
        $('#editDimensionLabel').text(label);
        $('#editDimensionValue').val(currentValue || '');
        
        // Show modal
        $('#editDimensionModal').modal('show');
        
        // Focus on input after modal is shown
        $('#editDimensionModal').on('shown.bs.modal', function() {
            $('#editDimensionValue').focus();
        });
    });
    
    // Handle save dimension from modal
    $('#saveDimensionBtn').on('click', function() {
        const orderId = $('#editDimensionOrderId').val();
        const field = $('#editDimensionField').val();
        const value = $('#editDimensionValue').val().trim();
        const label = $('#editDimensionLabel').text();
        
        // Validate input
        if (value !== '' && (isNaN(value) || parseFloat(value) < 0)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Input',
                text: `Please enter a valid number for ${label}.`,
                confirmButtonText: 'OK'
            });
            return;
        }
        
        // Convert empty string to null for proper backend handling
        const valueToSend = value === '' ? null : value;
        
        // Disable button during save
        $(this).prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: '{{ route("orders.update-dimensions") }}',
            type: 'POST',
            data: {
                order_id: orderId,
                field: field,
                value: valueToSend,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                $('#saveDimensionBtn').prop('disabled', false).text('Save');
                
                if (response.success) {
                    // Close modal
                    $('#editDimensionModal').modal('hide');
                    
                    // Show success message
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated',
                        text: `${label} updated successfully!`,
                        confirmButtonText: 'OK',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    // Reload table to show updated value
                    setTimeout(function() {
                        table.ajax.reload(null, false);
                    }, 100);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to update value.',
                        confirmButtonText: 'OK'
                    });
                }
            },
            error: function(xhr) {
                $('#saveDimensionBtn').prop('disabled', false).text('Save');
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: xhr.responseJSON?.message || 'An error occurred while updating the value.',
                    confirmButtonText: 'OK'
                });
            }
        });
    });
    
    // Reset modal when closed
    $('#editDimensionModal').on('hidden.bs.modal', function() {
        $('#editDimensionForm')[0].reset();
        $('#saveDimensionBtn').prop('disabled', false).text('Save');
    });
    
    // Handle recipient show/hide toggle
    $(document).on('click', '.toggle-recipient-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $btn = $(this);
        const orderId = $btn.data('order-id');
        const $recipientValue = $(`.recipient-value[data-order-id="${orderId}"]`);
        const $icon = $btn.find('i');
        
        if ($recipientValue.is(':visible')) {
            // Hide recipient
            $recipientValue.hide();
            $icon.removeClass('bi-eye-slash').addClass('bi-eye');
            $btn.attr('title', 'Show Recipient');
        } else {
            // Show recipient
            $recipientValue.show();
            $icon.removeClass('bi-eye').addClass('bi-eye-slash');
            $btn.attr('title', 'Hide Recipient');
        }
    });
    
        $('#shipmentTable').on('click', '.editable-cell', function() {
            let $cell = $(this);
            if ($cell.hasClass('editing-cell')) return;
            let $td = $cell;
            let originalValue = $td.text().trim() === '—' ? '' : $td.text().trim();
            let rowData = table.row($td.closest('tr')).data();
            let columnIndex = $td.index();
            let visibleColumns = table.columns().visible().toArray();
            let columnDefs = table.settings().init().columns;
            let visibleColumnIndex = 0;
            let actualColumnIndex = -1;
            for (let i = 0; i < visibleColumns.length; i++) {
                if (visibleColumns[i]) {
                    if (visibleColumnIndex === columnIndex) {
                        actualColumnIndex = i;
                        break;
                    }
                    visibleColumnIndex++;
                }
            }
            let field = columnDefs[actualColumnIndex].data;
            // Prevent editing of dimension columns - they now use modal
            if (['height_d', 'width_d', 'length_d', 'weight_d'].includes(field)) {
                Swal.fire({
                    icon: 'info',
                    title: 'Use Edit Button',
                    text: 'Please use the edit button to modify dimension values.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            // Only allow editing of weight (non-dimension), not the original H, W, L columns
            if (!['weight'].includes(field)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Column',
                    text: 'This column is not editable.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            // Double check - explicitly prevent editing of original dimension columns
            if (['height', 'width', 'length'].includes(field)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Column Not Editable',
                    text: 'H, W, and L columns are read-only. Please use H (DECL), W (DECL), or L (DECL) columns for editing.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            $td.addClass('editing-cell').html(`<input type="number" value="${originalValue}" min="0" step="0.01">`);
            let $input = $td.find('input');
            $input.focus();
            $input.on('blur keypress', function(e) {
                if (e.type === 'blur' || e.key === 'Enter') {
                    let newValue = $(this).val().trim();
                    $td.removeClass('editing-cell').text(newValue || '—');
                    if (newValue !== '' && (isNaN(newValue) || parseFloat(newValue) < 0)) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid Input',
                            text: `Please enter a valid number for ${field}.`,
                            confirmButtonText: 'OK'
                        });
                        $td.text(originalValue || '—');
                        return;
                    }
                    if (newValue !== originalValue) {
                        // Convert empty string to null for proper backend handling
                        let valueToSend = newValue === '' ? null : newValue;
                        $.ajax({
                            url: '{{ route("orders.update-dimensions") }}',
                            type: 'POST',
                            data: {
                                order_id: rowData.id,
                                field: field,
                                value: valueToSend,
                                _token: '{{ csrf_token() }}'
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Update the cell immediately with the new value
                                    $td.text(newValue || '—');
                                    // Then reload the table after a short delay to ensure DB is updated
                                    setTimeout(function() {
                                        table.ajax.reload(null, false);
                                    }, 100);
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Updated',
                                        text: `${field.charAt(0).toUpperCase() + field.slice(1)} updated successfully!`,
                                        confirmButtonText: 'OK',
                                        timer: 1500,
                                        showConfirmButton: false
                                    });
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: response.message || 'Failed to update value.',
                                        confirmButtonText: 'OK'
                                    });
                                    $td.text(originalValue || '—');
                                }
                            },
                            error: function(xhr) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: 'An error occurred while updating the value.',
                                    confirmButtonText: 'OK'
                                });
                                $td.text(originalValue || '—');
                            }
                        });
                    }
                }
            });
            $input.on('keypress', function(e) {
                if (e.key === 'Escape') {
                    $td.removeClass('editing-cell').text(originalValue || '—');
                }
            });
        });
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
        function fmtDate(d) {
            if (!d) return '—';
            const date = new Date(d);
            if (isNaN(date)) return '—';
            return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }
        function fmtMoney(v, symbol = '$') {
            if (v == null || v === '') return symbol + '0.00';
            const num = parseFloat(v);
            return symbol + (isNaN(num) ? '0.00' : num.toFixed(2));
        }
        $('#shipmentTable').on('click', '.recipient-link', function(e) {
            e.preventDefault();
            let orderId = $(this).data('order-id');
            const url = "{{ route('orders.details', ['orderId' => ':id']) }}".replace(':id', orderId);
            $('#orderDetailsContent').html('');
            $.ajax({
                url: url,
                type: 'GET',
                dataType: 'json',
                success: function(order) {
                    let contentHtml = '<div class="card border-0 shadow-sm p-4 fade-in" style="border-radius: 10px; background: #f9fafb;">';
                    if (order.order_number != null && order.order_number !== '') {
                        contentHtml += `
                            <div class="mb-4">
                                <h5 class="fw-bold mb-0 text-dark">Order #: <span class="text-primary">${order.order_number}</span></h5>
                            </div>
                            <hr class="my-3" style="border-color: #e9ecef;">
                        `;
                    }
                    let recipientHtml = '';
                    if (order.recipient_name != null && order.recipient_name !== '') {
                        recipientHtml += `<p class="mb-2 text-dark font-weight-medium">${order.recipient_name}</p>`;
                    }
                    const addr1 = order.ship_address1 || '';
                    const addr2 = order.ship_address2 || '';
                    const city = order.ship_city || '';
                    const state = order.ship_state || '';
                    const zip = order.ship_postal_code || '';
                    const country = order.ship_country || '';
                    const addressLine = [addr1, addr2].filter(Boolean).join(', ');
                    const cityLine = [city, state, zip].filter(Boolean).join(', ');
                    const fullAddress = [addressLine, cityLine, country].filter(Boolean).join('<br>');
                    if (fullAddress != null && fullAddress !== '') {
                        recipientHtml += `<p class="mb-2 text-dark font-weight-medium">${fullAddress}</p>`;
                    }
                    if (order.recipient_phone != null && order.recipient_phone !== '') {
                        recipientHtml += `<p class="mb-2"><strong>Phone:</strong> <span class="text-dark">${order.recipient_phone}</span></p>`;
                    }
                    if (order.recipient_email != null && order.recipient_email !== '') {
                        recipientHtml += `<p class="mb-0"><strong>Email:</strong> <span class="text-dark">${order.recipient_email}</span></p>`;
                    }
                    if (recipientHtml) {
                        contentHtml += `
                            <div class="mb-4">
                                <h6 class="fw-bold text-secondary mb-3">Recipient</h6>
                                ${recipientHtml}
                            </div>
                        `;
                    }
                    let itemsListHtml = `
                        <table class="table table-sm table-hover" style="background-color: #ffffff; border-radius: 10px; overflow: hidden; border: 1px solid #dee2e6; box-shadow: 0 3px 8px rgba(0,0,0,0.1);">
                            <thead style="background: linear-gradient(to right, #5a7bff, #8aa4ff); color: #fff; font-weight: 500;">
                                <tr>
                                    <th scope="col" class="ps-3 py-2" style="width: 50%; border-top: 0;">Item</th>
                                    <th scope="col" class="text-center py-2" style="width: 30%; border-top: 0;">SKU</th>
                                    <th scope="col" class="text-end pe-3 py-2" style="width: 20%; border-top: 0;">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    if (Array.isArray(order.items) && order.items.length > 0) {
                        order.items.forEach(item => {
                            itemsListHtml += `
                                <tr class="item-row" style="transition: all 0.2s;">
                                    <td class="ps-3 py-2 font-weight-medium">${item.product_name != null && item.product_name !== '' ? item.product_name : '—'}</td>
                                    <td class="text-center py-2 text-muted">${item.sku != null && item.sku !== '' ? item.sku : 'No SKU'}</td>
                                    <td class="text-end pe-3 py-2 font-weight-medium">${item.quantity != null && item.quantity !== '' ? item.quantity : 0}</td>
                                </tr>
                            `;
                        });
                    } else {
                        itemsListHtml += `
                            <tr>
                                <td colspan="3" class="text-center text-muted py-3">No items found for this order.</td>
                            </tr>
                        `;
                    }
                    itemsListHtml += `
                            </tbody>
                        </table>
                    `;
                    contentHtml += `
                        <div class="mb-4">
                            <h6 class="fw-bold text-secondary mb-3">Items</h6>
                            ${itemsListHtml}
                        </div>
                    `;
                    contentHtml += '</div>';
                    $('#orderDetailsContent').html(contentHtml);
                    setTimeout(() => $('#orderDetailsContent .card').addClass('show'), 10);
                    $('#orderDetailsModal').modal('show');
                },
                error: function(xhr) {
                    console.error('Failed to fetch order details', xhr);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'Failed to fetch order details.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $('#shipmentTable').on('click', '.edit-address-btn', function(e) {
            e.preventDefault();
            let order = $(this).data('order');
            if (typeof order === 'string') {
                try { order = JSON.parse(order); } catch (_) {}
            }
            $('#editOrderId').val(order.id || '');
            $('#editIsAddressVerified').val(order.is_address_verified || 0);
            $('#editRecipientName').val(order.recipient_name || '');
            $('#editShipAddress1').val(order.ship_address1 || '');
            $('#editShipAddress2').val(order.ship_address2 || '');
            $('#editShipCity').val(order.ship_city || '');
            $('#editShipState').val(order.ship_state || '');
            $('#editShipPostalCode').val(order.ship_postal_code || '');
            $('#editShipCountry').val(order.ship_country || '');
            if (order.is_address_verified === 1) {
                $('#verifyButtonContainer').hide();
            } else {
                $('#verifyButtonContainer').show();
            }
            $('#editAddressModal').modal('show');
        });
        $('#editAddressForm').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serialize();
            $('#loader').show();
            $('body').addClass('loader-active');
            $.ajax({
                url: "{{ route('orders.update-address') }}",
                type: 'POST',
                data: formData + '&_token=' + $('meta[name="csrf-token"]').attr('content'),
                success: function(response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    if (response.success) {
                        $('#editAddressModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: 'Shipping address updated successfully!',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            table.ajax.reload(null, false);
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to update address.',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'An error occurred while updating the address.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $('#verifyAddressBtn').on('click', function(e) {
            e.preventDefault();
            const recipientName = $('#editRecipientName').val().trim();
            const shipAddress1 = $('#editShipAddress1').val().trim();
            const shipCity = $('#editShipCity').val().trim();
            const shipState = $('#editShipState').val().trim();
            const shipPostalCode = $('#editShipPostalCode').val().trim();
            const shipCountry = $('#editShipCountry').val().trim();
            if (!recipientName || !shipAddress1 || !shipCity || !shipState || !shipPostalCode || !shipCountry) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'All address fields are required to verify the address.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            $('#loader').show();
            $('body').addClass('loader-active');
            const orderId = $('#editOrderId').val();
            $.ajax({
                url: '{{ route("orders.verify-address") }}',
                type: 'POST',
                data: {
                    order_id: orderId,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: 'Address verified successfully!',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            $('#verifyButtonContainer').hide();
                            table.ajax.reload(null, false);
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to verify address.',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'An error occurred while verifying the address.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $('#shipmentTable').on('click', '.edit-items-btn', function(e) {
            e.preventDefault();
            let orderId = $(this).data('order-id');
            $('#editItemsOrderId').val(orderId);
            $('#itemsList').html('<div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading items...</div>');
            $('#editItemsModal').modal('show');
            $.ajax({
                url: "{{ route('orders.details', ['orderId' => ':id']) }}".replace(':id', orderId),
                type: 'GET',
                dataType: 'json',
                success: function(order) {
                    let itemsHtml = '';
                    if (Array.isArray(order.items) && order.items.length > 0) {
                        order.items.forEach((item, index) => {
                            itemsHtml += `
                                <div class="mb-3 border p-3 rounded">
                                    <h6 class="mb-3">Item ${index + 1}: ${item.product_name || '—'}</h6>
                                    <input type="hidden" name="items[${index}][id]" value="${item.id || ''}">
                                    <div class="mb-2">
                                        <label class="form-label">SKU</label>
                                        <input type="text" class="form-control" name="items[${index}][sku]" value="${item.sku || ''}" required maxlength="255">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" class="form-control" name="items[${index}][quantity]" value="${item.quantity || 1}" required min="1" step="1">
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        itemsHtml = '<div class="text-center text-muted py-3">No items found for this order.</div>';
                    }
                    $('#itemsList').html(itemsHtml);
                },
                error: function(xhr) {
                    $('#itemsList').html('<div class="text-center text-danger py-3">Failed to load items.</div>');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'Failed to load order items.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $('#editItemsForm').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serialize();
            var orderId = $('#editItemsOrderId').val();
            $('#loader').show();
            $('body').addClass('loader-active');
            $.ajax({
                url: "{{ route('orders.update-items', ['orderId' => ':orderId']) }}".replace(':orderId', orderId),
                type: 'POST',
                data: formData + '&_token=' + '{{ csrf_token() }}',
                success: function(response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    if (response.success) {
                        $('#editItemsModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: 'Order items updated successfully!',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            table.ajax.reload(null, false);
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to update items.',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || 'An error occurred while updating the items.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
        $(document).on('click', '.mark-as-shipped-btn', function(e) {
    e.preventDefault();
    let $btn = $(this);
    let orderId = $btn.data('id');
    let orderNumber = $btn.data('order-number');
    let isMarked = $btn.data('marked') == 1;
    let action = isMarked ? 'Unmark as Shipped' : 'Mark as Shipped';
    let confirmText = isMarked ? `Are you sure you want to unmark order #${orderNumber} as shipped?` : `Are you sure you want to mark order #${orderNumber} as shipped?`;
    Swal.fire({
        icon: 'info',
        title: action,
        text: confirmText,
        showCancelButton: true,
        confirmButtonText: 'Yes',
        cancelButtonText: 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#loader').show();
            $('#loaderText').text(`${action}...`);
            $('body').addClass('loader-active');
            $.ajax({
                url: "{{ route('orders.mark-shipped', ':id') }}".replace(':id', orderId),
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    unmark: isMarked ? 1 : 0
                },
                success: function(response) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: `${action} successfully!`,
                            confirmButtonText: 'OK'
                        }).then(() => {
                            table.ajax.reload(null, false);
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || `Failed to ${action.toLowerCase()}.`,
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    $('#loader').hide();
                    $('body').removeClass('loader-active');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: xhr.responseJSON?.message || `An error occurred while ${action.toLowerCase()}.`,
                        confirmButtonText: 'OK'
                    });
                }
            });
        }
    });
});
        $(document).on('click', '.edit-carrier-btn', function () {
    // Get order ID and rate_type directly from data attributes
    let orderId = $(this).data('order-id');
    let rateType = $(this).data('rate-type'); // 'D' or 'O'
    
    // If not found, try to get from the table row
    if (!orderId) {
        const $row = $(this).closest('tr');
        if ($row.length) {
            try {
                const rowData = table.row($row).data();
                orderId = rowData?.id;
            } catch (e) {
                console.error('Error getting row data:', e);
            }
        }
    }
    
    // If rate_type not found, try to determine from which column the button was clicked
    if (!rateType) {
        const $row = $(this).closest('tr');
        if ($row.length) {
            const $cell = $(this).closest('td');
            const columnIndex = $cell.index();
            // Try to determine from column position or class
            if ($(this).closest('.best-rate-d-container').length) {
                rateType = 'D';
            } else if ($(this).closest('.best-rate-o-container').length) {
                rateType = 'O';
            }
        }
    }
    
    if (!orderId) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Order ID not found. Please try again.',
            confirmButtonText: 'OK'
        });
        return;
    }
    
    $('#carrierList').html(`
        <div class="d-flex flex-column align-items-center justify-content-center py-5 text-muted">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <small>Fetching best carrier options${rateType ? ' (' + rateType + ')' : ''}...</small>
        </div>
    `);
    $('#changeCarrierModal').modal('show');
    
    $.ajax({
        url: '{{ route("orders.get-carriers") }}',
        type: 'POST',
        timeout: 30000, // 30 second timeout
        data: {
            order_id: orderId,
            rate_type: rateType || null, // Pass rate_type to filter rates
            _token: '{{ csrf_token() }}'
        },
        success: function (response) {
            // Handle response with success field
            if (response.success === false) {
                $('#carrierList').html(`
                    <div class="text-center text-danger py-4">
                        <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>
                        ${response.message || 'Failed to load carriers'}
                    </div>
                `);
                return;
            }
            
            if (!response.rates || response.rates.length === 0) {
                $('#carrierList').html(`
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-truck fs-3 d-block mb-2"></i>
                        No carriers available for this order
                        <br><small class="text-muted mt-2">Please fetch rates first using the refresh button.</small>
                    </div>
                `);
                return;
            }
            // First, find the absolute cheapest rate from ALL rates (not just top 3 per carrier)
            // This ensures we get the true lowest rate across all carriers and services
            let overallCheapest = null;
            if (response.rates && response.rates.length > 0) {
                response.rates.forEach(rate => {
                    if (rate && rate.price !== undefined && rate.price !== null) {
                        if (!overallCheapest || parseFloat(rate.price) < parseFloat(overallCheapest.price)) {
                            overallCheapest = rate;
                        }
                    }
                });
            }
            
            // If no overall cheapest found, return error
            if (!overallCheapest) {
                $('#carrierList').html(`
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-truck fs-3 d-block mb-2"></i>
                        No valid rates found for this order
                        <br><small class="text-muted mt-2">Please fetch rates first using the refresh button.</small>
                    </div>
                `);
                return;
            }
            
            // Group rates by carrier
            const groupedRates = {};
            response.rates.forEach(rate => {
                if (rate && rate.carrier) {
                    if (!groupedRates[rate.carrier]) {
                        groupedRates[rate.carrier] = [];
                    }
                    groupedRates[rate.carrier].push(rate);
                }
            });
            
            // For each group, sort by price and take top 3
            // But ensure the overall cheapest rate is always included even if it's not in top 3
            const processedGroups = {};
            Object.keys(groupedRates).forEach(carrier => {
                // Deduplicate: Keep only one rate per service+price combination (prefer cheaper source if same price)
                const uniqueRates = {};
                groupedRates[carrier].forEach(rate => {
                    if (!rate || !rate.service || rate.price === undefined) return;
                    const key = `${rate.service}|${parseFloat(rate.price || 0).toFixed(2)}`;
                    if (!uniqueRates[key] || 
                        (rate.source && rate.source.toLowerCase() === 'shipstation' && 
                         uniqueRates[key].source && uniqueRates[key].source.toLowerCase() !== 'shipstation')) {
                        // Prefer ShipStation over other sources if price is same
                        uniqueRates[key] = rate;
                    }
                });
                
                const sortedGroup = Object.values(uniqueRates)
                    .sort((a, b) => parseFloat(a.price || 0) - parseFloat(b.price || 0));
                
                // Take top 3, but ensure overall cheapest is included if it belongs to this carrier
                let topRates = sortedGroup.slice(0, 3);
                if (overallCheapest && overallCheapest.carrier === carrier) {
                    // Check if overall cheapest is already in top 3
                    const isInTop3 = topRates.some(r => r.id === overallCheapest.id);
                    if (!isInTop3) {
                        // Replace the 3rd item with overall cheapest if it's cheaper
                        if (topRates.length >= 3 && parseFloat(overallCheapest.price || 0) < parseFloat(topRates[2].price || 0)) {
                            topRates[2] = overallCheapest;
                        } else if (topRates.length < 3) {
                            topRates.push(overallCheapest);
                        }
                        // Re-sort after adding overall cheapest
                        topRates.sort((a, b) => parseFloat(a.price || 0) - parseFloat(b.price || 0));
                    }
                }
                
                if (topRates.length > 0 && topRates[0].price !== undefined) {
                    processedGroups[carrier] = {
                        rates: topRates,
                        cheapestPrice: parseFloat(topRates[0].price || 0)
                    };
                }
            });
            
            // Sort groups by cheapest price
            const sortedCarriers = Object.keys(processedGroups)
                .sort((a, b) => processedGroups[a].cheapestPrice - processedGroups[b].cheapestPrice);
            
            // Build HTML
            let html = '<div class="list-group">';
            sortedCarriers.forEach((carrier, carrierIndex) => {
                const group = processedGroups[carrier];
                const numServices = group.rates.length;
                // Group header
                html += `
                    <div class="carrier-group-header list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>${carrier}</span>
                            <small class="text-muted">Top ${numServices} service${numServices > 1 ? 's' : ''}</small>
                        </div>
                    </div>
                `;
                // List items
                group.rates.forEach((rate, serviceIndex) => {
                    if (!rate || !rate.id) return; // Skip invalid rates
                    
                    const groupCheapest = serviceIndex === 0;
                    // Check if this rate is the overall cheapest (compare by id and price to be safe)
                    const overallCheapestFlag = overallCheapest && (
                        (rate.id === overallCheapest.id) || 
                        (parseFloat(rate.price || 0) === parseFloat(overallCheapest.price || 0) && 
                         rate.carrier === overallCheapest.carrier && 
                         rate.service === overallCheapest.service)
                    );
                    // ✅ Default checked: Always select the overall cheapest rate (lowest price)
                    const checked = overallCheapestFlag ? 'checked' : '';
                    const ratePrice = parseFloat(rate.price || 0);
                    const rateCarrier = (rate.carrier || 'Unknown').replace(/"/g, '&quot;');
                    const rateService = (rate.service || 'Unknown').replace(/"/g, '&quot;');
                    const rateSource = (rate.source || 'Unknown').replace(/"/g, '&quot;');
                    
                    html += `
                        <label class="list-group-item list-group-item-action carrier-group-item d-flex justify-content-between align-items-center cursor-pointer">
                            <div class="d-flex align-items-start">
                                <input class="form-check-input me-3 mt-1 select-carrier-radio"
                                       type="radio"
                                       name="selectedCarrier"
                                       id="carrier_${carrierIndex}_${serviceIndex}"
                                       data-order-id="${orderId}"
                                       data-carrier="${rateCarrier}"
                                       data-service="${rateService}"
                                       data-rate-id="${rate.id}"
                                       data-price="${ratePrice}"
                                       data-source="${rateSource}"
                                       ${checked}>
                                <div>
                                    <div class="fw-bold text-truncate" style="max-width: 200px;">${rateService}</div>
                                    <div class="small text-muted">${rateSource}</div>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-primary fs-6 px-3 py-2">$${ratePrice.toFixed(2)}</span>
                                ${groupCheapest ? `<div class="small text-success mt-1"><i class="bi bi-check-circle"></i> Cheapest for ${rateCarrier}</div>` : ''}
                                ${overallCheapestFlag ? `<div class="small text-success mt-1"><i class="bi bi-star-fill"></i> Overall Cheapest</div>` : ''}
                            </div>
                        </label>
                    `;
                });
            });
            html += '</div>';
            $('#carrierList').html(html);
        },
        error: function (xhr, status, error) {
            console.error('Error loading carriers:', {
                xhr: xhr,
                status: status,
                error: error,
                responseText: xhr.responseText
            });
            
            let errorMessage = 'Failed to load carriers. Please try again.';
            if (status === 'timeout') {
                errorMessage = 'Request timed out. Please try again.';
            } else if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMessage = xhr.responseJSON.message;
            } else if (xhr.status === 0) {
                errorMessage = 'Network error. Please check your connection.';
            }
            
            $('#carrierList').html(`
                <div class="text-center text-danger py-4">
                    <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>
                    ${errorMessage}
                    <br><small class="text-muted mt-2">If rates are not available, please fetch them first using the refresh button.</small>
                </div>
            `);
        },
        complete: function() {
            // Always clear any loading indicators
            // This ensures we don't get stuck in loading state
        }
    });
});
        $(document).on('click', '.fetch-rate-o-btn', function() {
    const $btn = $(this);
    const orderId = $btn.data('order-id');
    const length = parseFloat($btn.data('length')) || 0;
    const width = parseFloat($btn.data('width')) || 0;
    const height = parseFloat($btn.data('height')) || 0;
    const weight = parseFloat($btn.data('weight')) || 0;
    const shipToZip = $btn.data('ship-to-zip') || '';
    const shipToState = $btn.data('ship-to-state') || '';
    const shipToCity = $btn.data('ship-to-city') || '';
    const shipToCountry = $btn.data('ship-to-country') || 'US';
    
    if (length === 0 || width === 0 || height === 0 || weight === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Data',
            text: 'Please ensure L, W, H, and WT values are available',
            confirmButtonText: 'OK'
        });
        return;
    }
    
    // Disable button and show loading
    $btn.prop('disabled', true);
    const $container = $btn.closest('.best-rate-o-container');
    const originalHtml = $container.html();
    $container.html(`
        <span class="text-muted"><i class="spinner-border spinner-border-sm"></i> Fetching rates...</span>
    `);
    
    $.ajax({
        url: '{{ route("orders.fetch-rate-o") }}',
        type: 'POST',
        data: {
            order_id: orderId,
            length: length,
            width: width,
            height: height,
            weight: weight,
            ship_to_zip: shipToZip,
            ship_to_state: shipToState,
            ship_to_city: shipToCity,
            ship_to_country: shipToCountry,
            _token: '{{ csrf_token() }}'
        },
        success: function(response) {
            if (response.success && response.rate) {
                const rate = response.rate;
                // Update the row data in DataTable first to get updated row data
                const row = table.row(function(idx, data) {
                    return data.id == orderId;
                });
                // Update container immediately with fetched rate
                $container.html(`
                    <span>${rate.carrier}${rate.service ? ' - ' + rate.service : ''} $${parseFloat(rate.price || 0).toFixed(2)}</span>
                    <button
                        class="btn btn-sm btn-link text-primary ms-2 fetch-rate-o-btn"
                        data-order-id="${orderId}"
                        data-length="${length}"
                        data-width="${width}"
                        data-height="${height}"
                        data-weight="${weight}"
                        data-ship-to-zip="${shipToZip}"
                        data-ship-to-state="${shipToState}"
                        data-ship-to-city="${shipToCity}"
                        data-ship-to-country="${shipToCountry}"
                        title="Refresh Rate">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <button
                        class="btn btn-sm btn-link text-primary ms-2 edit-carrier-btn"
                        data-order-id="${orderId}"
                        data-rate-type="O"
                        title="Change Carrier (DIM)">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                `);
                
                // Reload the table from server to get fresh data with correct best_rate_d and best_rate_o
                // This ensures both columns show the correct rates from database with proper rate_type separation
                setTimeout(function() {
                    // Reload entire table to get fresh data from server
                    // This ensures best_rate_d and best_rate_o are loaded separately from database
                    table.ajax.reload(null, false);
                }, 500); // Small delay to ensure database is updated
                
                // Enable the checkbox for Best Rate (DIM) if conditions are met

                // Enable the checkbox for Best Rate (DIM) if conditions are met
                const $checkboxO = $(`.order-checkbox-o[value="${orderId}"]`);
                if ($checkboxO.length && rowData) {
                    const invValue = parseFloat(rowData.inv) || 0;
                    const hasValidRecipient = rowData.recipient_name && rowData.recipient_name.trim() !== '';
                    if (invValue > 0 && hasValidRecipient && rate && rate.carrier) {
                        // Replace the info icon with checkbox if it exists
                        const $cell = $checkboxO.closest('td');
                        if ($cell.find('.info-icon').length) {
                            $cell.html(`<input type="checkbox" class="order-checkbox-o" value="${orderId}" data-order-id="${orderId}">`);
                        }
                    }
                }
            } else {
                $container.html(`
                    <span class="text-danger">${response.message || 'Failed to fetch rate'}</span>
                    <button
                        class="btn btn-sm btn-link text-primary ms-2 fetch-rate-o-btn"
                        data-order-id="${orderId}"
                        data-length="${length}"
                        data-width="${width}"
                        data-height="${height}"
                        data-weight="${weight}"
                        data-ship-to-zip="${shipToZip}"
                        data-ship-to-state="${shipToState}"
                        data-ship-to-city="${shipToCity}"
                        data-ship-to-country="${shipToCountry}"
                        title="Retry">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                `);
            }
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            const errorMsg = xhr.responseJSON?.message || 'Failed to fetch rate';
            // Get row data for edit-carrier button
            const row = table.row(function(idx, data) {
                return data.id == orderId;
            });
            let rowData = row.length ? row.data() : null;
            
            $container.html(`
                <span class="text-danger">${errorMsg}</span>
                <button
                    class="btn btn-sm btn-link text-primary ms-2 fetch-rate-o-btn"
                    data-order-id="${orderId}"
                    data-length="${length}"
                    data-width="${width}"
                    data-height="${height}"
                    data-weight="${weight}"
                    data-ship-to-zip="${shipToZip}"
                    data-ship-to-state="${shipToState}"
                    data-ship-to-city="${shipToCity}"
                    data-ship-to-country="${shipToCountry}"
                    title="Retry">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button
                    class="btn btn-sm btn-link text-primary ms-2 edit-carrier-btn"
                    data-order='${rowData ? JSON.stringify(rowData).replace(/'/g,"&apos;") : ''}'
                    title="Change Carrier">
                    <i class="bi bi-pencil-square"></i>
                </button>
            `);
        }
    });
});
        $(document).on('click', '.fetch-rate-d-btn', function() {
    const $btn = $(this);
    const orderId = $btn.data('order-id');
    const lengthD = parseFloat($btn.data('length')) || 0;
    const widthD = parseFloat($btn.data('width')) || 0;
    const heightD = parseFloat($btn.data('height')) || 0;
    const weightD = parseFloat($btn.data('weight')) || 0;
    const shipToZip = $btn.data('ship-to-zip') || '';
    const shipToState = $btn.data('ship-to-state') || '';
    const shipToCity = $btn.data('ship-to-city') || '';
    const shipToCountry = $btn.data('ship-to-country') || 'US';
    
    if (lengthD === 0 || widthD === 0 || heightD === 0 || weightD === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Data',
            text: 'Please ensure L (DECL), W (DECL), H (DECL), and WT (DECL) values are available',
            confirmButtonText: 'OK'
        });
        return;
    }
    
    // Disable button and show loading
    $btn.prop('disabled', true);
    const $container = $btn.closest('.best-rate-d-container');
    const originalHtml = $container.html();
    $container.html(`
        <span class="text-muted"><i class="spinner-border spinner-border-sm"></i> Fetching rates...</span>
    `);
    
    $.ajax({
        url: '{{ route("orders.fetch-rate-d") }}',
        type: 'POST',
        data: {
            order_id: orderId,
            length: lengthD,
            width: widthD,
            height: heightD,
            weight: weightD,
            ship_to_zip: shipToZip,
            ship_to_state: shipToState,
            ship_to_city: shipToCity,
            ship_to_country: shipToCountry,
            _token: '{{ csrf_token() }}'
        },
        success: function(response) {
            if (response.success && response.rate) {
                const rate = response.rate;
                const row = table.row(function(idx, data) {
                    return data.id == orderId;
                });
                let rowData = row.length ? row.data() : null;
                
                // Update only best_rate_d, don't touch best_rate_o or default_carrier
                if (row.length && rowData) {
                    rowData.best_rate_d = rate;
                    // Don't update default_carrier - keep it separate from best_rate_d
                    row.data(rowData);
                }
                
                // Update the container display
                $container.html(`
                    <span>${rate.carrier}${rate.service ? ' - ' + rate.service : ''} $${parseFloat(rate.price || 0).toFixed(2)}</span>
                    <button
                        class="btn btn-sm btn-link text-primary ms-2 fetch-rate-d-btn"
                        data-order-id="${orderId}"
                        data-length="${lengthD}"
                        data-width="${widthD}"
                        data-height="${heightD}"
                        data-weight="${weightD}"
                        data-ship-to-zip="${shipToZip}"
                        data-ship-to-state="${shipToState}"
                        data-ship-to-city="${shipToCity}"
                        data-ship-to-country="${shipToCountry}"
                        title="Refresh Rate">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <button
                        class="btn btn-sm btn-link text-primary ms-2 edit-carrier-btn"
                        data-order-id="${orderId}"
                        data-rate-type="D"
                        title="Change Carrier (DECL)">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                `);
                
                // Reload the row from server to get fresh data with correct best_rate_d and best_rate_o
                // This ensures both columns show the correct rates from database
                setTimeout(function() {
                    // Reload the entire table to get fresh data from server
                    // This ensures best_rate_d and best_rate_o are loaded separately from database
                    table.ajax.reload(null, false);
                }, 500); // Small delay to ensure database is updated
            } else {
                $container.html(`
                    <span class="text-danger">${response.message || 'Failed to fetch rate'}</span>
                    <button
                        class="btn btn-sm btn-link text-primary ms-2 fetch-rate-d-btn"
                        data-order-id="${orderId}"
                        data-length="${lengthD}"
                        data-width="${widthD}"
                        data-height="${heightD}"
                        data-weight="${weightD}"
                        data-ship-to-zip="${shipToZip}"
                        data-ship-to-state="${shipToState}"
                        data-ship-to-city="${shipToCity}"
                        data-ship-to-country="${shipToCountry}"
                        title="Retry">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                `);
            }
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            const errorMsg = xhr.responseJSON?.message || 'Failed to fetch rate';
            $container.html(`
                <span class="text-danger">${errorMsg}</span>
                <button
                    class="btn btn-sm btn-link text-primary ms-2 fetch-rate-d-btn"
                    data-order-id="${orderId}"
                    data-length="${lengthD}"
                    data-width="${widthD}"
                    data-height="${heightD}"
                    data-weight="${weightD}"
                    data-ship-to-zip="${shipToZip}"
                    data-ship-to-state="${shipToState}"
                    data-ship-to-city="${shipToCity}"
                    data-ship-to-country="${shipToCountry}"
                    title="Retry">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            `);
        }
    });
});
        $(document).on('change', '.select-carrier-radio', function() {
            let $radio = $(this);
            let orderId = $radio.data('order-id');
            let carrier = $radio.data('carrier');
            let service = $radio.data('service');
            let rate_id = $radio.data('rate-id');
            let price = $radio.data('price');
            $.ajax({
                url: '{{ route("orders.updateCarrier") }}',
                type: 'POST',
                data: {
                    order_id: orderId,
                    carrier: carrier,
                    service: service,
                    price: price,
                    rate_id: rate_id,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Carrier Updated',
                            text: `${carrier} - ${service} selected successfully at $${parseFloat(price).toFixed(2)}`,
                            timer: 2000,
                            showConfirmButton: false
                        });
                        $('#changeCarrierModal').modal('hide');
                        table.ajax.reload(null, false);
                    } else {
                        Swal.fire('Error', response.message || 'Failed to update carrier', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to update carrier', 'error');
                }
            });
        });
        $('#shipmentTable').on('click', '.confirm-shipment-btn', function() {
            const orderId = $(this).data('order-id');
            Swal.fire({
                icon: 'info',
                title: 'Confirm Shipment',
                text: `Are you sure you want to confirm shipment for order #${orderId}?`,
                showCancelButton: true,
                confirmButtonText: 'Yes',
                cancelButtonText: 'No'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: `/admin/orders/${orderId}/confirm-shipment`,
                        type: 'POST',
                        data: { _token: '{{ csrf_token() }}' },
                        success: function(response) {
                            Swal.fire('Success', 'Shipment confirmed successfully!', 'success');
                            table.ajax.reload();
                        },
                        error: function(xhr) {
                            Swal.fire('Error', 'Failed to confirm shipment.', 'error');
                        }
                    });
                }
        });
    });

    // Overdue Count History Chart
    let overdueChart = null;
    
    function loadOverdueHistory(days = 30) {
        const ctx = document.getElementById('overdueHistoryChart');
        if (!ctx) {
            return;
        }
        
        $.ajax({
            url: '{{ route("orders.overdue.history") }}',
            method: 'GET',
            data: { days: days },
            success: function(data) {
                const labels = data.map(item => item.date);
                const counts = data.map(item => item.count);
                
                if (overdueChart) {
                    overdueChart.destroy();
                }
                
                overdueChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Overdue Count',
                            data: counts,
                            borderColor: 'rgb(220, 53, 69)',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            },
            error: function(xhr) {
                console.error('Error loading overdue history:', xhr);
            }
        });
    }
    
    // Load chart when modal is shown
    $('#overdueHistoryModal').on('shown.bs.modal', function() {
        const days = $('#historyDays').val();
        loadOverdueHistory(days);
    });
    
    // Reload chart when period changes
    $('#historyDays').on('change', function() {
        const days = $(this).val();
        loadOverdueHistory(days);
    });

    // Copy order number to clipboard functionality
    $(document).on('click', '.copy-order-number-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const orderNumber = $(this).data('order-number');
        
        if (!orderNumber) {
            return;
        }
        
        // Use the Clipboard API if available
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(orderNumber).then(function() {
                // Show feedback
                const $btn = $(e.currentTarget);
                const originalHtml = $btn.html();
                $btn.html('<i class="fas fa-check" style="font-size: 12px; display: block; line-height: 1;"></i>');
                    $btn.css({
                        'color': '#fff',
                        'background': '#28a745',
                        'border': 'none'
                    });
                
                setTimeout(function() {
                    $btn.html(originalHtml);
                    $btn.css({
                        'color': '#0d6efd',
                        'background': 'transparent',
                        'border': 'none'
                    });
                }, 1000);
            }).catch(function(err) {
                console.error('Failed to copy:', err);
                // Fallback to older method
                fallbackCopyToClipboard(orderNumber, e.currentTarget);
            });
        } else {
            // Fallback for older browsers
            fallbackCopyToClipboard(orderNumber, e.currentTarget);
        }
    });
    
    // Fallback copy function for older browsers
    function fallbackCopyToClipboard(text, buttonElement) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                const $btn = $(buttonElement);
                const originalHtml = $btn.html();
                $btn.html('<i class="fas fa-check" style="font-size: 12px; display: block; line-height: 1;"></i>');
                    $btn.css({
                        'color': '#fff',
                        'background': '#28a745',
                        'border': 'none'
                    });
                
                setTimeout(function() {
                    $btn.html(originalHtml);
                    $btn.css({
                        'color': '#0d6efd',
                        'background': 'transparent',
                        'border': 'none'
                    });
                }, 1000);
            }
        } catch (err) {
            console.error('Fallback copy failed:', err);
        }
        
        document.body.removeChild(textArea);
    }

});
</script>
@endsection
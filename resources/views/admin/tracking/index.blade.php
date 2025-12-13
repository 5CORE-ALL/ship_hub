@extends('admin.layouts.admin_master')
@section('title', 'Valid Tracking Rate Dashboard')
@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4">Valid Tracking Rate Dashboard</h2>
            
            {{-- Overall Stats Badge - Matching Green Box Design --}}
            @if($results->count() > 0)
                @php
                    $totalOrders = $results->sum('total_orders');
                    $totalValid = $results->sum('valid_tracking');
                    $totalInvalid = $results->sum('invalid_tracking');
                    $overallRate = $totalOrders > 0 ? round(($totalValid / $totalOrders) * 100, 1) : 0;
                    $statusClass = $overallRate >= 95 ? 'success' : 'warning';
                @endphp
                <div class="bg-success text-white p-4 rounded mb-4 d-flex align-items-center justify-content-between shadow" style="background: linear-gradient(135deg, #28a745, #20c997);">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-check-circle me-2"></i>
                            <h4 class="mb-0">Valid Tracking Rate</h4>
                        </div>
                        <div class="h1 mb-2 fw-bold">{{ $overallRate }}%</div>
                        <small class="text-white-50">{{ $totalValid }} of {{ $totalOrders }} shipments have valid tracking</small>
                        <div class="mt-2 p-2 bg-dark bg-opacity-50 rounded">
                            <small class="text-light">Last 30 days performance</small>
                        </div>
                    </div>
                    <div class="text-end">
                        <i class="fas fa-chart-line fa-3x opacity-75"></i>
                    </div>
                </div>
            @else
                <div class="alert alert-info mb-4">
                    No data available. Run <code>php artisan ebay:vtr-fetch</code> to fetch data.
                </div>
            @endif

            {{-- Data Table - Matching Dark Theme with Icons --}}
            <div class="card shadow">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0">Marketplace Tracking Stats</h5>
                </div>
                <div class="card-body p-0">
                    @if($results->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-hover table-striped table-dark mb-0">
                                <thead>
                                    <tr>
                                        <th>Marketplace</th>
                                        <th>Total Orders</th>
                                        <th>Valid Tracking</th>
                                        <th>Invalid Tracking</th>
                                        <th>Current Rate</th>
                                        <th>Allowed Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($results as $stat)
                                        @php
                                            $statusClass = ($stat->valid_tracking_rate >= $stat->allowed_rate) ? 'success' : 'danger';
                                            $iconClass = match($stat->marketplace_name) {
                                                'ebay1' => 'fab fa-ebay',
                                                'ebay2' => 'fab fa-ebay',
                                                'ebay3' => 'fab fa-ebay',
                                                'amazon' => 'fab fa-amazon',
                                                default => 'fas fa-store'
                                            };
                                        @endphp
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-between">
                                                    <div class="d-flex align-items-center">
                                                        <i class="{{ $iconClass }} me-2 text-primary fs-4"></i>
                                                        <span class="fw-bold">{{ $stat->marketplace_name }}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>{{ $stat->total_orders }}</td>
                                            <td>
                                                <span class="badge bg-{{ $statusClass }} fs-6">{{ $stat->valid_tracking }}</span>
                                            </td>
                                            <td class="text-danger fw-bold">{{ $stat->invalid_tracking }}</td>
                                            <td>
                                                <span class="badge bg-{{ $statusClass }} px-3 py-2">{{ number_format($stat->valid_tracking_rate, 1) }}%</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">{{ $stat->allowed_rate }}%</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-secondary">
                                    <tr>
                                        <th>Total</th>
                                        <th>{{ $totalOrders }}</th>
                                        <th>{{ $totalValid }}</th>
                                        <th>{{ $totalInvalid }}</th>
                                        <th>{{ number_format($overallRate, 1) }}%</th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @else
                        <p class="text-center text-muted py-4">No tracking stats found.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
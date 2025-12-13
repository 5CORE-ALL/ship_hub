@extends('admin.layouts.admin_master')

@section('title', 'Store Connect')

@section('content')
<style>
    .sales-channel-table {
        width: 100%;
        border-collapse: collapse;
        font-family: Arial, sans-serif;
        font-size: 14px;
        background-color: #fff;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    .sales-channel-table th,
    .sales-channel-table td {
        padding: 12px 15px;
        text-align: left;
    }

    .sales-channel-table thead {
        background-color: #2c3e50;
        color: #fff;
        font-weight: bold;
    }

    .sales-channel-table tbody tr {
        border-bottom: 1px solid #e0e0e0;
    }

    .sales-channel-table tbody tr:nth-child(even) {
        background-color: #f9f9f9;
    }

    .sales-channel-table tbody tr:hover {
        background-color: #f1f7ff;
        transition: 0.2s;
    }

    .sales-channel-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
        color: #fff;
    }

    .sales-channel-badge.amazon {
        background-color: #ff9900;
    }

    .sales-channel-badge.flipkart {
        background-color: #2874f0;
    }

    .sales-channel-badge.shopify {
        background-color: #96bf48;
    }

    .sales-channel-badge.ebay {
        background-color: #e53238;
    }
    .sales-channel-card:hover {
            border-color: #0d6efd;
            box-shadow: 0px 3px 10px rgba(0,0,0,0.1);
            cursor: pointer;
    }
</style>
<div class="p-4">
    {{-- Page Title --}}
    <h2 class="mb-1" style="font-weight:600;">Store Setup</h2>
    <hr>

    {{-- Description --}}
    <p style="max-width: 800px;">
        Connect an unlimited number of stores (including shopping carts, marketplaces, ERPs, etc) to begin importing your orders. 
        Once connected, you can customise each store’s settings, including import frequency, email and packing slip branding, and much more. 
        <a href="#" class="text-primary">Learn more about store settings.</a>
    </p>

    {{-- Checkbox & Button --}}
    <div class="d-flex justify-content-between align-items-center mb-3" style="max-width: 800px;">
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="showInactive">
            <label class="form-check-label" for="showInactive">Show Inactive Stores</label>
        </div>
        <button class="btn btn-success" style="background-color:#00663d; border-color:#00663d;" data-bs-toggle="modal" data-bs-target="#connectStoreModal">
            Connect a Store
        </button>
    </div>

    {{-- Stores Table --}}
    <div style="max-width: 800px;">
        <table class="table table-bordered mb-0">
            <thead class="bg-light">
                <tr>
                    <th>Store Name</th>
                    <th>Last Modified</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

{{-- Modal for Connecting a Store (Existing) --}}
<div class="modal fade" id="connectStoreModal" tabindex="-1" aria-labelledby="connectStoreModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="connectStoreModalLabel">Connect a Store</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="text" id="storeSearch" class="form-control mb-3" placeholder="Search store or marketplace...">

                <h6 class="mb-3">Select your store or marketplace to begin</h6>

                {{-- Sales Channels Grid --}}
                <div class="row g-3" id="salesChannelGrid">
                    @foreach($salesChannels as $channel)
                        <div class="col-md-3 col-sm-4 col-6 sales-channel-item" data-name="{{ strtolower($channel->name) }}">
                            <div class="border rounded text-center h-100 p-3 position-relative sales-channel-card" 
                                 data-bs-toggle="tooltip" 
                                 data-bs-placement="bottom" 
                                 title="{{ $channel->description ?? '' }}">
                                {{-- Beta Tag --}}
                                @if($channel->status === 'beta')
                                    <span class="badge bg-secondary position-absolute" style="top:8px; left:8px;">Beta</span>
                                @endif

                                {{-- Logo --}}
                                <img src="{{ asset('storage/selling_channels/' . $channel->logo) }}" 
                                     alt="{{ $channel->name }}" 
                                     class="img-fluid mb-2 channel-logo" 
                                     style="height:60px; object-fit:contain;" 
                                     data-logo="{{ asset('storage/selling_channels/' . $channel->logo) }}">

                                {{-- Name --}}
                                <h6 class="fw-bold">{{ $channel->name }}</h6>

                                {{-- Connect Button --}}
                                <button class="btn btn-outline-primary btn-sm mt-2 connect-channel" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="{{ strtolower($channel->name) === 'amazon' ? '#amazonConnectModal' : '#connectStoreModal' }}" 
                                        data-bs-dismiss="{{ strtolower($channel->name) === 'amazon' ? 'modal' : '' }}" 
                                        data-prev-modal="#connectStoreModal" 
                                        data-channel-logo="{{ asset('storage/selling_channels/' . $channel->logo) }}">
                                    Connect
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

{{-- New Modal for Amazon Store Connection (Increased Size) --}}
<div class="modal fade" id="amazonConnectModal" tabindex="-1" aria-labelledby="amazonConnectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl"> <!-- Increased to modal-xl for larger size -->
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="amazonConnectModalLabel">Set Up Store Connection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div>
                    <img src="" id="dynamic-channel-logo" alt="Channel Logo" class="mb-3" style="height: 40px;">
                    <p>If you don't currently sell on Amazon click <a href="https://sell.amazon.com/?ld=rpussoa-Auctane" target="_blank">here</a> to learn more and get started today.</p>
                    <p><em>For more detailed Amazon connection instructions, including screenshots, check out our <a href="https://help.shipstation.com/hc/en-us/articles/360026140891-Amazon-Marketplace" target="_blank">support article</a></em></p>
                    <h6>Follow the steps to get connected:</h6>
                    <div class="mb-3">
                        <label for="amazonOrderSource" class="form-label">Amazon Order Source *</label>
                        <select class="form-select" id="amazonOrderSource">
                            <option value="">Amazon Order Source</option>
                            <option value="Amazon.com (United States)">Amazon.com (United States)</option>
                            <option value="Amazon.com.au (Australia)">Amazon.com.au (Australia)</option>
                            <option value="Amazon.ca (Canada)">Amazon.ca (Canada)</option>
                            <option value="Amazon.de (Germany)">Amazon.de (Germany)</option>
                            <option value="Amazon.es (Spain)">Amazon.es (Spain)</option>
                            <option value="Amazon.fr (France)">Amazon.fr (France)</option>
                            <option value="Amazon.it (Italy)">Amazon.it (Italy)</option>
                            <option value="Amazon.co.jp (Japan)">Amazon.co.jp (Japan)</option>
                            <option value="Amazon.com.mx (Mexico)">Amazon.com.mx (Mexico)</option>
                            <option value="Amazon.co.uk (United Kingdom)">Amazon.co.uk (United Kingdom)</option>
                            <option value="Amazon.com.br (Brazil)">Amazon.com.br (Brazil)</option>
                            <option value="Amazon.com.be (Belgium)">Amazon.com.be (Belgium)</option>
                            <option value="Amazon.nl (Netherlands)">Amazon.nl (Netherlands)</option>
                            <option value="Amazon.se (Sweden)">Amazon.se (Sweden)</option>
                            <option value="Amazon.pl (Poland)">Amazon.pl (Poland)</option>
                            <option value="Amazon.eg (Egypt)">Amazon.eg (Egypt)</option>
                            <option value="Amazon.com.tr (Turkey)">Amazon.com.tr (Turkey)</option>
                            <option value="Amazon.sa (Saudi Arabia)">Amazon.sa (Saudi Arabia)</option>
                            <option value="Amazon.ae (United Arab Emirates)">Amazon.ae (United Arab Emirates)</option>
                            <option value="Amazon.in (India)">Amazon.in (India)</option>
                            <option value="Amazon.sg (Singapore)">Amazon.sg (Singapore)</option>
                            <option value="Amazon.ie (Ireland)">Amazon.ie (Ireland)</option>
                        </select>
                        <small class="text-danger">This is a required property</small>
                    </div>
                    <div class="mb-3">
                        <p>1. Choose an Amazon order source. Repeat this setup process to add multiple Amazon order sources.</p>
                    </div>
                    <div class="mb-3">
                        <label for="productIdentifier" class="form-label">Product Identifier *</label>
                        <select class="form-select" id="productIdentifier">
                            <option value="">Product Identifier</option>
                            <option value="Use SKU">Use SKU</option>
                            <option value="Use the ASIN">Use the ASIN</option>
                        </select>
                        <small class="text-danger">This is a required property</small>
                    </div>
                    <div class="mb-3">
                        <p>2. Choose how ShipStation should identify your Amazon products: either by SKU or by Amazon ASIN. Most merchants pick SKU, so go with that if you're not sure.</p>
                    </div>
                    <div class="mb-3">
                        <p>3. Click Connect to sign into Amazon with your Amazon Seller Central account.</p>
                    </div>
                    <div class="mb-3">
                        <p>4. On the next page, click Authorize to grant ShipStation authorization to retrieve your order information.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary back-button" data-bs-dismiss="modal">Back</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" style="background-color: #00663d; border-color: #00663d;">Connect</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('script')
<script>
    $(document).ready(function(){
        $('#logoImage').change(function(){
            var reader = new FileReader();
            reader.onload = function (e) {
                $('#logoImagePreview').attr('src', e.target.result).show();
            }
            reader.readAsDataURL(this.files[0]);
        });

        $('#storeSearch').on('keyup', function() {
            let query = $(this).val().toLowerCase();
            $('.sales-channel-item').each(function() {
                let name = $(this).data('name').toLowerCase();
                $(this).toggle(name.includes(query));
            });
        });
        $('.connect-channel').on('click', function() {
            let targetModal = $(this).data('bs-target');
            let prevModal = $(this).data('prev-modal');
            let channelLogo = $(this).data('channel-logo');

            if (targetModal === '#amazonConnectModal') {
                $(prevModal).modal('hide'); 
                $('#dynamic-channel-logo').attr('src', channelLogo); 
                $(targetModal).modal('show');
            }
        });

        $('.back-button').on('click', function() {
            $('#amazonConnectModal').modal('hide'); 
            $('#connectStoreModal').modal('show'); 
        });
    });
</script>
@endsection



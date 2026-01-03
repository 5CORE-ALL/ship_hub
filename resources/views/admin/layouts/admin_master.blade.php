@php
    App\Models\User::where('id', Auth::user()->id)->update(['last_active' =>  Carbon\Carbon::now() ]);
    $default_setting = App\Models\DefaultSetting::first();
@endphp
<!doctype html>
<html lang="en" class="light-theme">

<head>
    <!-- Required meta tags -->
    <link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="theme-color" content="#6777ef"/>
<link rel="apple-touch-icon" href="{{ asset('images/icons/logo.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="{{ asset('uploads/default_photo') }}/{{ $default_setting->favicon }}" type="image/png" />
    <!--plugins-->
    <link href="{{ asset('admin') }}/plugins/simplebar/css/simplebar.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/plugins/select2/css/select2.min.css" rel="stylesheet" />
	<link href="{{ asset('admin') }}/plugins/select2/css/select2-bootstrap4.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/plugins/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/plugins/metismenu/css/metisMenu.min.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/plugins/datatable/css/dataTables.bootstrap5.min.css" rel="stylesheet" />

    <link href="{{ asset('admin') }}/plugins/datatable/css/fixedColumns.bootstrap5.min.css" rel="stylesheet" />
    <!-- Bootstrap CSS -->
    <link href="{{ asset('admin') }}/css/bootstrap.min.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/css/bootstrap-extended.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/css/style.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/css/icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css">

    <!-- loader-->
    <link href="{{ asset('admin') }}/css/pace.min.css" rel="stylesheet" />

    <!--Theme Styles-->
    <link href="{{ asset('admin') }}/css/dark-theme.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/css/light-theme.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/css/semi-dark.css" rel="stylesheet" />
    <link href="{{ asset('admin') }}/css/header-colors.css" rel="stylesheet" />

    <link href="{{ asset('admin') }}/plugins/toastr/toastr.css" rel="stylesheet">
    <link href="{{ asset('admin') }}/plugins/summernote/summernote.min.css" rel="stylesheet">
    <link href="{{ asset('admin') }}/plugins/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('admin/css/awaiting_shipment.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        .timezone-display {
            display: flex;
            flex-direction: row;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        .timezone-item {
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        .timezone-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 11px;
        }
        .timezone-time {
            font-weight: 600;
            color: #495057;
            font-size: 12px;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
        }
        .timezone-detail-item {
            border-bottom: 1px solid #e9ecef;
        }
        .timezone-detail-item:last-child {
            border-bottom: none;
        }
        .timezone-time-detail {
            font-weight: 600;
            color: #0d6efd;
            font-size: 14px;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
        }
        .timezone-detail-item:hover {
            background-color: #f8f9fa;
            border-radius: 4px;
            padding: 0 4px;
        }
    </style>

    <title>{{ env('APP_NAME') }} || @yield('title')</title>
</head>

<body>
    <!--start wrapper-->
    <div class="wrapper">
        <!--start top header-->
        <header class="top-header">
            <nav class="navbar navbar-expand">
                <div class="mobile-toggle-icon d-xl-none">
                    <i class="bi bi-list"></i>
                </div>
                <div class="top-navbar-right d-none d-xl-flex ms-auto ms-3">
                    <ul class="navbar-nav align-items-center">
                     <!--    @if (Auth::user()->role == "Super Admin" || Auth::user()->role == "Admin")
                        <li class="nav-item dropdown dropdown-large">
                            <a class="nav-link dropdown-toggle dropdown-toggle-nocaret" href="#" data-bs-toggle="dropdown">
                                <div class="messages">
                                    <span class="notify-badge">{{ App\Models\ContactMessage::where('status', 'Unread')->count() }}</span>
                                    <i class="bi bi-messenger"></i>
                                </div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end p-0">
                                <div class="p-2 border-bottom m-2">
                                    <h5 class="h5 mb-0">Messages</h5>
                                </div>
                                <div class="header-message-list p-2">
                                    <div class="dropdown-item bg-light radius-10 mb-1"></div>
                                    @foreach (App\Models\ContactMessage::where('status', 'Unread')->take(5)->get() as $message)
                                    <a class="dropdown-item" href="#">
                                        <div class="d-flex align-items-center">
                                            <img src="{{ asset('admin') }}/images/avatars/avatar-1.png" alt="" class="rounded-circle" width="52" height="52">
                                            <div class="ms-3 flex-grow-1">
                                            <h6 class="mb-0 dropdown-msg-user">{{ $message->name }}<span class="msg-time float-end text-secondary">{{ $message->created_at->format('D d-M,Y h:m A') }}</span></h6>
                                            <small class="mb-0 dropdown-msg-text text-secondary d-flex align-items-center">{{ $message->subject }}</small>
                                            </div>
                                        </div>
                                    </a>
                                    @endforeach
                                </div>
                                <div class="p-2">
                                    <div><hr class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="{{ route('contact.message.index') }}">
                                        <div class="text-center">View All Messages</div>
                                    </a>
                                </div>
                            </div>
                        </li>
                        @endif -->
                        {{-- <li class="nav-item dropdown dropdown-large d-none d-sm-block">
                            <a class="nav-link dropdown-toggle dropdown-toggle-nocaret" href="#" data-bs-toggle="dropdown">
                                <div class="notifications">
                                <span class="notify-badge">8</span>
                                <i class="bi bi-bell-fill"></i>
                                </div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end p-0">
                                <div class="p-2 border-bottom m-2">
                                    <h5 class="h5 mb-0">Notifications</h5>
                                </div>
                                <div class="header-notifications-list p-2">
                                    <div class="dropdown-item bg-light radius-10 mb-1"></div>
                                    <a class="dropdown-item" href="#">
                                        <div class="d-flex align-items-center">
                                            <div class="notification-box"><i class="bi bi-basket2-fill"></i></div>
                                            <div class="ms-3 flex-grow-1">
                                                <h6 class="mb-0 dropdown-msg-user">New Orders <span class="msg-time float-end text-secondary">1 m</span></h6>
                                                <small class="mb-0 dropdown-msg-text text-secondary d-flex align-items-center">You have recived new orders</small>
                                            </div>
                                        </div>
                                    </a>
                                    <a class="dropdown-item" href="#">
                                        <div class="d-flex align-items-center">
                                            <div class="notification-box"><i class="bi bi-people-fill"></i></div>
                                            <div class="ms-3 flex-grow-1">
                                                <h6 class="mb-0 dropdown-msg-user">New Customers <span class="msg-time float-end text-secondary">7 m</span></h6>
                                                <small class="mb-0 dropdown-msg-text text-secondary d-flex align-items-center">5 new user registered</small>
                                            </div>
                                        </div>
                                    </a>
                                    <a class="dropdown-item" href="#">
                                        <div class="d-flex align-items-center">
                                        <div class="notification-box"><i class="bi bi-mic-fill"></i></div>
                                        <div class="ms-3 flex-grow-1">
                                            <h6 class="mb-0 dropdown-msg-user">Your item is shipped <span class="msg-time float-end text-secondary">7 m</span></h6>
                                            <small class="mb-0 dropdown-msg-text text-secondary d-flex align-items-center">Successfully shipped your item</small>
                                        </div>
                                        </div>
                                    </a>
                                    <a class="dropdown-item" href="#">
                                        <div class="d-flex align-items-center">
                                        <div class="notification-box"><i class="bi bi-lightbulb-fill"></i></div>
                                        <div class="ms-3 flex-grow-1">
                                            <h6 class="mb-0 dropdown-msg-user">Defense Alerts <span class="msg-time float-end text-secondary">2 h</span></h6>
                                            <small class="mb-0 dropdown-msg-text text-secondary d-flex align-items-center">45% less alerts last 4 weeks</small>
                                        </div>
                                        </div>
                                    </a>
                                </div>
                                <div class="p-2">
                                    <div><hr class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="#">
                                        <div class="text-center">View All Notifications</div>
                                    </a>
                                </div>
                            </div>
                        </li> --}}
                        <li class="nav-item dropdown dropdown-large d-none d-xl-block">
                            <a class="nav-link dropdown-toggle dropdown-toggle-nocaret" href="#" data-bs-toggle="dropdown">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="bi bi-clock-history fs-5"></i>
                                    <div class="timezone-display">
                                        <div class="timezone-item">
                                            <span class="timezone-label">IN:</span>
                                            <span id="time-india" class="timezone-time">--:-- --</span>
                                        </div>
                                        <div class="timezone-item">
                                            <span class="timezone-label">CA:</span>
                                            <span id="time-california" class="timezone-time">--:-- --</span>
                                        </div>
                                        <div class="timezone-item">
                                            <span class="timezone-label">OH:</span>
                                            <span id="time-ohio" class="timezone-time">--:-- --</span>
                                        </div>
                                        <div class="timezone-item">
                                            <span class="timezone-label">CN:</span>
                                            <span id="time-china" class="timezone-time">--:-- --</span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 280px;">
                                <div class="timezone-detail">
                                    <h6 class="mb-3 fw-bold">World Clock</h6>
                                    <div class="timezone-detail-item">
                                        <div class="d-flex justify-content-between align-items-center py-2">
                                            <div>
                                                <span class="fw-bold d-block">India</span>
                                                <small class="text-muted">IST (Asia/Kolkata)</small>
                                            </div>
                                            <span id="time-india-detail" class="timezone-time-detail">--:--:-- --</span>
                                        </div>
                                    </div>
                                    <div class="timezone-detail-item">
                                        <div class="d-flex justify-content-between align-items-center py-2">
                                            <div>
                                                <span class="fw-bold d-block">California</span>
                                                <small class="text-muted">PST/PDT (America/Los_Angeles)</small>
                                            </div>
                                            <span id="time-california-detail" class="timezone-time-detail">--:--:-- --</span>
                                        </div>
                                    </div>
                                    <div class="timezone-detail-item">
                                        <div class="d-flex justify-content-between align-items-center py-2">
                                            <div>
                                                <span class="fw-bold d-block">Ohio</span>
                                                <small class="text-muted">EST/EDT (America/New_York)</small>
                                            </div>
                                            <span id="time-ohio-detail" class="timezone-time-detail">--:--:-- --</span>
                                        </div>
                                    </div>
                                    <div class="timezone-detail-item">
                                        <div class="d-flex justify-content-between align-items-center py-2">
                                            <div>
                                                <span class="fw-bold d-block">China</span>
                                                <small class="text-muted">CST (Asia/Shanghai)</small>
                                            </div>
                                            <span id="time-china-detail" class="timezone-time-detail">--:--:-- --</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>
                        <li class="nav-item dropdown dropdown-large">
                            @php
                                $photo = Auth::user()->profile_photo;
                                // agar https ya http se start ho raha hai, direct use karo
                                if (Str::startsWith($photo, ['http://', 'https://'])) {
                                    $photoUrl = $photo;
                                } else {
                                    $photoUrl = asset('uploads/profile_photo/' . $photo);
                                }
                            @endphp
                            <li class="nav-item">
                                <a href="javascript:void(0);" onclick="location.reload();" class="nav-link" title="Refresh Page">
                                    <i class="bi bi-arrow-clockwise fs-5"></i>
                                </a>
                            </li>
                            <a class="nav-link dropdown-toggle dropdown-toggle-nocaret" href="#" data-bs-toggle="dropdown">
                                <div class="user-setting d-flex align-items-center gap-1">
                                <!-- <img src="{{ asset('uploads/profile_photo') }}/{{ Auth::user()->profile_photo }}" class="user-img" alt=""> -->
                                <img src="{{ $photoUrl }}" class="user-img" alt="">
                                <div class="user-name d-none d-sm-block">{{ Auth::user()->name }}</div>
                                </div>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                <a class="dropdown-item" href="{{ route('profile.edit') }}">
                                    <div class="d-flex align-items-center">
                                        <img src="{{ $photoUrl }}" alt="" class="rounded-circle" width="60" height="60">
                                        <div class="ms-3">
                                        <h6 class="mb-0 dropdown-user-name">{{ Auth::user()->name }}</h6>
                                        <small class="mb-0 dropdown-user-designation text-secondary">{{ Auth::user()->role }}</small>
                                        </div>
                                    </div>
                                </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="{{ route('profile.edit') }}">
                                    <div class="d-flex align-items-center">
                                        <div class="setting-icon"><i class="bi bi-person-fill"></i></div>
                                        <div class="setting-text ms-3"><span>Profile</span></div>
                                    </div>
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                            @csrf
                                        </form>
                                        <div class="d-flex align-items-center">
                                            <div class="setting-icon"><i class="bi bi-lock-fill"></i></div>
                                            <div class="setting-text ms-3"><span>Logout</span></div>
                                        </div>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>
        </header>
        <!--end top header-->

        <!--start sidebar -->
        <aside class="sidebar-wrapper" data-simplebar="true">
            <div class="sidebar-header">
                <div>
                    <img src="{{ asset('admin') }}/images/logo-icon.png" class="logo-icon" alt="logo icon">
                </div>
                <div>
                    <a href="{{ route('dashboard') }}"><h4 class="logo-text"> <img src="https://www.5core.com/cdn/shop/files/1500px_3x_acec3a4d-39c5-41e8-99b2-9be0419d70bc.png?v=1731097909&width=340" 
             alt="{{ env('APP_NAME') }} Logo" 
             style="height: 40px; width: auto; object-fit: contain; border-radius: 6px;"></h4></a>
                </div>
                <div class="toggle-icon ms-auto">
                    <i class="bi bi-chevron-double-left"></i>
                </div>
            </div>
            <!--navigation-->
            @include('admin.layouts.navigation')
            <!--end navigation-->
        </aside>
        <!--end sidebar -->

        <!--start content-->
        <main class="page-content">
            <!--breadcrumb-->
            <div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
                <div class="breadcrumb-title pe-3">Dashboard</div>
                <div class="ps-3">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 p-0">
                            <li class="breadcrumb-item">
                                <a href="{{ route('dashboard') }}"><i class="bx bx-home-alt"></i></a>
                            </li>
                            <li class="breadcrumb-item active" aria-current="page">@yield('title')</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <!--end breadcrumb-->
            @yield('content')
        </main>
        <!--end page main-->

        <!--start overlay-->
        <div class="overlay nav-toggle-icon"></div>
        <!--end overlay-->

        <!--Start Back To Top Button-->
        <a href="javaScript:;" class="back-to-top"><i class='bx bxs-up-arrow-alt'></i></a>
        <!--End Back To Top Button-->

        <!--start switcher-->
        <div class="switcher-body">
            <button class="btn btn-primary btn-switcher shadow-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasScrolling" aria-controls="offcanvasScrolling"><i class="bi bi-paint-bucket me-0"></i></button>
            <div class="offcanvas offcanvas-end shadow border-start-0 p-2" data-bs-scroll="true" data-bs-backdrop="false" tabindex="-1" id="offcanvasScrolling">
                <div class="offcanvas-header border-bottom">
                    <h5 class="offcanvas-title" id="offcanvasScrollingLabel">Theme Customizer</h5>
                    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas"></button>
                </div>
                <div class="offcanvas-body">
                    <h6 class="mb-0">Theme Variation</h6>
                    <hr>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="inlineRadioOptions" id="LightTheme" value="option1" checked>
                        <label class="form-check-label" for="LightTheme">Light</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="inlineRadioOptions" id="DarkTheme" value="option2">
                        <label class="form-check-label" for="DarkTheme">Dark</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="inlineRadioOptions" id="SemiDarkTheme" value="option3">
                        <label class="form-check-label" for="SemiDarkTheme">Semi Dark</label>
                    </div>
                    <hr>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="inlineRadioOptions" id="MinimalTheme" value="option3">
                        <label class="form-check-label" for="MinimalTheme">Minimal Theme</label>
                    </div>
                    <hr/>
                    <h6 class="mb-0">Header Colors</h6>
                    <hr/>
                    <div class="header-colors-indigators">
                        <div class="row row-cols-auto g-3">
                            <div class="col">
                                <div class="indigator headercolor1" id="headercolor1"></div>
                            </div>
                            <div class="col">
                                <div class="indigator headercolor2" id="headercolor2"></div>
                            </div>
                            <div class="col">
                                <div class="indigator headercolor3" id="headercolor3"></div>
                            </div>
                            <div class="col">
                                <div class="indigator headercolor4" id="headercolor4"></div>
                            </div>
                            <div class="col">
                                <div class="indigator headercolor5" id="headercolor5"></div>
                            </div>
                            <div class="col">
                                <div class="indigator headercolor6" id="headercolor6"></div>
                            </div>
                            <div class="col">
                                <div class="indigator headercolor7" id="headercolor7"></div>
                            </div>
                            <div class="col">
                                <div class="indigator headercolor8" id="headercolor8"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!--end switcher-->
    </div>
    <!--end wrapper-->

    <!-- Bootstrap bundle JS -->
    <script src="{{ asset('admin') }}/js/bootstrap.bundle.min.js"></script>
    <!--plugins-->
    <script src="{{ asset('admin') }}/js/jquery.min.js"></script>
    <script src="{{ asset('admin') }}/plugins/simplebar/js/simplebar.min.js"></script>
    <script src="{{ asset('admin') }}/plugins/perfect-scrollbar/js/perfect-scrollbar.js"></script>
    <script src="{{ asset('admin') }}/js/pace.min.js"></script>

    <script src="{{ asset('admin') }}/plugins/datatable/js/jquery.dataTables.min.js"></script>
    <script src="{{ asset('admin') }}/plugins/datatable/js/dataTables.bootstrap5.min.js"></script>

    <script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>
    
    <script src="{{ asset('admin') }}/js/table-datatable.js"></script>
    <script src="{{ asset('admin') }}/plugins/select2/js/select2.min.js"></script>
    <script src="{{ asset('admin') }}/js/form-select2.js"></script>

    <script src="{{ asset('admin') }}/plugins/apexcharts-bundle/js/apexcharts.min.js"></script>
    <script src="{{ asset('admin') }}/plugins/metismenu/js/metisMenu.min.js"></script>

    <script src="{{asset('admin')}}/plugins/printThis/printThis.js"></script>

    <!--app-->
    <script src="{{ asset('admin') }}/js/app.js"></script>

    <script src="{{ asset('admin') }}/plugins/sweetalert2/sweetalert2.all.min.js"></script>
    <script src="{{ asset('admin') }}/plugins/summernote/summernote.min.js"></script>
    <script src="{{ asset('admin') }}/plugins/toastr/toastr.min.js"></script>
    <script>
        $(document).ready(function() {
            @if(Session::has('message'))
                var type = "{{ Session::get('alert-type', 'info') }}";
                switch(type){
                    case 'info':
                        toastr.info("{{ Session::get('message') }}");
                        break;

                    case 'warning':
                        toastr.warning("{{ Session::get('message') }}");
                        break;

                    case 'success':
                        toastr.success("{{ Session::get('message') }}");
                        break;

                    case 'error':
                        toastr.error("{{ Session::get('message') }}");
                        break;
                }
            @endif
        });
    </script>
<script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register("{{ asset('sw.js') }}")
        .then(reg => console.log('Service Worker registered:', reg))
        .catch(err => console.log('Service Worker registration failed:', err));
    }
</script>
<script>
    // Timezone display functionality
    function formatTimeInTimezone(timezone, format = 'short') {
        const now = new Date();
        const options = {
            timeZone: timezone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        };
        
        if (format === 'long') {
            options.second = '2-digit';
        }
        
        const formatter = new Intl.DateTimeFormat('en-US', options);
        const parts = formatter.formatToParts(now);
        
        const hour = parts.find(p => p.type === 'hour').value;
        const minute = parts.find(p => p.type === 'minute').value;
        const dayPeriod = parts.find(p => p.type === 'dayPeriod')?.value || '';
        
        if (format === 'short') {
            return `${hour}:${minute} ${dayPeriod}`;
        } else {
            const second = parts.find(p => p.type === 'second').value;
            return `${hour}:${minute}:${second} ${dayPeriod}`;
        }
    }
    
    function updateTimezones() {
        // India (Asia/Kolkata - IST)
        document.getElementById('time-india').textContent = formatTimeInTimezone('Asia/Kolkata', 'short');
        document.getElementById('time-india-detail').textContent = formatTimeInTimezone('Asia/Kolkata', 'long');
        
        // California (America/Los_Angeles - PST/PDT)
        document.getElementById('time-california').textContent = formatTimeInTimezone('America/Los_Angeles', 'short');
        document.getElementById('time-california-detail').textContent = formatTimeInTimezone('America/Los_Angeles', 'long');
        
        // Ohio (America/New_York - EST/EDT)
        document.getElementById('time-ohio').textContent = formatTimeInTimezone('America/New_York', 'short');
        document.getElementById('time-ohio-detail').textContent = formatTimeInTimezone('America/New_York', 'long');
        
        // China (Asia/Shanghai - CST)
        document.getElementById('time-china').textContent = formatTimeInTimezone('Asia/Shanghai', 'short');
        document.getElementById('time-china-detail').textContent = formatTimeInTimezone('Asia/Shanghai', 'long');
    }
    
    // Update timezones immediately and then every second
    $(document).ready(function() {
        updateTimezones();
        setInterval(updateTimezones, 1000);
    });
</script>
    @yield('script')

</body>
</html>

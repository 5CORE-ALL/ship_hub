@extends('admin.layouts.auth_master')

@section('title', 'Login')

@section('content')
<div class="col-lg-7">
    <div class="card-body mt-3 p-4 p-sm-5 text-center">
        <div class="mb-4">
            <img src="{{ asset('admin/images/image.jpg') }}" alt="ShipHub Logo" class="img-fluid logo" style="max-width: 120px;">
            <h5 class="card-title mt-2 title">ShipHub</h5>
            <p class="card-text text-muted tagline">
                Ship Smarter, Not Harder.
            </p>
        </div>
        <p class="card-text mb-5 intro">Use your Google account to login</p>

        @if (session('status'))
        <div class="alert alert-info">
            <strong>{{ session('status') }}</strong>
        </div>
        @endif

        <div class="d-grid">
            <a href="{{ url('auth/google') }}" class="btn btn-danger btn-lg radius-30 google-btn">
                <i class="bi bi-google me-2"></i> Sign in with Google
            </a>
        </div>

        <!-- Privacy Policy Link -->
        <p class="text-center mt-3 mb-3 policy-links">
            <small class="text-muted">By signing in, you agree to our <a href="{{ url('/privacy-policy') }}" class="text-danger text-decoration-none">Privacy Policy</a>.</small>
        </p>
        
        <!-- Subtle footer for added polish -->
        <div class="mt-4 footer">
            <small class="text-muted">Secure, seamless, and swift logistics.</small>
        </div>
    </div>
</div>
@endsection

<style>
    body {
        background: linear-gradient(135deg, #f0f4f8 0%, #d9e2ec 50%, #c3d8e8 100%); /* Multi-layer gradient for depth */
        min-height: 100vh;
        margin: 0;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; /* Clean, modern font */
        position: relative;
        overflow-x: hidden;
    }
    
    body::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: radial-gradient(circle at 20% 80%, rgba(59, 130, 246, 0.1) 0%, transparent 50%),
                    radial-gradient(circle at 80% 20%, rgba(220, 53, 69, 0.1) 0%, transparent 50%);
        pointer-events: none;
        z-index: -1;
    }
    
    .card-body {
        background: rgba(255, 255, 255, 0.95); /* Slight transparency for glass effect */
        backdrop-filter: blur(20px);
        border-radius: 1.5rem;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        border: 1px solid rgba(255, 255, 255, 0.2);
        position: relative;
    }
    
    .card-body::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(220, 53, 69, 0.3), transparent);
    }
    
    .card-body:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    }
    
    .logo {
        width: 120px;
        height: 120px; /* Fixed square dimensions for circular crop */
        object-fit: cover;
        border-radius: 50%; /* Fully round logo */
        transition: all 0.3s ease;
        filter: drop-shadow(0 4px 12px rgba(220, 53, 69, 0.15));
        animation: float 3s ease-in-out infinite;
        border: 3px solid rgba(220, 53, 69, 0.1); /* Subtle border for definition */
    }
    
    @keyframes float {
        0%, 100% { transform: translateY(0px) rotate(0deg); }
        50% { transform: translateY(-5px) rotate(2deg); }
    }
    
    .logo:hover {
        transform: scale(1.15) rotate(10deg);
        filter: drop-shadow(0 6px 16px rgba(220, 53, 69, 0.25));
        border-color: rgba(220, 53, 69, 0.3);
    }
    
    .title {
        font-size: 2rem;
        font-weight: 700;
        background: linear-gradient(135deg, #dc3545, #a71e2a);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 0.5rem;
        letter-spacing: -0.5px; /* Tighter kerning for premium feel */
    }
    
    .tagline {
        font-size: 1.05rem;
        font-weight: 400;
        color: #6c757d !important;
        font-style: italic;
        letter-spacing: 0.5px;
        max-width: 250px;
        margin: 0 auto;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); /* Subtle depth */
    }
    
    .intro {
        font-size: 1.15rem;
        color: #495057;
        font-weight: 300;
        opacity: 0.9;
        letter-spacing: 0.2px;
    }
    
    .google-btn {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        border: none;
        border-radius: 2rem;
        padding: 14px 28px;
        font-size: 1.1rem;
        font-weight: 600;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 56px;
        box-shadow: 0 4px 15px rgba(220, 53, 69, 0.2);
    }
    
    .google-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.6s ease;
    }
    
    .google-btn:hover::before {
        left: 100%;
    }
    
    .google-btn:hover {
        background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(220, 53, 69, 0.3);
    }
    
    .google-btn:active {
        transform: translateY(-1px);
    }
    
    .alert-info {
        background: linear-gradient(135deg, #e7f3ff 0%, #cce7ff 100%);
        color: #0c5460;
        border: 1px solid rgba(0, 123, 255, 0.2);
        border-radius: 0.75rem;
        font-size: 0.95rem;
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.1);
        animation: fadeIn 0.5s ease-out;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .footer {
        opacity: 0;
        transition: opacity 0.4s ease;
        font-style: italic;
    }
    
    .card-body:hover .footer {
        opacity: 1;
    }
    
    /* Responsive tweaks */
    @media (max-width: 768px) {
        .card-body {
            margin: 1rem;
            border-radius: 1.25rem;
            padding: 2rem 1.5rem;
        }
        
        .title {
            font-size: 1.75rem;
        }
        
        .tagline {
            font-size: 1rem;
            max-width: 100%;
        }
        
        .google-btn {
            padding: 16px 24px;
            font-size: 1.05rem;
        }
        
        .logo {
            width: 100px;
            height: 100px;
        }
    }

    /* Style for policy links */
    .policy-links a:hover {
        text-decoration: underline;
    }
</style>
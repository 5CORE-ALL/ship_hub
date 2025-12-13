@extends('frontend.layouts.frontend_master')

@section('title', 'Privacy Policy')

@section('content')
<!-- ========================= privacy-policy-section start ========================= -->
<section class="about-section pt-150 pb-5">
    <div class="container">
        <div class="row">
            <div class="col-lg-12">
                <div class="about-content received-content">
                    <div class="section-title">
                        <span class="wow fadeInUp" data-wow-delay=".2s">Privacy Policy - {{ env('APP_NAME') }}</span>
                        <h1 class="mb-25 wow fadeInUp" data-wow-delay=".4s">Privacy & Data Security Policy for 5Core Carrier Management using ShipHub</h1>
                        <p class="wow fadeInUp" data-wow-delay=".6s">
                            <strong>Last Updated: November 04, 2025</strong><br><br>
                            <strong>Organization: 5Core</strong><br>
                            <strong>Website: https://ship.5coremanagement.com/</strong><br><br>

                            <strong>1. Overview</strong><br><br>
                            At 5Core, we take data protection and privacy seriously. Our carrier management system, powered by ShipHub, connects multiple e-commerce platforms and shipping carriers—including Amazon, eBay, Walmart, Temu, USPS, UPS, FedEx, and others—to streamline order processing, label creation, rate shopping, and real-time tracking updates.<br>
                            We recognize that this process involves sensitive customer and carrier information, and we are committed to maintaining the highest standards of data privacy, integrity, and security in every part of our system.<br><br>

                            <strong>2. Information We Collect</strong><br><br>
                            We only collect the information necessary to manage carriers and fulfill orders, including:<br><br>
                            • Buyer names, addresses, and contact numbers for shipping purposes.<br>
                            • Order details (such as SKUs, quantities, weights, dimensions, and tracking numbers).<br>
                            • Seller account credentials, API tokens, and carrier account details needed to connect our systems to e-commerce platforms and shipping carriers.<br><br>
                            We do not collect or store payment details, credit card data, or personal identification documents from customers, sellers, or carriers.<br><br>

                            <strong>3. How We Use Information</strong><br><br>
                            All collected data is used solely for operational purposes, including:<br>
                            • Generating accurate shipping labels and customs forms.<br>
                            • Comparing carrier rates and selecting optimal shipping options.<br>
                            • Updating shipment tracking, delivery status, and exceptions in real-time.<br>
                            • Syncing order fulfillment data between platforms, carriers, and ShipHub.<br><br>
                            We never use customer, seller, or carrier information for marketing, profiling, or analytics. Data is never sold, rented, or shared with unauthorized third parties.<br><br>

                            <strong>4. Data Security</strong><br><br>
                            Our systems, including ShipHub integration, are hosted on secure, in-house servers maintained by the 5Core IT team.<br>
                            We employ the following safeguards:<br><br>
                            • <strong>Encryption:</strong> All sensitive data is encrypted at rest (AES-256) and in transit (TLS 1.3 or higher).<br>
                            • <strong>Access Controls:</strong> Only authorized personnel have access to operational data, enforced through role-based access control (RBAC) and multi-factor authentication (MFA).<br>
                            • <strong>Separation of Data:</strong> Seller and carrier accounts are logically separated to prevent any cross-access or data leakage.<br>
                            • <strong>Monitoring:</strong> All system access, API calls, and data transactions are logged, monitored, and reviewed regularly to detect unauthorized activity.<br><br>

                            <strong>5. Data Retention and Disposal</strong><br><br>
                            We keep personally identifiable information (PII) only as long as necessary to complete fulfillment, track deliveries, manage carrier disputes, and meet audit requirements.<br>
                            Once an order is completed and confirmed as delivered, customer data is securely deleted or anonymized—typically within 30 days. Backup data containing PII is overwritten automatically in the next backup cycle.<br><br>

                            <strong>6. Third-Party Sharing</strong><br><br>
                            We only share data with trusted partners directly involved in the carrier management and fulfillment process, such as shipping carriers (e.g., USPS, UPS), e-commerce platforms, and ShipHub's core services.<br>
                            All integrations with external systems, including ShipHub APIs, use secure, encrypted connections.<br>
                            We do not share, resell, or disclose data to any other third party under any circumstance unless required by law.<br><br>

                            <strong>7. Incident Response</strong><br><br>
                            In the unlikely event of a security incident or data breach:<br><br>
                            • We immediately isolate affected systems, including ShipHub integrations.<br>
                            • Rotate access credentials and investigate the source of compromise.<br>
                            • Notify any affected platform, carrier, or partner (e.g., Amazon, UPS, ShipHub) within 24 hours of confirmed impact.<br>
                            • Conduct a root-cause analysis and apply remediation measures to prevent recurrence.<br><br>

                            <strong>8. Employee Access and Training</strong><br><br>
                            Only authorized and trained personnel have access to sensitive data.<br>
                            All staff handling customer, seller, or carrier data receive annual training on data security, privacy standards, and compliance requirements for platforms like ShipHub and major carriers.<br><br>

                            <strong>9. Policy Updates</strong><br><br>
                            This policy may be updated periodically to reflect changes in our systems, ShipHub integrations, carrier partnerships, or legal requirements.<br>
                            The latest version will always be available on our website.<br><br>

                            <strong>10. Contact Us</strong><br><br>
                            If you have any questions about our data privacy or security practices, please contact:<br><br>
                            <strong>5Core Data Protection Team</strong><br>
                            📧 tech-support@5core.com<br>
                            🏢 1221 W Sandusky Ave Suite C, Bellefontaine OH 43311
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- ========================= privacy-policy-section end ========================= -->
@endsection
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order Details</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            color: #2c3e50;
            background-color: #f7f7f7;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 700px;
            margin: 20px auto;
            background-color: #ffffff;
            padding: 25px 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        h1, h2 {
            color: #34495e;
            margin-bottom: 15px;
        }

        h1 {
            font-size: 26px; /* larger font for Order ID */
            font-weight: bold;
        }

        p {
            font-size: 14px;
            line-height: 1.6;
        }

        .highlight {
            color: #e74c3c;
            font-weight: bold;
            font-size: 16px; /* slightly larger font */
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 10px 12px;
            border: 1px solid #ddd;
            text-align: left;
            font-size: 14px;
        }

        th {
            background-color: #3498db;
            color: #ffffff;
        }

        tr:nth-child(even) {
            background-color: #f2f6fa;
        }

        .footer {
            margin-top: 25px;
            font-size: 13px;
            color: #7f8c8d;
        }
    </style>
</head>
<body>
    <div class="container">
        <p>Dear <span class="highlight">5 Core Shipping Team</span>,</p>

        <h1>Order <span class="highlight">#{{ $order->order_number }}</span> - Multiple SKUs</h1>

        <p><strong>Recipient:</strong> {{ $order->recipient_name }}</p>
        <p><strong>Email:</strong> {{ $order->recipient_email ?? 'N/A' }}<br>
           <strong>Phone:</strong> {{ $order->recipient_phone ?? 'N/A' }}</p>

        <h2>SKUs Included:</h2>
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Quantity</th>
                </tr>
            </thead>
            <tbody>
                <tbody>
                    @foreach($items as $item) 
                    <tr> 
                        <td>{{ $item->sku }}</td> 
                        <td>{{ $item->quantity_ordered }}</td> 
                    </tr> 
                    @endforeach
                </tbody>
            </tbody>
        </table>

        <p class="footer">Regards,<br>ShipHub Team</p>
    </div>
</body>
</html>

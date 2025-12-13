<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order Details</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 15px;
        }
        th, td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f4f4f4;
        }
        h2 {
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <p>Dear 5 Core Shipping Team,</p>

    <h2>Order #{{ $orderNumber }} - Multiple SKUs</h2>

    <p>The following SKUs are included in this order:</p>

    <table>
        <thead>
            <tr>
                <th>SKU</th>
                <th>Quantity</th>
            </tr>
        </thead>
        <tbody>
            @foreach($skus as $sku)
            <tr>
                <td>{{ $sku['name'] }}</td>
                <td>{{ $sku['quantity'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <p>Thank you,<br>{{ config('app.name') }} Team</p>
</body>
</html>

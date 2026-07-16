<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            color: #1f2937;
            margin: 0;
        }
        .banner {
            width: 100%;
            background-color: #1d4ed8;
            color: #ffffff;
            padding: 24px 28px;
            margin-bottom: 4px;
        }
        .banner td { vertical-align: middle; }
        .logo { max-height: 55px; max-width: 150px; }
        .company-name { font-size: 17px; font-weight: bold; }
        .invoice-title { font-size: 26px; font-weight: bold; text-align: right; }
        .invoice-number { text-align: right; font-size: 12px; color: #dbeafe; }
        .accent-bar { width: 100%; height: 6px; background-color: #93c5fd; margin-bottom: 24px; }
        .content { padding: 0 28px; }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 3px;
            color: #ffffff;
            background-color: {{ $invoice->status === 'paid' ? '#16a34a' : ($invoice->status === 'cancelled' ? '#6b7280' : '#f59e0b') }};
            font-size: 11px;
            font-weight: bold;
        }
        .info-boxes { width: 100%; margin: 20px 0; }
        .info-boxes td { width: 33%; vertical-align: top; padding-right: 16px; }
        .info-box-label { font-size: 10px; text-transform: uppercase; color: #1d4ed8; font-weight: bold; margin-bottom: 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th {
            background-color: #1d4ed8;
            color: #ffffff;
            text-align: left;
            padding: 10px 8px;
            font-size: 11px;
        }
        table.items td { padding: 9px 8px; }
        table.items tbody tr:nth-child(even) { background-color: #eff6ff; }
        table.items th.num, table.items td.num { text-align: right; }
        .totals { width: 42%; margin-left: 58%; margin-top: 16px; }
        .totals td { padding: 5px 8px; }
        .totals .total-row td {
            font-weight: bold;
            font-size: 15px;
            background-color: #1d4ed8;
            color: #ffffff;
        }
        .footer {
            margin-top: 28px;
            padding: 14px 28px;
            background-color: #eff6ff;
            font-size: 10px;
            color: #374151;
        }
        .signature { width: calc(100% - 56px); margin: 32px 28px 0; }
        .signature td { width: 50%; text-align: center; padding-top: 26px; border-top: 1px solid #93c5fd; font-size: 10px; color: #1d4ed8; }
    </style>
</head>
<body>
    <table class="banner">
        <tr>
            <td style="width: 55%;">
                @if($logoDataUri)<img src="{{ $logoDataUri }}" class="logo" alt="Logo"><br>@endif
                <div class="company-name">{{ $company->name }}</div>
            </td>
            <td style="width: 45%;">
                <div class="invoice-title">{{ $t['invoice'] }}</div>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
            </td>
        </tr>
    </table>
    <div class="accent-bar"></div>

    <div class="content">
        <table class="info-boxes">
            <tr>
                <td>
                    <div class="info-box-label">{{ $t['client'] }}</div>
                    <strong>{{ $invoice->client->name }}</strong><br>
                    @if($invoice->client->email){{ $invoice->client->email }}@endif
                </td>
                <td>
                    <div class="info-box-label">{{ $t['dates'] }}</div>
                    {{ $t['issued_short'] }} : {{ $invoice->issued_at?->format('d/m/Y') }}<br>
                    {{ $t['due_short'] }} : {{ $invoice->due_date?->format('d/m/Y') }}
                </td>
                <td>
                    <div class="info-box-label">{{ $t['status'] }}</div>
                    <span class="status-badge">{{ strtoupper($statusLabel) }}</span>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>{{ $t['article'] }}</th>
                    <th class="num">{{ $t['qty'] }}</th>
                    <th class="num">{{ $t['unit_price'] }}</th>
                    <th class="num">{{ $t['discount'] }}</th>
                    <th class="num">{{ $t['total'] }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $item)
                    <tr>
                        <td>{{ $item->name_snapshot }}</td>
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">{{ $money($item->unit_price) }}</td>
                        <td class="num">{{ $item->discount_percent > 0 ? $item->discount_percent . '%' : '-' }}</td>
                        <td class="num">{{ $money($item->total_line) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td>{{ $t['subtotal'] }}</td><td class="num" style="text-align:right;">{{ $money($invoice->subtotal) }}</td></tr>
            @if($invoice->discount_amount > 0)
            <tr><td>{{ $t['discount'] }}</td><td class="num" style="text-align:right;">-{{ $money($invoice->discount_amount) }}</td></tr>
            @endif
            @if($invoice->tax_amount > 0)
            <tr><td>{{ $t['tax'] }}</td><td class="num" style="text-align:right;">{{ $money($invoice->tax_amount) }}</td></tr>
            @endif
            @if($invoice->shipping_fee > 0)
            <tr><td>{{ $t['shipping'] }}</td><td class="num" style="text-align:right;">{{ $money($invoice->shipping_fee) }}</td></tr>
            @endif
            <tr class="total-row"><td>{{ $t['total'] }}</td><td class="num" style="text-align:right;">{{ $money($invoice->total) }}</td></tr>
        </table>
    </div>

    @if($invoice->notes || $invoice->terms || $company->bank_account_number)
        <div class="footer">
            @if($invoice->notes)<div><strong>{{ $t['notes'] }} :</strong> {{ $invoice->notes }}</div>@endif
            @if($invoice->terms)<div style="margin-top:4px;"><strong>{{ $t['terms'] }} :</strong> {{ $invoice->terms }}</div>@endif
            @if($company->bank_account_number)<div style="margin-top:4px;"><strong>{{ $t['bank'] }} :</strong> {{ $company->bank_account_number }}</div>@endif
        </div>
    @endif

    <table class="signature">
        <tr>
            <td>{{ $t['company_stamp'] }}</td>
            <td>{{ $t['client_signature'] }}</td>
        </tr>
    </table>
</body>
</html>

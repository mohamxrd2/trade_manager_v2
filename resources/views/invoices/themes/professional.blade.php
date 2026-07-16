<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            color: #262626;
            margin: 0;
        }
        .header-band {
            width: 100%;
            background-color: #1c2431;
            color: #ffffff;
            padding: 26px 32px;
        }
        .header-band td { vertical-align: middle; }
        .logo { max-height: 55px; max-width: 150px; }
        .company-name { font-family: 'DejaVu Serif', serif; font-size: 18px; font-weight: bold; }
        .invoice-title {
            font-family: 'DejaVu Serif', serif;
            font-size: 22px;
            text-align: right;
            color: #c9a96e;
            letter-spacing: 2px;
        }
        .invoice-number { text-align: right; font-size: 12px; color: #9aa4b2; margin-top: 4px; }
        .gold-line { width: 100%; height: 3px; background-color: #c9a96e; }
        .content { padding: 26px 32px; }
        .info-boxes { width: 100%; margin-bottom: 22px; }
        .info-boxes td { width: 50%; vertical-align: top; }
        .info-box {
            background-color: #f5f5f4;
            padding: 14px 16px;
            margin-right: 12px;
        }
        .info-box-label {
            font-family: 'DejaVu Serif', serif;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #1c2431;
            font-weight: bold;
            margin-bottom: 6px;
            border-bottom: 1px solid #c9a96e;
            padding-bottom: 4px;
        }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th {
            background-color: #1c2431;
            color: #c9a96e;
            text-align: left;
            padding: 9px 8px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        table.items td { padding: 8px; border-bottom: 1px solid #e5e5e5; }
        table.items th.num, table.items td.num { text-align: right; }
        .totals { width: 42%; margin-left: 58%; margin-top: 16px; }
        .totals td { padding: 5px 8px; }
        .totals .total-row td {
            font-weight: bold;
            font-size: 15px;
            border-top: 2px solid #c9a96e;
            color: #1c2431;
        }
        .signature { width: 100%; margin-top: 40px; }
        .signature td { width: 50%; text-align: center; padding-top: 28px; border-top: 1px solid #9aa4b2; font-size: 10px; color: #6b7280; }
        .footer {
            margin-top: 26px;
            padding: 14px 32px;
            background-color: #1c2431;
            color: #9aa4b2;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <table class="header-band">
        <tr>
            <td style="width: 55%;">
                @if($logoDataUri)<img src="{{ $logoDataUri }}" class="logo" alt="Logo"><br>@endif
                <div class="company-name">{{ $company->name }}</div>
                @if($company->headquarters)<div style="color:#9aa4b2;font-size:10px;">{{ $company->headquarters }}</div>@endif
            </td>
            <td style="width: 45%;">
                <div class="invoice-title">{{ $t['invoice'] }}</div>
                <div class="invoice-number">{{ $t['invoice_number'] }} {{ $invoice->invoice_number }}</div>
            </td>
        </tr>
    </table>
    <div class="gold-line"></div>

    <div class="content">
        <table class="info-boxes">
            <tr>
                <td>
                    <div class="info-box">
                        <div class="info-box-label">{{ $t['billed_to'] }}</div>
                        <strong>{{ $invoice->client->name }}</strong><br>
                        @if($invoice->client->email){{ $invoice->client->email }}<br>@endif
                        @if($invoice->billing_address){{ nl2br(e($invoice->billing_address)) }}@endif
                    </div>
                </td>
                <td>
                    <div class="info-box">
                        <div class="info-box-label">{{ $t['details'] }}</div>
                        {{ $t['issued_date'] }} : {{ $invoice->issued_at?->format('d/m/Y') }}<br>
                        {{ $t['due_date'] }} : {{ $invoice->due_date?->format('d/m/Y') }}<br>
                        {{ $t['status'] }} : {{ strtoupper($statusLabel) }}
                    </div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>{{ $t['designation'] }}</th>
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
                        <td class="num">{{ $item->discount_percent > 0 ? $item->discount_percent . '%' : '—' }}</td>
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

        @if($invoice->notes || $invoice->terms)
            <div style="margin-top:20px; font-size:10px; color:#6b7280;">
                @if($invoice->notes)<div>{{ $invoice->notes }}</div>@endif
                @if($invoice->terms)<div style="margin-top:4px;">{{ $invoice->terms }}</div>@endif
            </div>
        @endif

        <table class="signature">
            <tr>
                <td>{{ $t['company_stamp'] }}</td>
                <td>{{ $t['client_signature'] }}</td>
            </tr>
        </table>
    </div>

    @if($company->bank_account_number)
        <div class="footer">{{ $t['bank_details'] }} : {{ $company->bank_account_number }}</div>
    @endif
</body>
</html>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'DejaVu Serif', serif;
            font-size: 12px;
            color: #1a1a1a;
            margin: 0;
        }
        .outer-border {
            border: 2px solid #1a1a1a;
            padding: 24px;
        }
        .header {
            width: 100%;
            text-align: center;
            border-bottom: 3px double #1a1a1a;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .logo { max-height: 60px; max-width: 160px; margin-bottom: 8px; }
        .company-name { font-size: 18px; font-weight: bold; letter-spacing: 1px; }
        .invoice-title {
            font-size: 20px;
            letter-spacing: 4px;
            margin-top: 10px;
            text-transform: uppercase;
        }
        .meta {
            width: 100%;
            margin-bottom: 20px;
        }
        .meta td { vertical-align: top; width: 50%; padding: 4px 0; }
        .meta .label { font-weight: bold; text-transform: uppercase; font-size: 10px; color: #4a4a4a; }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            border: 1px solid #1a1a1a;
        }
        table.items th {
            border: 1px solid #1a1a1a;
            padding: 8px;
            text-align: left;
            font-size: 11px;
            background-color: #f0f0f0;
        }
        table.items td {
            border: 1px solid #1a1a1a;
            padding: 7px 8px;
        }
        table.items th.num, table.items td.num { text-align: right; }
        .totals { width: 45%; margin-left: 55%; margin-top: 14px; border-collapse: collapse; }
        .totals td { padding: 5px 8px; border-bottom: 1px solid #ccc; }
        .totals .total-row td { font-weight: bold; font-size: 14px; border-top: 2px solid #1a1a1a; border-bottom: none; }
        .footer {
            margin-top: 30px;
            padding-top: 12px;
            border-top: 1px solid #1a1a1a;
            font-size: 10px;
            color: #4a4a4a;
        }
        .signature {
            margin-top: 40px;
            width: 100%;
        }
        .signature td { width: 50%; text-align: center; padding-top: 30px; border-top: 1px solid #1a1a1a; font-size: 10px; }
    </style>
</head>
<body>
    <div class="outer-border">
        <div class="header">
            @if($logoDataUri)<img src="{{ $logoDataUri }}" class="logo" alt="Logo"><br>@endif
            <div class="company-name">{{ $company->name }}</div>
            @if($company->headquarters)<div>{{ $company->headquarters }}</div>@endif
            <div class="invoice-title">{{ $t['invoice'] }}</div>
        </div>

        <table class="meta">
            <tr>
                <td>
                    <div class="label">{{ $t['billed_to'] }}</div>
                    <strong>{{ $invoice->client->name }}</strong><br>
                    @if($invoice->client->email){{ $invoice->client->email }}<br>@endif
                    @if($invoice->billing_address){{ nl2br(e($invoice->billing_address)) }}@endif
                </td>
                <td style="text-align: right;">
                    <div class="label">{{ $t['invoice_number'] }}</div>
                    {{ $invoice->invoice_number }}<br><br>
                    <div class="label">{{ $t['issued_date'] }}</div>
                    {{ $invoice->issued_at?->format('d/m/Y') }}<br><br>
                    <div class="label">{{ $t['due_date'] }}</div>
                    {{ $invoice->due_date?->format('d/m/Y') }}
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

        @if($invoice->notes || $invoice->terms || $company->bank_account_number)
            <div class="footer">
                @if($invoice->notes)<div>{{ $invoice->notes }}</div>@endif
                @if($invoice->terms)<div style="margin-top:4px;">{{ $invoice->terms }}</div>@endif
                @if($company->bank_account_number)<div style="margin-top:4px;">{{ $t['bank_details'] }} : {{ $company->bank_account_number }}</div>@endif
            </div>
        @endif

        <table class="signature">
            <tr>
                <td>{{ $t['company_stamp_signature'] }}</td>
                <td>{{ $t['agreed'] }}</td>
            </tr>
        </table>
    </div>
</body>
</html>

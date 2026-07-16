<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #262626;
            margin: 40px 48px;
        }
        .header { width: 100%; margin-bottom: 36px; }
        .header td { vertical-align: top; }
        .logo { max-height: 40px; max-width: 130px; margin-bottom: 6px; }
        .company-name { font-size: 13px; font-weight: bold; }
        .muted { color: #8c8c8c; }
        .invoice-title {
            font-size: 13px;
            letter-spacing: 3px;
            text-align: right;
            color: #8c8c8c;
        }
        .invoice-number { text-align: right; font-size: 15px; margin-top: 4px; }
        hr { border: none; border-top: 1px solid #e0e0e0; margin: 24px 0; }
        .meta { width: 100%; margin-bottom: 20px; }
        .meta td { vertical-align: top; width: 50%; font-size: 11px; }
        .meta .label { color: #8c8c8c; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 3px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th {
            text-align: left;
            padding: 6px 4px;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #8c8c8c;
            border-bottom: 1px solid #262626;
        }
        table.items td { padding: 8px 4px; border-bottom: 1px solid #f0f0f0; }
        table.items th.num, table.items td.num { text-align: right; }
        .totals { width: 40%; margin-left: 60%; margin-top: 16px; }
        .totals td { padding: 4px; font-size: 11px; }
        .totals .muted-label { color: #8c8c8c; }
        .totals .total-row td { font-size: 14px; padding-top: 10px; border-top: 1px solid #262626; }
        .footer { margin-top: 48px; font-size: 9px; color: #8c8c8c; }
        .signature { width: 100%; margin-top: 40px; }
        .signature td { width: 50%; text-align: center; padding-top: 24px; border-top: 1px solid #e0e0e0; font-size: 9px; color: #8c8c8c; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 60%;">
                @if($logoDataUri)<img src="{{ $logoDataUri }}" class="logo" alt="Logo"><br>@endif
                <div class="company-name">{{ $company->name }}</div>
                @if($company->headquarters)<div class="muted">{{ $company->headquarters }}</div>@endif
            </td>
            <td style="width: 40%;">
                <div class="invoice-title">{{ $t['invoice'] }}</div>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
            </td>
        </tr>
    </table>

    <hr>

    <table class="meta">
        <tr>
            <td>
                <div class="label">{{ $t['billed_to'] }}</div>
                {{ $invoice->client->name }}<br>
                @if($invoice->client->email)<span class="muted">{{ $invoice->client->email }}</span>@endif
            </td>
            <td style="text-align: right;">
                <div class="label">{{ $t['issued_short'] }}</div>
                {{ $invoice->issued_at?->format('d/m/Y') }}<br><br>
                <div class="label">{{ $t['due_short'] }}</div>
                {{ $invoice->due_date?->format('d/m/Y') }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>{{ $t['designation'] }}</th>
                <th class="num">{{ $t['qty'] }}</th>
                <th class="num">{{ $t['price_short'] }}</th>
                <th class="num">{{ $t['total'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->name_snapshot }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ $money($item->total_line) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted-label">{{ $t['subtotal'] }}</td><td class="num" style="text-align:right;">{{ $money($invoice->subtotal) }}</td></tr>
        @if($invoice->discount_amount > 0)
        <tr><td class="muted-label">{{ $t['discount'] }}</td><td class="num" style="text-align:right;">-{{ $money($invoice->discount_amount) }}</td></tr>
        @endif
        @if($invoice->tax_amount > 0)
        <tr><td class="muted-label">{{ $t['tax'] }}</td><td class="num" style="text-align:right;">{{ $money($invoice->tax_amount) }}</td></tr>
        @endif
        @if($invoice->shipping_fee > 0)
        <tr><td class="muted-label">{{ $t['shipping'] }}</td><td class="num" style="text-align:right;">{{ $money($invoice->shipping_fee) }}</td></tr>
        @endif
        <tr class="total-row"><td>{{ $t['total'] }}</td><td class="num" style="text-align:right;">{{ $money($invoice->total) }}</td></tr>
    </table>

    @if($invoice->notes || $invoice->terms || $company->bank_account_number)
        <div class="footer">
            @if($invoice->notes){{ $invoice->notes }}<br>@endif
            @if($invoice->terms){{ $invoice->terms }}<br>@endif
            @if($company->bank_account_number)<span class="muted">{{ $t['bank'] }} :</span> {{ $company->bank_account_number }}@endif
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

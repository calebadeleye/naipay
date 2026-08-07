<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $loan->loan_reference }} — Repayment schedule</title>
    <style>
        @page { margin: 32px 36px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; color: #1e293b; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .brand { font-size: 18px; font-weight: bold; color: #0f172a; }
        .tagline { font-size: 10px; color: #64748b; }
        h1 { font-size: 14px; margin: 18px 0 4px; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .meta td { padding: 2px 0; font-size: 11px; }
        .meta td.label { color: #64748b; width: 140px; }
        table.schedule { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.schedule th {
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            border-bottom: 1px solid #cbd5e1;
            padding: 6px 4px;
        }
        table.schedule td {
            padding: 6px 4px;
            border-bottom: 1px solid #e2e8f0;
        }
        .numeric { text-align: right; }
        .status { font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; }
        .status-paid { color: #15803d; }
        .status-partially_paid { color: #b45309; }
        .status-pending { color: #64748b; }
        .status-overdue { color: #b91c1c; }
        .footer { margin-top: 24px; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">{{ config('naipay.brand.name') }}</div>
        <div class="tagline">{{ config('naipay.brand.legal_name') }}</div>
    </div>

    <h1>Repayment schedule — {{ $loan->loan_reference }}</h1>

    <table class="meta">
        <tr>
            <td class="label">Merchant</td>
            <td>{{ $loan->merchant?->fullName() ?? '—' }}</td>
            <td class="label">Principal</td>
            <td>{{ $loan->principal_amount->format() }}</td>
        </tr>
        <tr>
            <td class="label">Business</td>
            <td>{{ $loan->business?->business_name ?? '—' }}</td>
            <td class="label">Interest rate</td>
            <td>{{ $loan->interest_rate }}%</td>
        </tr>
        <tr>
            <td class="label">Disbursed</td>
            <td>{{ $loan->disbursement_date?->toFormattedDateString() ?? '—' }}</td>
            <td class="label">Tenor</td>
            <td>{{ $loan->tenor }} ({{ $loan->repayment_frequency->value }})</td>
        </tr>
        <tr>
            <td class="label">Maturity</td>
            <td>{{ $loan->maturity_date?->toFormattedDateString() ?? '—' }}</td>
            <td class="label">Total payable</td>
            <td>{{ $loan->total_payable?->format() ?? '—' }}</td>
        </tr>
    </table>

    <table class="schedule">
        <thead>
            <tr>
                <th>#</th>
                <th>Due date</th>
                <th class="numeric">Principal</th>
                <th class="numeric">Interest</th>
                <th class="numeric">Total due</th>
                <th class="numeric">Paid</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($loan->scheduleEntries as $entry)
                <tr>
                    <td>{{ $entry->installment_number }}</td>
                    <td>{{ $entry->due_date->toFormattedDateString() }}</td>
                    <td class="numeric">{{ $entry->principal_due->format() }}</td>
                    <td class="numeric">{{ $entry->interest_due->format() }}</td>
                    <td class="numeric">{{ $entry->totalDue()->format() }}</td>
                    <td class="numeric">{{ $entry->principal_paid->plus($entry->interest_paid)->plus($entry->fee_paid)->format() }}</td>
                    <td class="status status-{{ $entry->status->value }}">{{ $entry->status->label() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generated {{ now()->toFormattedDateString() }} · {{ config('naipay.brand.name') }} ·
        {{ config('naipay.brand.support_email') }}
    </div>
</body>
</html>

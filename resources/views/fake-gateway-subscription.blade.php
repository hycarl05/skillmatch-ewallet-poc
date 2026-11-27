<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FakePay sandbox checkout</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-6 font-sans">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="bg-indigo-700 text-white px-6 py-4">
            <div class="text-xs uppercase tracking-wider text-indigo-200">Sandbox · no real money</div>
            <div class="text-lg font-semibold">FakePay checkout</div>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <div class="text-sm text-slate-500">Paying SkillMatch for</div>
                <div class="font-medium">{{ $invoice->subscription->plan->name }} subscription</div>
                <div class="text-sm text-slate-500">
                    {{ $invoice->period_start->format('j M Y') }} to {{ $invoice->period_end->format('j M Y') }} · {{ $invoice->subscription->user->name }}
                </div>
            </div>
            <div class="text-3xl font-semibold">RM {{ number_format($invoice->amount_sen / 100, 2) }}</div>

            @if ($invoice->status !== 'open')
                <div class="rounded-lg bg-slate-100 p-3 text-sm">This invoice is already <strong>{{ $invoice->status }}</strong>.</div>
                <a href="/plans" class="block text-center rounded-lg bg-slate-800 text-white py-2.5">Back to SkillMatch</a>
            @else
                <div class="rounded-lg border border-slate-200 p-3 text-sm text-slate-600">
                    Pretend bank: <strong>FPX · Maybank2u (sandbox)</strong>. FPX is a one-off payment, so each period gets its own invoice.
                </div>
                <form method="POST" action="{{ route('fake-gateway.subscription-invoice.complete', $invoice) }}" class="space-y-2">
                    @csrf
                    <button name="outcome" value="paid" class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white py-2.5 font-medium">Approve payment</button>
                    <button name="outcome" value="failed" class="w-full rounded-lg bg-white hover:bg-slate-50 border border-slate-300 py-2.5 font-medium">Decline payment</button>
                </form>
            @endif
        </div>
    </div>
</body>
</html>

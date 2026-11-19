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
                <div class="font-medium">{{ $payment->order->training->title }}</div>
                <div class="text-sm text-slate-500">Order {{ $payment->order->reference }} · {{ $payment->order->buyer->name }}</div>
            </div>
            <div class="text-3xl font-semibold">RM {{ number_format($payment->amount_sen / 100, 2) }}</div>

            @if ($payment->status !== 'pending')
                <div class="rounded-lg bg-slate-100 p-3 text-sm">This payment is already <strong>{{ $payment->status }}</strong>.</div>
                <a href="{{ route('payment.return', $payment) }}" class="block text-center rounded-lg bg-slate-800 text-white py-2.5">Back to SkillMatch</a>
            @else
                <div class="rounded-lg border border-slate-200 p-3 text-sm text-slate-600">
                    Pretend bank: <strong>FPX · Maybank2u (sandbox)</strong>. Pick what the bank answers.
                </div>
                <form method="POST" action="{{ route('fake-gateway.complete', $payment) }}" class="space-y-2">
                    @csrf
                    <button name="outcome" value="paid" class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white py-2.5 font-medium">Approve payment</button>
                    <button name="outcome" value="failed" class="w-full rounded-lg bg-white hover:bg-slate-50 border border-slate-300 py-2.5 font-medium">Decline payment</button>
                    <label class="flex items-center gap-2 text-sm text-slate-600 pt-2">
                        <input type="checkbox" name="duplicate" value="1" class="rounded">
                        Also send the webhook twice (tests duplicate handling)
                    </label>
                </form>
            @endif
        </div>
    </div>
</body>
</html>

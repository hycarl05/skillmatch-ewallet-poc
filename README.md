# SkillMatch e-Wallet PoC

A standalone proof of concept for SkillMatch payments, built with **Laravel 13 + React**.

**The rule it follows: every transaction happens in SkillMatch, but SkillMatch never holds the money.**
A payment gateway (Billplz, CHIP, …) collects the money and pays it out. SkillMatch only records what
happened, in a double-entry ledger, and shows each user a wallet view of it. SkillPoints are a discount,
not stored money, so they can't be withdrawn.

## What it shows

| Flow | Example in the demo data |
|---|---|
| Individual buys a training | Aisyah buys *Laravel for Beginners* from freelance trainer Hafiz |
| Company HR pays for a staff member | Nadia (HR, Maju Jaya) buys *Excel for HR Teams* for Farid |
| Training provider hires a freelance trainer | Akademi Digital buys Priya's *Public Speaking Coaching* (provider pays freelancer as a normal order) |
| 3-way split | *Leadership Essentials*: sold by Akademi Digital, delivered by Priya. Each sale is split provider / freelancer / SkillMatch |

Each order can run in one of two **payout modes**. This is the main business decision, and the PoC lets you compare both:

- **instant**: the gateway splits the payment the moment it's collected, so trainers are paid immediately (Billplz / ToyyibPay split payment). Simple, but if the buyer later cancels, the trainer has already been paid and owes the money back. The PoC shows this as `clawback_required`.
- **deferred**: the trainer's share is **held** until the training is completed and confirmed, then **paid out** (Stripe Connect / Xendit xenPlatform / CHIP Collect + Send). Safer for buyers, but needs a gateway that can hold and release money.

### Order lifecycle

```
pending_payment ──(gateway webhook: paid)──> paid ──(trainer: mark completed)──> completed ──(buyer confirms or auto-confirm after 3 days)──> confirmed
       │                                       │                                     │
       └──(webhook: failed)──> failed          └───────────────(full refund or lost chargeback)───────────────┴──> refunded
```

- An order is marked paid **only by the gateway's signed webhook**, never by the browser returning to a "success" page.
- Webhooks are stored with a unique key, so a webhook the gateway retries is applied once.
- All money is integer **sen** (RM1.00 = 100), never floats.
- Every money movement is a balanced ledger transaction (debits = credits). The *Platform ledger* page proves the books balance.
- Refunds go through **request → approve**. The buyer asks (any time before confirmation, or within 14 days after); SkillMatch approves (possibly for less) or rejects with a note. SkillMatch can also refund directly. Partial refunds are taken from every share **pro rata**, so trainers and SkillMatch give back the same proportion. A full refund returns the SkillPoints used and takes back the ones earned.
- **Refund rules.** The gateway keeps its processing fee when money is refunded, so every refund carries its share of it (fee × refunded ÷ paid). When approving, SkillMatch picks the reason: **buyer changed their mind** (fee deducted from the refund), **trainer cancelled or didn't deliver** (buyer gets everything; the trainer owes the fee and it's kept from their next payout) or **goodwill** (SkillMatch pays it). Buyers can ask for up to 100% before the trainer marks the training completed and up to 50% after (`SKILLMATCH_REFUND_MAX_*`); SkillMatch can refund more itself.
- FakePay charges a simulated fee (3% + RM1, `FAKE_GATEWAY_FEE_*`) so this can be tried locally.
- A refund the gateway rejects, or can't do by API (Billplz FPX), stays **owed** to the buyer (`refunds_owed`) until SkillMatch retries or marks it sent. Stripe refund webhooks (`refund.updated`) move a pending refund to succeeded, or back to owed if it fails later.
- A refund made **in the Stripe dashboard** arrives by webhook (`refund.created`) and is recorded like a SkillMatch refund: shares, ledger and clawbacks. So the books match Stripe even when someone skips the SkillMatch admin page. Refunds SkillMatch makes carry `skillmatch_refund_id` in their Stripe metadata, so they're never recorded twice.
- Refunding after the trainer was paid **takes their share back** with a payout reversal (Stripe Connect transfer reversal) in deferred mode. With instant split the gateway paid them, so it stays `clawback_required` for SkillMatch to collect.
- A **chargeback** pauses payouts and refunds for that order. Won: payouts resume. Lost: it's booked as a refund the card network already made.
- SkillPoints discounts come out of SkillMatch's commission, so a trainer's share never shrinks. This means the discount is capped at the platform fee (10% by default).

## Run it locally

Requirements: PHP 8.4+, Composer, Node 22+.

```bash
git clone https://github.com/hycarl05/skillmatch-ewallet-poc.git
cd skillmatch-ewallet-poc
composer setup        # once: install, .env, key, SQLite DB, migrate + seed, build frontend
composer dev          # every time: Laravel server, Vite and the queue worker together
```

Open http://127.0.0.1:8010. **Ctrl+C** stops everything. Use the *Acting as* menu (top right) to switch between demo users; **SkillMatch Admin** opens on the dashboard.

| What | Where |
|---|---|
| App | http://127.0.0.1:8010 (`SERVER_PORT` in `.env`) |
| Vite dev server | 127.0.0.1:5174 (fixed in `vite.config.js`) |

These aren't Laravel's defaults (8000 / 5173), so this app can run next to another project that uses those.

To fill the admin dashboard with about 60 days of orders (paid, confirmed, failed, refunded, a few refund requests waiting): `php artisan db:seed --class=DemoOrdersSeeder`. They go through the real order and refund flows on FakePay, so the ledger balances. It adds to what's there and runs only once.

To start again with fresh data: `php artisan migrate:fresh --seed` (add the demo orders again afterwards if you want them).

Run the tests: `php artisan test`. They also run on GitHub Actions on every push.

#### Sharing on your Wi-Fi

To let someone on the same network open your copy, set `VITE_DEV_HOST` in `.env` to your Wi-Fi IP (`ipconfig` → *Wireless LAN adapter Wi-Fi → IPv4 Address*) and restart `composer dev`. They open `http://<that-ip>:8010`. Clear it again afterwards: if your IP changes, the page goes blank because it looks for Vite at the old address.

#### Blank page?

- **`composer dev` isn't running**, or one of its processes stopped. Restart it.
- **A port is taken.** Another `composer dev` is still open in a different terminal. Close it, or restart VS Code. VS Code's *Ports* tab can also hold a port it forwarded; remove it there.
- **Vite crashed and left `public/hot` behind.** Delete that file, or run `npm run build`, and the page uses the built assets instead.
- **Check the browser console** (F12). Requests failing to `:5174` mean Vite isn't reachable.

### Demo walkthrough (no gateway account needed)

The built-in **FakePay** simulator behaves like a real gateway: hosted payment page, signed webhooks, refunds.

1. Acting as **Aisyah**: buy *Laravel for Beginners*, use some SkillPoints, choose *FakePay* and payout mode **deferred** → **Pay**.
2. On the FakePay page, tick *send the webhook twice* and click **Approve payment**. The order is `paid`, the trainer's share is `held`, and the duplicate webhook is ignored.
3. Switch to **Hafiz** → *Orders* → open the order → **Mark training completed**. His *Wallet* shows the money as **Held**.
4. Switch back to **Aisyah** → **Confirm training received**. The share is released and paid out, and Aisyah earns SkillPoints.
5. Switch to **SkillMatch Admin** → *Platform ledger*: the books balance, and money at the gateway is back to RM0.00.
6. On a new order, as **Aisyah** click **Request refund** (try half the amount). Switch to **SkillMatch Admin**: the request is on *Platform ledger → Refunds to handle*. Approve it, or approve less, and see how each share gave back its part.
7. Refund a **confirmed** deferred order: the trainer's payout is reversed (`fake_reversal_…`).
8. Repeat with payout mode **instant** and refund: the trainer was already paid by the gateway, so the share is `clawback_required`.
9. As **SkillMatch Admin**, open the **Dashboard** and click the tallest bar to drill into that day (see below).

## Admin dashboard

**SkillMatch Admin** → *Dashboard* (the admin's start page) shows what's happening and what needs attention.

- **Needs attention:** refund requests to decide, refunds owed to buyers, trainer shares to claw back, open chargebacks, money held for trainers, and whether the ledger balances. A tile turns amber when there's something to do, and links to *Platform ledger*.
- **Totals for the period:** money collected, SkillMatch commission (after SkillPoints discounts and refunds), refunded, and orders. Click a total to make the main chart show it.
- **Period:** 7, 30 or 90 days (one bar per day) or 12 months (one bar per month). Days are counted in Malaysian time.

**Drill down** by clicking a bar:

```
Collected by day  ──click a bar──>  that day by training / trainer / buyer / gateway / status  ──click a row──>  the orders
```

The breadcrumb above the chart (*Collected by day › Fri, 20 Nov › Training: Leadership Essentials*) goes back up. *Break down the whole period* skips the day step. The side charts (orders by status, collected by gateway, top trainings, top trainers) open their orders directly. **Show table** gives the exact numbers instead of the chart, and the bars work with the keyboard (Tab, Enter).

"Collected" counts only orders the gateway confirmed as paid; unpaid and failed orders count as RM0 but still appear under *Orders*.

The data comes from `GET /api/platform/dashboard?days=30` (admin only): one row per order plus the attention counts. The browser does the grouping, which is fine for a PoC. With thousands of orders a month the totals should move to the server.

## Using the real sandboxes

The gateway can be chosen at checkout. FakePay always works; Billplz and CHIP need sandbox keys in `.env`.

Gateways must be able to reach your webhook URL, so expose your local app with a tunnel:

```bash
ngrok http 8010        # then set APP_URL=https://<your-id>.ngrok-free.app in .env
```

### Billplz sandbox (instant split)
1. Sign up at https://www.billplz-sandbox.com.
2. Create a **collection**. To test split payment, add the split rule (recipients) to the collection in the dashboard.
3. From *Settings*, copy the **API Secret Key** and **X Signature Key** and turn X Signature on.
4. Fill in `BILLPLZ_API_KEY`, `BILLPLZ_X_SIGNATURE`, `BILLPLZ_COLLECTION_ID` in `.env`.

Billplz can't refund FPX automatically, so a refund is recorded as `manual_required`. Finish it from the Billplz dashboard (Payment Order).

### CHIP (deferred payout)
1. Get **test** keys from the CHIP merchant portal: Brand ID and Secret Key.
2. Copy CHIP's **public key** (for verifying callbacks) into `CHIP_PUBLIC_KEY`, in double quotes, with `\n` for line breaks.
3. Fill in `CHIP_BRAND_ID`, `CHIP_SECRET_KEY`.

Payouts to trainers still go through the fake payout provider. Real payouts need a **CHIP Send** account, which CHIP opens for you. With CHIP Collect + Send, the money sits in SkillMatch's CHIP balance until it's paid out, so management must be comfortable with that.

### Stripe (deferred payout with Connect)
Uses Stripe Checkout plus Connect *separate charges and transfers*: the buyer pays SkillMatch's Stripe account, and each trainer's share is transferred to their **Express** connected account when the training is confirmed.

1. Put your **test** keys in `STRIPE_KEY` and `STRIPE_SECRET_KEY`.
2. Forward webhooks locally (no tunnel needed): `stripe listen --forward-to localhost:8010/api/webhooks/stripe --events checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired,refund.created,refund.updated,refund.failed,charge.dispute.created,charge.dispute.updated,charge.dispute.closed`, then copy the printed `whsec_…` into `STRIPE_WEBHOOK_SECRET`.
3. Turn on Connect in the dashboard, and **FPX** (plus GrabPay if you like) under *Settings → Payment methods*. Checkout uses Stripe's dynamic payment methods, so it shows whatever is enabled there. An FPX payment can finish after the buyer returns: the order stays pending until `checkout.session.async_payment_succeeded` (or `_failed`) arrives. Once paid, the order page shows the FPX details FPX requires on the confirmation page: bank, FPX transaction ID, Stripe charge ID, amount, date and status.
4. As a trainer (Hafiz, Priya, Akademi Digital): *Wallet* → **Set up payouts** → finish Stripe's test onboarding.
5. Buy a training with *Stripe*, pay with card `4242 4242 4242 4242` (or choose FPX and any test bank, then authorize), complete and confirm it: the shares go out as Stripe transfers (`tr_…` in the split's payout reference).

As with CHIP, the money sits in SkillMatch's Stripe balance until confirmation. A trainer who hasn't finished onboarding keeps the share in `payable`, and `payOutReleased` retries it later.

## Where things are

| | |
|---|---|
| `app/Services/OrderService.php` | The order lifecycle: create, paid, complete, confirm, payout, auto-confirm |
| `app/Services/RefundService.php` | Refund requests, approval, pro-rata split, gateway refund, retries, clawbacks, refund and dispute webhooks |
| `app/Services/Ledger.php` | Double-entry ledger: refuses unbalanced transactions |
| `app/Payments/PaymentGateway.php` | The interface every gateway implements |
| `app/Payments/Gateways/` | `FakeGateway` (simulator), `BillplzGateway`, `ChipGateway` |
| `app/Payments/Payouts/` | Payout providers: fake, and Stripe Connect transfers (both can reverse a payout) |
| `app/Http/Controllers/WebhookController.php` | Signature check, duplicate protection, applies webhooks |
| `config/skillmatch.php` | Fee %, SkillPoints rules, gateways and which payout modes each supports |
| `database/migrations/2026_11_19_000001_create_ewallet_tables.php` | Orders, splits, payments, refunds, webhook events, ledger, SkillPoints |
| `app/Http/Controllers/Api/DashboardController.php` | Admin dashboard data: orders in the period and needs-attention counts |
| `resources/js/` | React app: trainings, orders, order detail with ledger, wallet, platform ledger, admin dashboard |
| `resources/js/charts.jsx` | Dependency-free SVG bar charts used by the dashboard (`pages/Dashboard.jsx`) |
| `tests/` | Feature tests for every flow above, plus Billplz signature tests |

### Ledger accounts

| Account | Meaning |
|---|---|
| `gateway_clearing` | Money the gateway is holding |
| `held:{user}` | Belongs to a trainer, waiting for training confirmation |
| `payable:{user}` | Released to a trainer, payout in progress |
| `receivable:{user}` | A trainer owes this back (refund after they were paid, until the payout is reversed) |
| `refunds_owed` | Refunds approved but not yet sent to the buyer (failed or manual) |
| `refund_fees_recovered` | Processing fees on refunds that the buyer or trainer paid instead of SkillMatch |
| `fee_debt:{user}` | Refund fees a trainer owes; kept back from their next payout |
| `platform_revenue` / `platform_bank` | SkillMatch's commission, and the part already settled to SkillMatch's bank |
| `skillpoints_discount` | Discounts SkillMatch has given |

## PoC limitations / next steps

- **Login is a demo user switcher** (`X-Demo-User` header). It is disabled in production. Use SkillMatch's real auth when merging.
- Payouts use a fake provider except on Stripe. Next: CHIP Send or Xendit payouts, depending on the gateway chosen.
- Disputes are handled for Stripe only. Evidence is submitted in the Stripe dashboard, and Stripe's dispute fee isn't booked yet.
- A `clawback_required` share that couldn't be reversed (instant split, or the trainer's Stripe balance already paid to their bank) is collected outside the app.
- E-invoices and receipts for company buyers (FPX B2B) are not covered yet.
- Before going live, the company should confirm with the chosen gateway (and ideally a lawyer) that this model doesn't make SkillMatch an e-money issuer under BNM rules.

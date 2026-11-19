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
       └──(webhook: failed)──> failed          └──(buyer cancels)──> refunded        └──(SkillMatch admin, dispute)──> refunded
```

- An order is marked paid **only by the gateway's signed webhook**, never by the browser returning to a "success" page.
- Webhooks are stored with a unique key, so a webhook the gateway retries is applied once.
- All money is integer **sen** (RM1.00 = 100), never floats.
- Every money movement is a balanced ledger transaction (debits = credits). The *Platform ledger* page proves the books balance.
- SkillPoints discounts come out of SkillMatch's commission, so a trainer's share never shrinks. This means the discount is capped at the platform fee (10% by default).

## Run it locally

Requirements: PHP 8.3+, Composer, Node 22+.

```bash
git clone https://github.com/hycarl05/skillmatch-ewallet-poc.git
cd skillmatch-ewallet-poc
composer setup        # install, .env, key, SQLite DB, migrate + seed, build frontend
composer dev          # runs the Laravel server and Vite together
```

Open http://localhost:8000. To start again with fresh data: `php artisan migrate:fresh --seed`.

Run the tests: `php artisan test`. They also run on GitHub Actions on every push.

### Demo walkthrough (no gateway account needed)

The built-in **FakePay** simulator behaves like a real gateway: hosted payment page, signed webhooks, refunds.

1. Acting as **Aisyah**: buy *Laravel for Beginners*, use some SkillPoints, choose *FakePay* and payout mode **deferred** → **Pay**.
2. On the FakePay page, tick *send the webhook twice* and click **Approve payment**. The order is `paid`, the trainer's share is `held`, and the duplicate webhook is ignored.
3. Switch to **Hafiz** → *Orders* → open the order → **Mark training completed**. His *Wallet* shows the money as **Held**.
4. Switch back to **Aisyah** → **Confirm training received**. The share is released and paid out, and Aisyah earns SkillPoints.
5. Switch to **SkillMatch Admin** → *Platform ledger*: the books balance, and money at the gateway is back to RM0.00.
6. Repeat with payout mode **instant**, then **Cancel & refund**, to see the instant-split refund problem (`clawback_required`).

## Using the real sandboxes

The gateway can be chosen at checkout. FakePay always works; Billplz and CHIP need sandbox keys in `.env`.

Gateways must be able to reach your webhook URL, so expose your local app with a tunnel:

```bash
ngrok http 8000        # then set APP_URL=https://<your-id>.ngrok-free.app in .env
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

## Where things are

| | |
|---|---|
| `app/Services/OrderService.php` | The whole lifecycle: create, paid, complete, confirm, payout, refund, auto-confirm |
| `app/Services/Ledger.php` | Double-entry ledger: refuses unbalanced transactions |
| `app/Payments/PaymentGateway.php` | The interface every gateway implements |
| `app/Payments/Gateways/` | `FakeGateway` (simulator), `BillplzGateway`, `ChipGateway` |
| `app/Payments/Payouts/` | Payout providers (fake for now) |
| `app/Http/Controllers/WebhookController.php` | Signature check, duplicate protection, applies webhooks |
| `config/skillmatch.php` | Fee %, SkillPoints rules, gateways and which payout modes each supports |
| `database/migrations/2026_11_19_000001_create_ewallet_tables.php` | Orders, splits, payments, refunds, webhook events, ledger, SkillPoints |
| `resources/js/` | React app: trainings, orders, order detail with ledger, wallet, platform ledger |
| `tests/` | Feature tests for every flow above, plus Billplz signature tests |

### Ledger accounts

| Account | Meaning |
|---|---|
| `gateway_clearing` | Money the gateway is holding |
| `held:{user}` | Belongs to a trainer, waiting for training confirmation |
| `payable:{user}` | Released to a trainer, payout in progress |
| `receivable:{user}` | A trainer owes this back (refund after instant split) |
| `platform_revenue` / `platform_bank` | SkillMatch's commission, and the part already settled to SkillMatch's bank |
| `skillpoints_discount` | Discounts SkillMatch has given |

## PoC limitations / next steps

- **Login is a demo user switcher** (`X-Demo-User` header). It is disabled in production. Use SkillMatch's real auth when merging.
- Payouts use a fake provider. Next: CHIP Send, Xendit payouts or Stripe Connect transfers, depending on the gateway chosen.
- Partial refunds, disputes and trainer clawback collection are recorded but not automated.
- E-invoices and receipts for company buyers (FPX B2B) are not covered yet.
- Before going live, the company should confirm with the chosen gateway (and ideally a lawyer) that this model doesn't make SkillMatch an e-money issuer under BNM rules.

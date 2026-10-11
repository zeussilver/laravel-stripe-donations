# Donate Demo — Laravel + Stripe donation form

A small donation page for a not-for-profit: one-off or monthly gifts, paid through Stripe Checkout (test mode), with payment status driven by Stripe webhooks and an emailed receipt for every successful charge.

## How it works

1. The donor picks **Give once** or **Monthly** and an amount. Laravel validates the form and saves a `pending` donation.
2. The app creates a Stripe Checkout Session (`payment` mode for one-off, `subscription` mode for monthly) and redirects the donor to Stripe's hosted page. Card details never touch this server.
3. Stripe calls `POST /stripe/webhook`. The app verifies the signature, ignores events it has already processed, then:
   - `checkout.session.completed` → one-off donation marked `paid`; monthly donation linked to its subscription
   - `invoice.paid` → one payment recorded per month
   - `invoice.payment_failed` → `past_due`; `customer.subscription.deleted` → `cancelled`; `checkout.session.expired` → `expired`
4. Every new payment row queues a receipt email. A replayed webhook never sends a second receipt.

## Run locally

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
# add STRIPE_SECRET to .env, then in three terminals:
php artisan serve
php artisan queue:work
stripe listen --events checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.expired,invoice.paid,invoice.payment_failed,customer.subscription.deleted \
  --forward-to localhost:8000/stripe/webhook   # copy the whsec_ into .env
```

Pay with test card `4242 4242 4242 4242`, any future date and any CVC. Receipts are written to `storage/logs/laravel.log`.

## Tests

```bash
php artisan test
```

Feature tests cover form validation, the Stripe Checkout request for each frequency, signature rejection, one-off and monthly receipts, duplicate-event idempotency, cancellation and expiry. Stripe is faked in tests; webhook payloads are signed the same way Stripe signs them.

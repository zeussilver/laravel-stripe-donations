# Donate Demo — Laravel + Stripe donation form

A small donation page for a not-for-profit: one-off or monthly gifts, paid through Stripe Checkout (test mode), with payment status driven by Stripe webhooks and an emailed receipt for every successful charge.

**Live demo:** https://dl45ye3zyfeko.cloudfront.net, running on AWS in Stripe test mode. No real money moves: pay with `4242 4242 4242 4242`, any future date and any CVC.

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

Feature tests cover form validation, rate limiting, the Stripe Checkout request for each frequency, signature rejection, one-off and monthly receipts, duplicate-event idempotency, cancellation and expiry. Stripe is faked in tests; webhook payloads are signed the same way Stripe signs them.

Both flows were also run end to end against Stripe's sandbox, locally and on the live site: a one-off gift, a monthly gift, a redelivered event and a cancellation. Those runs caught two bugs the faked tests could not: a donation sent to Stripe without its default currency, and production logging that dropped every receipt.

## Deploy to AWS

[`deploy/aws/template.yml`](deploy/aws/template.yml) is a CloudFormation stack in `ap-southeast-2`:

- **EC2** (`t4g.micro`, Amazon Linux 2023) runs nginx, PHP-FPM and the queue worker as a systemd service.
- **CloudFront** in front provides HTTPS. The instance's security group accepts HTTP only from CloudFront's origin-facing prefix list, and there is no SSH: administration goes through SSM Run Command.
- **Secrets** live in SSM Parameter Store under `/donate/` (SecureString). The instance's IAM role can read only that path.
- [`deploy/aws/provision.sh`](deploy/aws/provision.sh) installs packages on first boot, writes `.env` from Parameter Store and migrates. Run it again to deploy.
- Receipts go to the log, so a public demo never emails an address a visitor typed in. `POST /donate` is limited to 10 submissions a minute per visitor.

```bash
export AWS_REGION=ap-southeast-2
aws ssm put-parameter --name /donate/stripe-secret --type SecureString --value sk_test_...
PL=$(aws ec2 describe-managed-prefix-lists --filters Name=prefix-list-name,Values=com.amazonaws.global.cloudfront.origin-facing --query 'PrefixLists[0].PrefixListId' --output text)
aws cloudformation deploy --stack-name donate-demo --template-file deploy/aws/template.yml \
  --capabilities CAPABILITY_IAM --parameter-overrides CloudFrontPrefixListId=$PL
aws cloudformation describe-stacks --stack-name donate-demo --query 'Stacks[0].Outputs'   # SiteUrl, InstanceId
```

Then store the site URL, create a Stripe webhook endpoint for `<SiteUrl>/stripe/webhook` with the six events above, store its signing secret, and redeploy:

```bash
aws ssm put-parameter --name /donate/app-url --type String --value https://xxxx.cloudfront.net
aws ssm put-parameter --name /donate/stripe-webhook-secret --type SecureString --value whsec_...
aws ssm send-command --instance-ids <InstanceId> --document-name AWS-RunShellScript \
  --parameters 'commands=["git -C /var/www/donate pull --ff-only","bash /var/www/donate/deploy/aws/provision.sh"]'
```

`aws cloudformation delete-stack --stack-name donate-demo` removes everything except the parameters.

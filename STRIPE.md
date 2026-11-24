# Stripe Checkout

This version uses Stripe-hosted Checkout for one-time card payments in EUR. PHP requires cURL and OpenSSL, outbound HTTPS to api.stripe.com, and the existing writable data directory. No Composer installation is needed on the server. API requests use version `2025-02-24.acacia`.

Upload the complete system directory, retaining the server's existing sibling data directory. Do not upload development seed data. Existing Stripe keys and mail settings are preserved.

## Configuration

In administration, save the HTTPS base URL of the system directory, for example `https://examina.niagab.se/system`. Return addresses come from this saved setting, not the request Host header.

Save the sandbox API secret and sandbox webhook signing secret in their respective fields. A secret test key (`sk_test_`) or restricted test key (`rk_test_`) with Checkout Sessions read/write permissions is supported. The hosted redirect flow does not need the public key, but its existing setting remains available. Never commit keys or data/settings.json to GitHub.

Create a Stripe webhook destination for:

`https://examina.niagab.se/system/payment.php?action=webhook`

Select events from your account: `checkout.session.completed` and `checkout.session.async_payment_succeeded`. Use snapshot events containing the Checkout Session object. Save its `whsec_` signing secret under the matching sandbox/live configuration. Both environments can share the URL with separate signing secrets.

Set the test price to at least 0.50 EUR and choose Stripe sandbox. The configured price is the full charged amount; automatic tax calculation and additional fees are not added. Verify the appropriate price/tax treatment before accepting real payments. Prices and purchased access terms are captured when Checkout is created.

## Verification before live use

Complete a Stripe sandbox card payment and check return to the participant details form, test start, configured attempts/time limit, and the webhook delivery's successful response in Stripe. Check cancellation and retry too. Real account credentials have not been used in automated tests.

Once sandbox testing succeeds, use the live account's corresponding key and webhook secret and explicitly select Stripe Live. Live Checkout charges real money. Keep sandbox secrets until pending sandbox payments have been handled.

## Recovery and storage

A return URL never grants access by itself. A signed, timestamp-checked webhook or a server-side retrieval of the Checkout Session must confirm paid status, environment, order, currency and exact amount. Fulfillment uses per-order and per-grant locks and a stable grant ID, so duplicate or concurrent deliveries cannot reset attempts. Creation retries reuse the saved request and Stripe idempotency key.

Orders are stored under data/payments, protected by data/.htaccess. They are removed by existing maintenance after access expiry plus the configured retention interval (unpaid orders after one day plus that interval). Stripe's own records have separate retention. Do not delete pending orders manually.

The purchaser returns in the same browser session. If the browser session is lost, payment is still recorded by the webhook, but the user must contact NIAG to restore access; verify payment in Stripe before issuing a replacement invitation. Refunds and disputes are handled manually in Stripe; this version does not automatically revoke test access. Disabled/deleted tests are not automatically re-enabled after payment.

## Automated checks

Run `php tests/stripe.php` from the repository root (or `php system/tests/stripe.php` from the outer development project). These checks use disposable synthetic data and a simulated Stripe transport, including duplicate/concurrent events, invalid signatures, wrong amounts/environments, retry recovery and browser ownership. They never send payments or email.

Additional checks: `python3 tests/stripe_http.py` exercises the real HTTP endpoints using a local PHP server; `node tests/stripe_ui.cjs` checks payment UI routing. Neither contacts Stripe.

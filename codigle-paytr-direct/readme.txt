=== Codigle PayTR Direct ===
Contributors: codigle
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.5.5

PayTR Direct API checkout, card storage, subscription activation and audited customer subscription management.

== Safety ==
* Card number and CVV are posted directly from the browser to PayTR.
* Merchant credentials are inherited from the installed official PayTR plugin.
* utoken and ctoken values are encrypted with Sodium.
* Rollout defaults to administrators only.
* The official iFrame plugin files are not modified.
* The existing merchant callback URL is preserved and CDG order IDs are routed
  to this plugin before non-CDG callbacks continue to the official plugin.

== PayTR callback ==
Keep the existing official WooCommerce callback URL:
https://codigle.com/index.php?wc-api=wc_gateway_paytrcheckout

== Version 0.1.0 ==
* WooCommerce classic and block checkout gateway.
* Dedicated secure payment page.
* New card storage and existing PayTR card listing.
* Verified idempotent callback.
* Subscription activation and My Account subscription list.
* Automatic recurring charging is intentionally not enabled until the first
  real 3D callback and token/card mapping are verified.


= 0.1.1 =
* Preserve actual Direct API client IP, including IPv6.
* Add local token contract verification and safe signature diagnostics.


= 0.1.2 =
* Enforce PayTR Direct API's documented IPv4 user_ip contract.
* Preserve real customer IPv4 when available and use configured server IPv4 only for IPv6-only clients.


= 0.1.3 =
* Remove unsigned optional request_exp_date from the Direct API POST.
* Keep the basic payment request aligned with PayTR's official sample.


= 0.1.4 =
* Use the verified native cURL transport for PayTR CAPI LIST.
* Retry transient CAPI transport failures without exposing tokens.
* Rename the CLI option to --customer_id to avoid WP-CLI's global --user option.
* Retry asynchronous card synchronization after a successful initial payment.


= 0.1.5 =
* Remove the forced IPv4 route from PayTR CAPI LIST.
* Match the exact native cURL transport proven to return HTTP 200 on the live server.


= 0.2.0 =
* Add registered-card recurring Non3D payment engine.
* Add test-only recurring charge command that does not advance the subscription.
* Add renewal orders, callback-driven period advancement, past_due state and retries.
* Add Action Scheduler sweep, idempotent locks and ambiguous-response reconciliation.
* Add PayTR Status Inquiry support.
* Upgrade CAPI LIST to the proven 0/2/5/10 second retry policy.
* Keep automatic renewal mode on Manual after installation until the recurring test passes.


= 0.2.1 =
* Treat immediate failed + try_again=false as a terminal failure.
* Do not incorrectly wait for a callback after a terminal rejection.
* Capture a strict whitelist of safe PayTR diagnostic response fields.
* Isolate each explicit recurring CLI test in a new WooCommerce order.
* Add repair_attempt for historical immediate-failure state repair.


= 0.3.0 =
* Add an administrator-first Hostinger-style in-page subscription checkout.
* Add account email verification for public checkout rollout.
* Add saved-card and new-card selection in the same checkout panel.
* Post PAN and CVV directly from the browser to PayTR, never through Codigle REST.
* Add WooCommerce billing profile capture and exact address-based order totals.
* Add legal document snapshots and immutable checkout consent evidence.
* Add customer/IP/session evidence hashes and checkout authorization rate limiting.
* Keep classic WooCommerce checkout as a fallback and keep recurring mode Manual.

= 0.4.0 =
* Add customer-owned REST endpoints for subscription detail and upgrade options.
* Add nonce, verified-email, ownership, rate-limit and idempotency guards for every customer write.
* Add automatic-renewal enable/disable, period-end cancellation and reactivation services.
* Add manual Renew now through saved-card recurring payment with callback-only period advancement.
* Add next-period billing-cycle and plan-change scheduling.
* Add prorated immediate upgrades with unused-period credit, Plan Builder policy and callback-only activation.
* Add immutable subscription event evidence with legal snapshots, request hashes and safe before/after states.
* Add expiry sweep for cancelled or manually non-renewing subscriptions.
* Preserve PAN/CVV isolation and never expose saved-card provider tokens through REST.

= 0.5.0 =
* Confirms merchant recurring authorization through a server-side option.
* Enables verified saved-card selection for renewals and upgrades.
* Adds masked PayTR card refresh through CAPI LIST.
* Adds a 3D Secure renewal flow that can store a new card at PayTR.
* Keeps PAN/CVV out of WordPress REST requests and database storage.


= 0.5.1 =
* Make signed callback terminal states authoritative over slower recurring HTTP responses.
* Prevent callback-confirmed payments from being downgraded to wait_callback/on-hold.
* Add controlled callback-race repair command without advancing subscription periods.

= 0.5.2 =
* Force IPv4 for PayTR recurring, card-list and status-inquiry server calls after the live host returned Cloudflare 522 on the default path.
* Treat the documented immediate recurring `success` response as a completed payment while keeping the signed callback idempotent.
* Reconcile missing callbacks through authenticated PayTR status inquiry without advancing a subscription twice.
* Close confirmed-not-found renewal attempts as failed after the safety window.
* Complete paid Codigle service orders instead of leaving them in WooCommerce processing/on-hold.

= 0.5.4 =
* Retry PayTR Status Inquiry over IPv4 on transient Cloudflare/network errors.
* Submit Status Inquiry as application/x-www-form-urlencoded, matching the provider contract.
* Repair historical renewal races from previously verified signed callback evidence without charging again or extending the subscription period again.
* Keep callback evidence authoritative while preserving idempotent order and event repair.


= 0.5.5 =
* Submit PayTR CAPI LIST as application/x-www-form-urlencoded over IPv4.
* Preserve transient retry behavior without exposing card tokens.
* Pair with Customer Portal 0.5.3 so remote card refresh never blocks the renewal confirmation dialog.

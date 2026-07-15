=== The-RNG — Verifiably Fair Random Number Generator ===
Contributors: therng
Tags: random, rng, raffle, competition, drand, provably fair, verifiable
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verifiably fair random numbers for competitions and raffles, powered by the drand distributed randomness beacon (League of Entropy).

== Description ==

The-RNG generates winning ticket numbers that anyone can independently verify:

* **Distributed randomness** — entropy comes from the drand "quicknet" beacon run by the League of Entropy (Cloudflare, EPFL, University of Chile and others), fetched via Cloudflare's public relay with automatic fallback and independent cross-checking. The plugin never generates randomness itself.
* **Future-round commitment** — every draw is committed to a drand round that does not exist yet (live round + 3), with the commitment stored and shown before the randomness is created anywhere.
* **Forced client seed** — the precise millisecond timestamp of the click, assigned by the server.
* **HMAC-SHA256 + rejection sampling** — deterministic, uniform, no modulo bias. Compatible with widely used public verification scripts.
* **Tamper-evident ledger** — append-only draw records chained with SHA-256; no delete function exists. Draws can only be voided with a public reason.
* **Public verification** — every input revealed after the draw; in-browser recompute via Web Crypto; copy-paste PHP/JS scripts; direct beacon links on multiple independent relays.
* **Integrity endpoint** — SHA-256 manifest of the running plugin files for comparison against published source.

== Shortcodes ==

* `[trng_generator]` — the draw tool (logged-in users)
* `[trng_verify]` — public verification page (accepts ?key=)
* `[trng_past_draws]` — draw log (renders for site admins only)
* `[trng_user_history]` — the logged-in user's draws
* `[trng_provably_fair]` — how-it-works page
* `[trng_drand_info]` — drand chain information with live round
* `[trng_how_to_verify]` — manual verification guide with scripts

== Installation ==

1. Upload the `the-rng` folder to `/wp-content/plugins/` and activate.
2. Create pages for the shortcodes above (at minimum: generator + verify).
3. In The-RNG → Settings, set the Verify page URL so results link to it.
4. Optionally add your public GitHub ledger/source URLs.

== Frequently Asked Questions ==

= Where does the randomness come from? =
From the drand distributed randomness beacon (quicknet chain), generated collectively by the League of Entropy using BLS threshold cryptography and served by multiple independent public relays, primarily Cloudflare's.

= Can the site operator influence a result? =
The draw is locked to a future beacon round before that round's randomness exists, the client seed is a forced timestamp, and every input is published afterwards. Anyone can recompute the result and check the beacon on independent relays.

== Changelog ==

= 2.4.1 =
* Login wall now offers Create Account / Log In buttons automatically when registration is open, falling back to enquiry-only wording when it is closed.


= 2.4.0 =
* All operator details (name, website URL, company number) are now REQUIRED at registration.
* Manual account approval: new sign-ups cannot generate draws until an administrator approves them (Users list "Approve draws" action or profile checkbox). Approved users are notified by email.
* Users list shows approval status; existing accounts grandfathered as approved on upgrade.


= 2.3.0 =
* Registration now collects operator details (company name, website URL, optional company number) on both WordPress and WooCommerce sign-up forms.
* Details are stored per user and used as the operator block in every GitHub ledger record for draws that user runs.
* Read-only operator details panel in My Account (changes by enquiry); admins edit via the user profile screen.


= 2.2.0 =
* GitHub public ledger: every completed draw is automatically committed to a public repository as YYYY/MM/DD/<round-uuid>.json (GitHub Contents API, async with hourly sweep and manual push).
* Operator details (name, website, company number) per user profile with global defaults, included in each ledger record.
* Connection test and push status column in admin. Token via settings or TRNG_GITHUB_TOKEN constant.


= 2.1.1 =
* Draw log ([trng_past_draws]) is no longer publicly accessible — it renders for administrators only. Individual draws remain verifiable by anyone holding the verification key.


= 2.1.0 =
* Live client seed ticker (millisecond timestamp digits) on the generator, matching the assigned-at-click seed for transparency.
* Independent Randomness Provider card (Drand Quicknet via Cloudflare relay) on the generator.
* On-screen clocks now display in a configurable timezone (default Europe/London). Records remain UTC.


= 2.0.0 =
* Complete rebuild on the drand distributed randomness beacon (quicknet via Cloudflare relay).
* Future-round commitment, HMAC-SHA256 combination, rejection sampling.
* Tamper-evident hash-chained ledger, public verification pages, integrity manifest endpoint.

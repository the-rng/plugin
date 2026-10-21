=== The-RNG — Verifiably Fair Random Number Generator ===
Contributors: therng
Tags: random, rng, raffle, competition, drand, provably fair, verifiable
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.8.2
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

= 2.8.2 =
* Fix: a ticket_list from a draw whose sold tickets are scattered (thousands
  of separate runs) was refused as "must be comma-separated ticket numbers or
  ranges". The format check ran one pattern over the whole list, and PHP's
  pattern engine runs out of stack on long lists and reports no match. The
  list is now checked item by item; tested with 100,000 tickets in 100,000
  separate runs.

= 2.8.1 =
* Fix: auto draws with more than 994 tickets sold failed with a bare
  "400 Bad Request" page. The web server's firewall counts each number in a
  JSON ticket list as a separate request argument and rejects 1,000 or more
  before WordPress runs. The API now also accepts the entry list as one
  compact string, ticket_list ("1-250,253,260-900"), which The-RNG Link
  2.4.1 sends. ticket_numbers still works; the draw, its record and the
  ledger are identical either way.
* No limits: the Max tickets (10,000,000) and Max winners (25) settings are
  gone, as are the API's 30 draws a minute per operator, the per-visitor
  limits on resolving and looking up draws, the 50,000 entry cap on lists
  published to the GitHub ledger, the draw walk's 100,000 read guard and the
  generator's 20 tries waiting for the beacon. The only ceiling left is the
  engine's number space: up to 4,294,967,295 tickets per draw.

= 2.8.0 =
* Instant win audit records. Operators running instant wins (BC
  Competitions 0.3.0 and later, through The-RNG Link 2.4.0) send a seal
  when a draw makes its first sale and a reveal when it closes. The seal
  fixes the ticket count, the instant prize list digest and a SHA-256
  commitment to the draw's shuffle key; the reveal gives the key and
  fingerprints of every allocation and win.
* API: POST /the-rng/v1/audit (X-TRNG-Key) and public GET
  /the-rng/v1/audit/{key}. On receipt the text's format and SHA-256 are
  checked, and a reveal must name a seal the same operator recorded and
  carry a key that hashes to that seal's commitment, or it is refused.
* Records are append-only and chained with SHA-256 (separately from the
  draw chain), and published to the GitHub ledger under
  audit/YYYY/MM/DD/{key}.json. The hourly sweep retries any that missed.
* [trng_verify] shows an audit record for its ?key=, with in-browser
  checks of the text's hash and of the key against the seal.
* Draw records, their hashes and the draw chain are unchanged.
* Generator: the limit of 12 draws a minute is removed, and approved
  operators are no longer throttled while their draws resolve. A draw with
  few tickets sold can take 20 or more draws to land on a sold ticket.
* Generator session check: the page checks you are still logged in when it
  loads, every minute, when you come back to the tab and before every draw.
  If you have been logged out it locks the Generate button and shows a "Log
  in again" link (straight back to the page) before you type anything, and
  keeps what you typed. Previously a logged-out draw failed with "Network
  error". The security token is refreshed on every check so it never expires
  on an open page, and the generator page is never cached.

= 2.7.2 =
* Ledger JSON files now encode with JSON_UNESCAPED_UNICODE, so currency
  symbols and other non-ASCII characters in draw titles appear literally
  (£ instead of \u00a3). Byte-format only; identical decoded content, no
  effect on record hashes or the chain.

= 2.7.1 =
* Verify page clarity for entry-list draws: the Derived Numbers column is
  labelled "Position → Ticket" and shows both values on winning steps; each
  winning ticket carries a "position N of M" note plus a plain-English
  explainer; the header shows the real number range instead of "N of N";
  the record fields add Number Range and Entry List SHA-256; Pool Size
  reads as entries. Classic draws are displayed exactly as before.

= 2.7.0 =
* Entry-list draws: operators can commit the exact list of sold ticket
  numbers (API: ticket_numbers + range_max). The engine draws a uniform
  index over the list and the record publishes the LITERAL winning ticket,
  the real number range, the raw drawn indexes, the entry list and its
  SHA-256 — fully reproducible end to end.
* Record hash chain: entry-list records additionally bind the list, range
  and raw indexes. Conditional — every pre-2.7 record hash is byte-identical
  and the existing chain verifies unchanged.
* GitHub ledger records for entry-list draws include ticket_range_max,
  entry_hash, result_indexes and (up to 50,000 entries) ticket_numbers.
* In-browser verification maps the recomputed walk through the published
  entry list and shows "Winner #n → ticket T" in the steps table.
* Backwards compatible: draws without an entry list behave exactly as 2.6.


= 2.6.0 =
* REST API for operator integrations: POST /the-rng/v1/draws (create+commit), POST /draws/{key}/resolve, GET /draws/{key}. Auth via per-operator X-TRNG-Key (stored hashed, generated on the user profile, revocable). API draws are ledger-attributed to the operator. Rate limited.


= 2.5.0 =
* Redesigned verify page: full draw-details dashboard with status chips, winning-ticket panel, derived-numbers table, copyable verifiably-fair data, pre-hash string, drand proof sidebar, draw information and timeline.
* Verification now runs automatically in the visitor's browser the moment a record loads, with per-winner ticks and a clear verdict.


= 2.4.3 =
* Verify page and draw results now display times in the configured display timezone (default Europe/London) with the canonical UTC value alongside. Stored records, the ledger and GitHub files remain UTC.


= 2.4.2 =
* Integrity endpoint now sends no-cache headers so the file-hash manifest can never be served stale by page caches or CDNs.


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

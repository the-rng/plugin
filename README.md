# The-RNG — Verifiably Fair Random Number Generator for WordPress

The-RNG generates winning ticket numbers for competitions and raffles that **anyone can verify independently**. The randomness comes from the [drand](https://drand.love) distributed beacon operated by the League of Entropy — never from this plugin or the server it runs on.

This repository contains the complete source of the plugin running at [the-rng.com](https://the-rng.com), published so the public can verify the deployed code. Every draw it performs is mirrored to the public ledger: [the-rng/ledger](https://github.com/the-rng/ledger).

## How a draw works

1. **Commit** — on Generate, the draw locks to drand round `live + 3`. The round number, verification key, millisecond-timestamp client seed and draw UUID are stored and shown **before that round's randomness exists anywhere in the world**.
2. **Beacon** — seconds later the League of Entropy emits the round. The plugin fetches it from Cloudflare's public relay, cross-checks it against a second independent relay, and verifies `randomness = SHA-256(signature)` locally.
3. **Combine** — `HMAC-SHA256("clientSeed:roundId:staticSalt:ticketsSold:maxTickets", key = serverSeed)`, then unbiased rejection sampling (unsigned 32-bit little-endian reads, `limit = 0xFFFFFFFF − (0xFFFFFFFF mod maxTickets)`, `ticket = (value mod maxTickets) + 1`).
4. **Reveal** — every input is published. Results are verifiable in-browser (Web Crypto), by hand with open scripts, and against the beacon on relays we don't control.

## Features

* Drand **quicknet** beacon via the Cloudflare relay with automatic fallback (`api.drand.sh`, `api2`, `api3`) and two-relay cross-checking
* Future-round commitment (configurable offset, default +3 ≈ 9 s)
* Multi-winner draws — winner #1 is byte-identical to the standard single-winner verification scripts; extra winners continue the same documented hash walk
* Append-only, SHA-256 **hash-chained draw ledger** — no delete function exists; draws can only be voided with a public reason
* Automatic mirroring of every completed draw to a public **GitHub ledger** (`YYYY/MM/DD/round-uuid.json`)
* Public **integrity endpoint** (`/?trng_integrity=1`) publishing SHA-256 hashes of every running plugin file for comparison against this repository
* Operator details (name, website, company number) collected at registration, shown in every ledger record; **manual account approval** before draws can be generated
* Public verification page with in-browser recomputation, manual-verification guide with PHP/JS scripts, live drand chain info

## Shortcodes

| Shortcode | Purpose |
| --- | --- |
| `[trng_generator]` | The draw tool (approved, logged-in users) |
| `[trng_verify]` | Public verification of any draw key (accepts `?key=`) |
| `[trng_past_draws]` | Draw log (site administrators only) |
| `[trng_user_history]` | The logged-in user's own draws |
| `[trng_provably_fair]` | How-it-works page with public parameters |
| `[trng_drand_info]` | Drand chain information with live round |
| `[trng_how_to_verify]` | Manual verification guide with scripts |

## Installation

1. Download this repository, place the folder in `wp-content/plugins/the-rng/` (or upload a zip of it), and activate.
2. Create pages for the shortcodes above (at minimum: generator + verify) and set the Verify page URL in **The-RNG → Settings**.
3. Optional: configure the GitHub ledger (repository, branch, fine-grained token with Contents read/write on that one repository — or define `TRNG_GITHUB_TOKEN` in `wp-config.php`).

Requires WordPress 5.8+ and PHP 7.4+. WooCommerce is optional (adds a My Draws account tab and registration fields).

## Verify the deployed code

The integrity endpoint returns a SHA-256 hash for every file the site is actually running:

```
https://the-rng.com/?trng_integrity=1
```

Compare those hashes against the files in this repository — they must match. Combined with the public ledger and the drand beacon, this closes the loop: **you do not have to trust The-RNG; you can verify it.**

## License

GPL-2.0-or-later. Randomness by the [League of Entropy](https://www.cloudflare.com/leagueofentropy/); served via the Cloudflare drand relay.

# SPC Lead & Call Tracker (mu-plugin)

Live file: `wp-content/mu-plugins/spc-lead-and-call-tracker.php` (table `qnVLkjm_smart_lead_tracker`, admin page `?page=spc-lead-tracker`).

- `original/spc-lead-and-call-tracker.php`: v1.2.0 as found on 2026-11-12 (md5 be924d0ae0605534a5b0fbf3e8c76fcd). Server copy: `wp-content/spc-backups/spc-lead-and-call-tracker.php.20261112.bak`
- `spc-lead-and-call-tracker.php`: v1.3.0 (live md5 65d19ad81b01bc9790996280b0be4969). Previous live: `spc-backups/spc-lead-and-call-tracker.php.v130.bak`
- `deploy.py`: guarded deploy (md5 check, backup, frontend + admin render checks, auto-restore). `deploy.py restore` puts back the BK file. Update EXPECT_OLD and BK before each deploy.
- `tests/helpers_test.php`: unit tests for channel guessing, UK-time conversion, office hours, attribution time (`php helpers_test.php`).

## v1.3.0 fixes (2026-11-12)
1. Duplicate rows: every event has an `event_id`; one click = one row even when VPN / iCloud Private Relay changes IP per request (11 Nov: 2 "calls" from 2 Albanian IPs in the same second). Image ping now only fires if beacon and fetch fail.
2. Forms: browser event + Forminator server hook share `spc_event_id` (hidden field), so one submission = one row.
3. Wrong default channels: forms without cookie were labelled "Google Organic (SEO)", Woo orders "ChatGPT (AI)". Now guessed from the URL (Ads click ids) or "Direct".
4. Attribution time (`attr_time`) and Google Ads campaign (`_spc_attr_gad`) kept in cookies; dashboard shows "Arrived: <UK time>, N min/h/days before".
5. IP flags (`ip_flags`): Outside UK / VPN-proxy / Data centre (ip-api proxy+hosting fields). Unknown location no longer shown as "United Kingdom". Old rows backfilled with outside_uk (7 rows).
6. "After hours" badge (Mon–Sat 08:00–18:00 UK; Sunday closed).
7. Date filters use UK calendar days (BST safe); created_at stored in UTC explicitly; month labels dynamic (were hard-coded October/September).
8. KPI cards follow filters; "Filtered Results" shows the real total (was page count); tablet counted; flagged count.
9. Public endpoint hardening: whitelisted event types, bot user-agents ignored, 30 events / 10 min per IP, field length limits; forwarded IP headers only trusted from private proxy addresses (visitors could fake IP/location before).
10. CSV export guarded against spreadsheet formula injection. Table check uses a DB-version option instead of SHOW TABLES on every request.
11. "Phone Call" badge renamed "Call Click" (it records a tap on a call button, not a connected call).

## v1.4.0 (2026-11-12): search terms
- **Google SEO leads:** Google does not send organic keywords to websites. The new "Search / Keyword" column shows the *likely* searches: Search Console queries that brought clicks/impressions to the lead's landing page within 3 days before the lead (fallback: the page's top searches over 28 days). Data lives in `{prefix}spc_gsc_queries`, filled by `push_gsc.py [days]` (UK only). `daily/daily_gsc.py` now runs it automatically (35 days). GSC data lags 2–3 days, so new leads show "Waiting for Search Console data" at first.
- **Google Ads leads:** stores `utm_term` (or `keyword`) as `search_term`. Needs the Google Ads account-level Final URL suffix:
  `utm_source=google&utm_medium=cpc&utm_campaign={campaignid}&utm_term={keyword}`
  ({keyword} = the matched keyword; Google only shows the user's exact search text in the Ads Search terms report.)

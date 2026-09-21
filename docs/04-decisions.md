# Wren Wold AI Agent — Design Decisions

A running log of decisions made during planning, in the order they were decided. Newer decisions are added to the bottom as the project progresses.

| Date | Decision | Reasoning |
|---|---|---|
| 2026-09 | Agent named **Ornina** (ancient Syrian goddess) | Distinct brand identity, not a generic assistant name |
| 2026-09 | Reuse existing Hostinger VPS (shared with Amy Agent), separate Dokploy container | Sufficient spare resources for current dev phase; upgrade/split later if the business scales |
| 2026-09 | Database: PostgreSQL, fully owned by Python backend, separate from WordPress MySQL | Safer — a bug in Python can't directly damage live store data; matches Amy Agent precedent |
| 2026-09 | Long-term knowledge base via `pgvector` inside the same PostgreSQL instance, not a separate vector DB | Simpler to maintain than running two databases |
| 2026-09 | Communication via REST API with shared API key authentication | Standard, secure, simple to implement for this scale |
| 2026-09 | Strict `/internal/*` vs `/public/*` endpoint separation (not a shared endpoint with a permission flag) | Enforces the Knowledge Separation boundary at the architecture level, not just in prompting |
| 2026-09 | Backend framework: FastAPI | Matches Amy Agent precedent; owner has prior experience with it |
| 2026-09 | Storefront chat widget: open-source visual shell only, 100% custom logic | Faster to build the UI shell without pulling in a full chat plugin/backend |
| 2026-09 | Telegram bot runs inside the Python backend service, not as a separate service | Simpler deployment, shares the same approval-queue logic as the dashboard |
| 2026-09 | Matterhorn import is on-demand and count-limited (owner specifies 5/10/20 per request) — never automatic bulk import | Owner wants full curation control, especially pre-launch; avoids importing thousands of unvetted products |
| 2026-09 | Printful products are imported manually by the owner (outside Ornina); Ornina's only role is SEO review/improvement on request | Printful has no feed to import from — the product only exists after the owner designs and publishes it via the Printful platform |
| 2026-09 | Price sync on Matterhorn cost changes is autonomous (no approval needed) | Defensive action — preserves an already-agreed margin, does not represent a new business decision |
| 2026-09 | Discounts/offers always require owner approval, with profit impact shown before the request | Offensive/strategic action — reduces margin deliberately, so it needs a business judgment call |
| 2026-09 | Out-of-stock products are auto-drafted immediately, then the owner is asked keep-vs-delete | Auto-draft is protective (hides from storefront instantly); the keep/delete choice has a real trade-off (storage vs. future restock) so it needs a decision |
| 2026-09 | Build order starts with minimal infra + basic internal chat (Phase 0–1) before any business features | Gives a working, testable communication channel with Ornina from day one, so later features can be verified conversationally as they're built |
| 2026-09 | AI provider API keys are entered and managed from a WordPress dashboard settings screen, not the Python `.env` — supports Gemini, OpenAI, Claude, DeepSeek, Mistral, and Grok | Lets the owner add/switch providers anytime without redeploying the backend; centralizes key management in one place the owner already controls |
| 2026-09-21 | WooCommerce category taxonomy expanded from 6 to 9 garment-type categories, each mapped to specific Matterhorn feed category paths (not free-text keyword matching) | Free-text category matching against the Matterhorn feed was returning wrong garment types (e.g. blouses and cami tops appearing under "T-Shirts" import results); full feed analysis (13,891 products, 34 unique category paths) showed Matterhorn has a genuine multi-level taxonomy that the import logic was ignoring |

## Category → Matterhorn Feed Mapping (2026-09-21)

Full mapping derived from analysis of the live Matterhorn feed (feed_woocommerce.xml, 13,891 products, 34 unique category paths under the "VOOR HAAR / Women's fashion" root). Confirmed: no "Bags" or "Hoodies" category exists anywhere in the Matterhorn feed — Hoodies are Printful-sourced only (see the Printful decision above).

| WooCommerce Category | Matterhorn Feed Subcategories | Notes |
|---|---|---|
| Dresses (existing) | Daily (dagelijks jurken), Evening (avondjurken), Formal/Cocktail (Formele jurken, cocktail) | |
| Pants (existing) | Overalls, Fitted Shorts (Shorts, bijgesneden), Training Pants (trainingsbroek), Long Pants (lange broek), Leggings, Elegant Pants (Broeken elegante) | |
| Knitwear (existing) | Regular Sweaters (Truien), Turtle-Necks (Truien, Turtle-Necks) | |
| Shirts (existing) | Women's Shirts (shirts Vrouwen) only | |
| T-Shirts (existing) | General t-shirts, Tops/T-shirts subcategory, Bodysuits (lichaam) | "Tops" is intentionally NOT a separate category — see the 2026-08-27 resolution in CONTENT_ARCHITECTURE.md (theme repo). All of this folds into the existing T-Shirts category. |
| Skirts (new) | rokken (single level, no subcategories) | Not previously formalized as a WooCommerce category despite existing in older import code |
| Blouses (new) | Blouses, tunieken | Split out from "Shirts, Blouses" — large volume (1,719 products), distinct enough from Shirts to warrant its own category |
| Coats (new) | Jassen voor vrouwen + Coats, Jackets (merged — confirmed zero product_id overlap between the two, i.e. genuinely different products, not duplicates in two languages) | |
| Hoodies (existing) | N/A — not in Matterhorn feed, Printful-sourced only | |

Deferred, not part of this decision: Maternity (Zwangerschapsmode) and Plus-size (Grote maten mode) as potential future product lines; store multilingual support.

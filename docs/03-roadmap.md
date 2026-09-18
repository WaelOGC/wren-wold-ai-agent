# Wren Wold AI Agent — Roadmap

Build order prioritizes getting a working communication channel with Ornina early, so every later feature can be tested conversationally as it's built — rather than building everything silently and only seeing results at the end.

## Phase 0 — Minimal Infrastructure
- Set up Python backend project (FastAPI) as its own container in Dokploy, on the existing VPS.
- Set up PostgreSQL database + pgvector extension.
- Generate and store shared API keys (WordPress ↔ Python).
- Basic `/internal/*` and `/public/*` endpoint structure (empty/placeholder logic).
- One working test round-trip: WordPress dashboard sends a message → Python responds → confirms the two systems can talk to each other.

## Phase 1 — Internal Chat (Basic)
- Dashboard chat UI inside the WordPress plugin.
- Connects to Gemini via the Python backend for real responses (not yet connected to store actions).
- Per-conversation scoped memory (each chat window remembers its own thread).
- This becomes the primary tool used to test and interact with every feature built in later phases.

## Phase 2 — Matterhorn Import & Pricing (On-Demand, Controlled)
- Owner requests imports conversationally via chat or Telegram (e.g. "import dresses") — Ornina asks how many (5/10/20) and imports exactly that number. No automatic bulk import.
- Optional trend research step before import: owner can ask Ornina to research current fashion trends for the target audience (women, Gen Z–40s/50s) to inform what to import.
- Image analysis (Gemini) generates product name, description, and structured SEO data (JSON-LD/Schema.org).
- Pricing & margin engine: real cost (Matterhorn price + shipping per country + payment processor fees) + owner's margin → final price.
- Continuous price sync on Matterhorn cost/shipping changes (autonomous, no approval — defensive margin protection).
- Out-of-stock handling (auto-draft, then ask owner: keep as draft or delete entirely).
- All imported products saved as Draft. Approval requested via Telegram and/or dashboard before publishing.

## Phase 3 — Chat Capabilities Expansion
- Sub-agent structure implemented (SEO & Content, Market/Trend Research, Customer Service, Pricing & Margin) — Ornina delegates and consolidates.
- Action Log / Audit Trail: every action (automatic or approved) permanently logged and viewable in the dashboard.
- Long-term knowledge base (pgvector) starts accumulating: past decisions, pricing outcomes, campaign results, owner preferences.

## Phase 4 — Printful SEO Assistance
- Owner manually creates and imports Printful products via the Printful platform into WooCommerce (no import role for Ornina here).
- Owner asks Ornina (by product name/reference) to review and improve SEO for a specific Printful product.
- Ornina may improve weak SEO without approval (content refinement, not publishing/pricing).

## Phase 5 — Customer-Facing Chat Widget
- Storefront chat bubble (open-source visual shell + fully custom logic).
- Connects only to `/public/*` endpoints — restricted to public store information (Knowledge Separation boundary from Vision doc).
- Answers customer questions, gives recommendations, suggests products.

## Phase 6 — Visitor Analytics & Cart Abandonment
- Visitor session tracking (entry to exit, browsing behavior).
- Cart-abandonment pattern detection.
- Autonomous discount proposals for repeatedly-abandoned products — always requires owner approval (with calculated profit impact) before being applied, per the Sensitive Actions rule in the Architecture doc.

## Notes
- Each phase should be independently testable via the internal chat (Phase 1) before moving to the next.
- Phase order may be adjusted as development progresses — this roadmap reflects current thinking, not a fixed contract.

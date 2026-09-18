# Wren Wold AI Agent — Architecture

## High-Level Overview
Ornina is built as two independent, decoupled systems that communicate over a REST API:

1. **WordPress Plugin** (`wren-wold-ai-agent`) — the frontend/interface layer. Lives inside WordPress, provides the dashboard UI (internal chat, approvals, settings), and the customer-facing chat widget on the storefront.
2. **Python Backend Service** — the "brain." An always-on FastAPI service handling AI reasoning (Gemini), the Telegram bot, scheduled jobs (daily Matterhorn feed checks, price sync), visitor analytics processing, and all business logic.

This mirrors the existing "Amy Agent" pattern already in production for the OGC Newfinity project.

## Hosting & Infrastructure
- **Server**: Same Hostinger VPS currently running Amy Agent (Ubuntu 24.04, Dokploy, Germany–Frankfurt, KVM 2 — 2 CPU / 8GB RAM / 100GB disk).
- **Deployment**: Ornina's Python backend runs as its own separate project/container in Dokploy, fully isolated from the Amy Agent container, sharing only the underlying VPS resources.
- **Future scaling**: If Wren Wold grows into a full brand/expansion phase, the VPS can be upgraded or Ornina migrated to a dedicated VPS at that point. Not a current-phase concern.

## Database
- **Engine**: PostgreSQL, owned and managed entirely by the Python backend. Fully separate from the WordPress MySQL database — no direct cross-database access in either direction.
- **Long-term knowledge base**: Implemented via the `pgvector` extension inside the same PostgreSQL instance (semantic/vector search for past decisions, pricing outcomes, campaign results, owner preferences) — no separate vector database needed.
- **What lives here**: internal chat history, sub-agent task logs, visitor/session analytics, cart-abandonment tracking, pending-approval queue, action log/audit trail, knowledge base embeddings.
- **What does NOT live here**: WordPress core data (products, orders, customers) — that stays in WordPress/MySQL, accessed via API only.

## Communication Between WordPress and Python
- **Protocol**: REST API (WordPress REST API on the WordPress side; FastAPI endpoints on the Python side).
- **Authentication**: Shared secret API key(s), generated once and stored securely on both sides (`.env` on the Python service; a protected settings/Customizer field on WordPress). Every request between the two systems carries this key in the request header.
- **Strict endpoint separation** (enforces the Knowledge Separation boundary from the Vision doc):
  - `/internal/*` — requires the admin-level API key. Full access: store data, costs, margins, analytics, approvals. Used only by the WordPress dashboard (owner-facing).
  - `/public/*` — requires a separate, lower-privilege API key. Restricted to public store information only. Used only by the storefront customer-facing chat widget.
  - These are architecturally distinct — not the same endpoints with a permission flag — so a bug in one cannot expose the other's data.

## AI Provider
- **Provider keys management**: All AI provider API keys are entered and stored via a dedicated settings screen inside the WordPress admin dashboard (plugin settings page) — never hardcoded in the Python service's `.env`. WordPress is the source of truth for these keys.
- **Key delivery to Python**: The Python backend retrieves the active provider key(s) from WordPress via an authenticated `/internal/*` API call (using the shared API key), rather than reading them from local environment variables. This lets the owner add, rotate, or switch providers at any time without redeploying the backend.
- **Supported providers**: Google Gemini, OpenAI (GPT), Anthropic (Claude), DeepSeek, Mistral, and xAI (Grok). Each provider's key is stored and labeled separately; the owner selects which provider is active for a given task/sub-agent.
- **Web search / market research**: Uses whichever active provider's built-in web-search/grounding capability is available (e.g. Gemini's Grounding with Google Search, xAI's X/Twitter trend access for Grok).
- **Image analysis**: Uses the active provider's multimodal capability (e.g. Gemini or GPT-4o-class models) for analyzing Matterhorn product photos.

## Sub-Agent Structure
Ornina (orchestrator) delegates to specialized sub-agents, each scoped to a domain:
- SEO & Content
- Market/Trend Research
- Customer Service (storefront chat)
- Pricing & Margin

Each sub-agent operates within its own permission scope. Ornina consolidates their outputs and is the single point of communication with the owner (dashboard chat, Telegram).

## Notification & Approval Channel
- **Telegram Bot**: Runs as part of the Python backend service (not a separate service). Sends notifications and receives approve/reject actions from the owner, writing results back into the same approval-queue system used by the dashboard.
- **WordPress Dashboard**: Same approval queue, viewable/actionable when the owner is online at the computer.

## Customer-Facing Chat Widget (Storefront)
- **Visual layer**: A free, open-source JS library/template used only for the widget's visual shell (bubble, message window, input box) — not a WordPress plugin, no backend logic included.
- **Logic layer**: 100% custom-built — message handling, connection to the `/public/*` API, response rendering — written specifically for Ornina.

## Sensitive Actions Requiring Owner Approval
- Publishing a product (Draft → Published)
- Creating or modifying a discount/offer (must include calculated profit impact before requesting approval)

## Actions Ornina Performs Autonomously (No Approval Needed)
- Responding to routine customer emails/inquiries
- For urgent/important customer issues: replies immediately to the customer, then sends the owner a notification (Telegram/dashboard) flagging it for personal follow-up
- **Price sync on cost change**: Continuously monitors the Matterhorn feed for cost or shipping changes and immediately adjusts the customer-facing price to preserve the owner's defined margin. This is defensive (protects existing margin), not promotional — no approval required, since it does not reduce margin, only preserves it.
- **Out-of-stock handling**: Immediately sets the product to Draft (removes from storefront) as a protective default. Then asks the owner whether to keep it as a draft (in case it restocks) or delete it entirely (including media/images) to save space.

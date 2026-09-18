# Wren Wold AI Agent — Vision & Goals

## Name
The agent is called **Ornina** (named after an ancient Syrian goddess).

## Purpose
Ornina is a private, in-house AI agent built exclusively for the Wren Wold business — not a product to be sold or distributed. It acts as a digital employee capable of handling marketing, SEO, data analysis, and customer-facing support tasks that would otherwise require hiring a team. It must be able to operate autonomously, including while the store owner is unavailable.

## Core Capabilities

1. **On-Demand, Controlled Import (Matterhorn)** — Ornina never bulk-imports products automatically. Import only happens when the owner requests it via the internal chat or Telegram (e.g. "import dresses"), at which point Ornina asks how many (e.g. 5, 10, 20) and imports exactly that number — never hundreds or thousands at once. Before importing, the owner can also ask Ornina to research current market/fashion trends (target audience: women, Gen Z through 40s-50s) to inform what to import.
2. **Printful (Print-on-Demand) SEO Assistance** — Printful is a separate, manual-trigger-only workflow. The owner designs and imports Printful products manually via the Printful platform into WooCommerce (Ornina has no import role here). Once a product exists, the owner can ask Ornina (by name/reference) to review and improve its SEO. If SEO is weak, Ornina may improve it without requiring approval, since this is content refinement, not publishing or pricing.
3. **Human Approval Gate** — Every AI-processed product is saved as a Draft only. Nothing is ever auto-published. Ornina must notify the owner and wait for explicit approval before publishing.
4. **Dual-Channel Notification & Approval** — Review and approval available via the WordPress dashboard (desktop) and via a Telegram bot with approve/reject actions (mobile/away).
5. **Pricing & Margin Engine** — Calculates real product cost (Matterhorn price + shipping cost per destination country + payment processor fees) and applies the owner's margin (flat or percentage) to determine final pricing.
6. **Cart Abandonment Analysis & Auto-Discounts** — Tracks visitor sessions and behavior. When a product is repeatedly added to cart but not purchased, Ornina can propose or apply a discount, editable from the product edit page.
7. **Visitor Analytics** — General visitor behavior tracking (entry to exit, browsing patterns) for marketing insight.
8. **Internal Agent Chat** — A chat interface inside the WordPress dashboard where the owner can talk to Ornina directly and assign it tasks, similar to a standard AI chat interface. Each chat window/conversation maintains its own scoped memory, but Ornina always starts with full, up-to-date knowledge of the store's state (products, orders, settings) without needing to be re-briefed.
9. **Customer-Facing Chat Widget** — A chat icon on the storefront that helps customers with questions, gives recommendations, and suggests products.
10. **Structured SEO Data** — Every product Ornina generates or updates must include structured data markup (JSON-LD / Schema.org) for price, availability, shipping, and product attributes — not just plain text descriptions. This improves visibility in both traditional search engines and AI-driven discovery/shopping assistants.
11. **Long-Term Knowledge Base** — Ornina maintains a searchable knowledge base (beyond live store data) of past decisions, pricing outcomes, campaign results, and owner preferences, so it improves over time instead of starting fresh in every interaction.

## Internal Team Structure (Sub-Agents)
Ornina operates as a manager over a team of specialized sub-agents, each responsible for a domain — mirroring a small in-house team (SEO & content, market/trend research, customer service, pricing & margin). Each sub-agent has permissions scoped to its own domain. Ornina consolidates their work and reports/communicates with the owner in one unified voice.

## Knowledge Separation (Critical Security Boundary)
Ornina operates under two distinct knowledge/permission contexts:

- **Internal mode** (WordPress dashboard, store owner only): Full access — acts as a fully-briefed employee with visibility into all store data, costs, margins, analytics, and operations. Can answer anything within its scope of responsibility.
- **External mode** (customer-facing chat widget): Restricted to publicly available store information only — existing and future public-facing pages (products, shipping, policies, etc.). Must never expose internal data such as costs, margins, or analytics.

This separation is a hard boundary, not a formatting preference — it must be enforced at the architecture level (separate contexts/permissions), not just prompted around.

## Action Log & Audit Trail
Every action Ornina takes — automatic or approved — is permanently logged and visible in the dashboard: what changed, when, and why (e.g. "14:32 — Updated price on [Product] from €X to €Y — reason: Matterhorn cost update"). This provides full transparency and an audit trail the owner can review at any time.

import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from typing import Optional

from dotenv import load_dotenv
from fastapi import Depends, FastAPI, Header, HTTPException
from google import genai
from pydantic import BaseModel, Field
from sqlalchemy import Column, DateTime, ForeignKey, String, Text, create_engine, text
from sqlalchemy.orm import Session, declarative_base, relationship, sessionmaker

load_dotenv()

app = FastAPI()

DATABASE_URL = os.getenv("DATABASE_URL", "")
Base = declarative_base()
engine = None
SessionLocal: Optional[sessionmaker] = None

IMPORT_KEYWORDS = (
    "import",
    "importing",
    "استورد",
    "استيراد",
    "يستورد",
)

# Longer phrases first so matching prefers the most specific category label.
CATEGORY_ALIASES = (
    ("فساتين", "dresses"),
    ("فستان", "dresses"),
    ("dresses", "dresses"),
    ("dress", "dresses"),
    ("تنانير", "skirts"),
    ("تنورة", "skirts"),
    ("skirts", "skirts"),
    ("skirt", "skirts"),
    ("بلوزات", "tops"),
    ("بلوزة", "tops"),
    ("tops", "tops"),
    ("top", "tops"),
    ("حقائب", "bags"),
    ("حقيبة", "bags"),
    ("bags", "bags"),
    ("bag", "bags"),
)

QUANTITY_RE = re.compile(r"^\s*(\d+)\s*$")


class Conversation(Base):
    __tablename__ = "conversations"

    id = Column(String(64), primary_key=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=lambda: datetime.now(timezone.utc))
    messages = relationship("Message", back_populates="conversation", cascade="all, delete-orphan")


class Message(Base):
    __tablename__ = "messages"

    id = Column(String(64), primary_key=True)
    conversation_id = Column(String(64), ForeignKey("conversations.id"), nullable=False, index=True)
    role = Column(String(32), nullable=False)
    content = Column(Text, nullable=False)
    intent = Column(String(64), nullable=True)
    meta = Column(Text, nullable=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=lambda: datetime.now(timezone.utc))
    conversation = relationship("Conversation", back_populates="messages")


class ChatRequest(BaseModel):
    conversation_id: str = Field(..., min_length=1)
    message: str = Field(..., min_length=1)
    gemini_api_key: str = Field(..., min_length=1)


class ChatResponse(BaseModel):
    reply: str
    conversation_id: str
    intent: Optional[str] = None
    detected_category: Optional[str] = None
    category: Optional[str] = None
    quantity: Optional[int] = None


def verify_api_key(x_api_key: str | None = Header(default=None, alias="X-API-Key")):
    expected = os.getenv("WORDPRESS_API_KEY")
    if not expected or not x_api_key or x_api_key != expected:
        raise HTTPException(status_code=401, detail="Unauthorized")


def get_db() -> Session:
    if SessionLocal is None:
        raise HTTPException(
            status_code=503,
            detail="Database is not configured. Set DATABASE_URL and restart the service.",
        )
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


def init_db() -> None:
    global engine, SessionLocal

    if not DATABASE_URL:
        return

    engine = create_engine(DATABASE_URL, pool_pre_ping=True)
    SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)
    Base.metadata.create_all(bind=engine)
    with engine.begin() as conn:
        conn.execute(text("ALTER TABLE messages ADD COLUMN IF NOT EXISTS intent VARCHAR(64)"))
        conn.execute(text("ALTER TABLE messages ADD COLUMN IF NOT EXISTS meta TEXT"))


@app.on_event("startup")
def on_startup() -> None:
    init_db()


def _new_id() -> str:
    return os.urandom(16).hex()


def extract_category(message: str) -> Optional[str]:
    lowered = message.lower()
    for alias, category in CATEGORY_ALIASES:
        if alias.isascii():
            if re.search(rf"\b{re.escape(alias)}\b", lowered):
                return category
        elif alias in message:
            return category
    return None


def is_import_request(message: str) -> bool:
    lowered = message.lower()
    if any(keyword in lowered or keyword in message for keyword in IMPORT_KEYWORDS):
        return True
    return extract_category(message) is not None


def parse_quantity(message: str) -> Optional[int]:
    match = QUANTITY_RE.match(message)
    if not match:
        return None
    return int(match.group(1))


def last_assistant_message(prior_messages: list[Message]) -> Optional[Message]:
    for row in reversed(prior_messages):
        if row.role == "assistant":
            return row
    return None


def load_message_meta(row: Optional[Message]) -> dict:
    if row is None or not row.meta:
        return {}
    try:
        data = json.loads(row.meta)
        return data if isinstance(data, dict) else {}
    except json.JSONDecodeError:
        return {}


def build_import_request_reply(category: Optional[str]) -> str:
    if category:
        return f"How many {category} would you like me to import?"
    return "How many items would you like me to import?"


def fetch_matterhorn_feed_items(category: Optional[str], quantity: int) -> dict:
    """Call the WordPress Ornina REST endpoint to preview Matterhorn feed items."""
    site_url = (os.getenv("WORDPRESS_SITE_URL") or "").rstrip("/")
    api_key = os.getenv("WORDPRESS_API_KEY") or ""

    if not site_url:
        raise RuntimeError("WORDPRESS_SITE_URL is not configured")
    if not api_key:
        raise RuntimeError("WORDPRESS_API_KEY is not configured")

    category_param = category or "dresses"
    query = urllib.parse.urlencode(
        {
            "category": category_param,
            "quantity": int(quantity),
        }
    )
    url = f"{site_url}/wp-json/ornina/v1/matterhorn/feed?{query}"

    request = urllib.request.Request(
        url,
        headers={
            "X-API-Key": api_key,
            "Accept": "application/json",
        },
        method="GET",
    )

    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            body = response.read().decode("utf-8")
            status = getattr(response, "status", 200)
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"WordPress feed preview failed ({exc.code}): {detail}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"WordPress feed preview unreachable: {exc.reason}") from exc

    if status < 200 or status >= 300:
        raise RuntimeError(f"WordPress feed preview failed ({status}): {body}")

    try:
        data = json.loads(body)
    except json.JSONDecodeError as exc:
        raise RuntimeError("WordPress feed preview returned invalid JSON") from exc

    if not isinstance(data, dict):
        raise RuntimeError("WordPress feed preview returned unexpected payload")

    return data


DEFAULT_PRICING_RULES = {
    "default_margin_percent": 40.0,
    "default_shipping_cost": 9.90,
    "payment_fee_percent": 2.9,
    "payment_fee_fixed": 0.30,
}


def fetch_pricing_rules() -> dict:
    """Fetch pricing rules from WordPress; fall back to defaults on failure."""
    site_url = (os.getenv("WORDPRESS_SITE_URL") or "").rstrip("/")
    api_key = os.getenv("WORDPRESS_API_KEY") or ""
    fallback = dict(DEFAULT_PRICING_RULES)

    if not site_url or not api_key:
        return fallback

    url = f"{site_url}/wp-json/ornina/v1/pricing-rules"
    request = urllib.request.Request(
        url,
        headers={
            "X-API-Key": api_key,
            "Accept": "application/json",
        },
        method="GET",
    )

    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            body = response.read().decode("utf-8")
            status = getattr(response, "status", 200)
            if status < 200 or status >= 300:
                return fallback
            data = json.loads(body)
    except (urllib.error.URLError, urllib.error.HTTPError, json.JSONDecodeError, TimeoutError):
        return fallback

    if not isinstance(data, dict):
        return fallback

    return {
        "default_margin_percent": float(
            data.get("default_margin_percent", fallback["default_margin_percent"])
        ),
        "default_shipping_cost": float(
            data.get("default_shipping_cost", fallback["default_shipping_cost"])
        ),
        "payment_fee_percent": float(
            data.get("payment_fee_percent", fallback["payment_fee_percent"])
        ),
        "payment_fee_fixed": float(
            data.get("payment_fee_fixed", fallback["payment_fee_fixed"])
        ),
    }


def _money(value: float) -> float:
    return round(float(value), 2)


def calculate_item_pricing(item: dict, rules: dict) -> dict:
    """Apply Ornina pricing rules to a single feed item."""
    cost = _money(item.get("price") or 0)
    shipping = _money(rules.get("default_shipping_cost", 9.90))
    fee_percent = float(rules.get("payment_fee_percent", 2.9))
    fee_fixed = _money(rules.get("payment_fee_fixed", 0.30))
    margin_percent = float(rules.get("default_margin_percent", 40.0))

    total_cost = _money(cost + shipping)
    payment_fee = _money((cost * fee_percent / 100.0) + fee_fixed)

    denom = 1.0 - (margin_percent / 100.0)
    if denom <= 0:
        sale_price = _money(total_cost + payment_fee)
    else:
        sale_price = _money((total_cost + payment_fee) / denom)

    profit = _money(sale_price - total_cost - payment_fee)

    priced = dict(item)
    priced.update(
        {
            "cost": cost,
            "shipping": shipping,
            "total_cost": total_cost,
            "payment_fee": payment_fee,
            "sale_price": sale_price,
            "profit": profit,
            "margin_percent": margin_percent,
            "payment_fee_percent": fee_percent,
            "payment_fee_fixed": fee_fixed,
        }
    )
    return priced


def summarize_priced_preview(
    category: Optional[str],
    quantity: int,
    priced_items: list,
) -> str:
    found = len(priced_items)
    label = category if category else "items"

    if found == 0:
        return f"I couldn't find any {label} in the Matterhorn feed right now."

    lines = []
    for item in priced_items[:quantity]:
        name = str(item.get("name") or item.get("model") or "Unknown")
        model = str(item.get("model") or "").strip()
        label_name = f"{name} model {model}" if model and model.lower() not in name.lower() else name
        lines.append(
            f"{label_name} — cost €{item['cost']:.2f}, "
            f"sale price €{item['sale_price']:.2f}, profit €{item['profit']:.2f}"
        )

    listing = "\n".join(lines)
    if found < quantity:
        header = f"Found {found} {label} (fewer than the {quantity} requested):"
    else:
        header = f"Found {found} {label}:"

    return f"{header}\n{listing}"


def call_gemini(api_key: str, history_rows: list[Message], user_message: str) -> str:
    client = genai.Client(api_key=api_key)

    history = []
    for row in history_rows:
        gemini_role = "user" if row.role == "user" else "model"
        history.append({"role": gemini_role, "parts": [{"text": row.content}]})

    chat = client.chats.create(model="gemini-3.6-flash", history=history)
    response = chat.send_message(user_message)
    text_out = getattr(response, "text", None)
    if not text_out:
        raise RuntimeError("Gemini returned an empty response")
    return text_out


def persist_turn(
    db: Session,
    conversation_id: str,
    reply_text: str,
    *,
    intent: Optional[str] = None,
    meta: Optional[dict] = None,
) -> None:
    assistant_message = Message(
        id=_new_id(),
        conversation_id=conversation_id,
        role="assistant",
        content=reply_text,
        intent=intent,
        meta=json.dumps(meta) if meta is not None else None,
    )
    db.add(assistant_message)
    try:
        db.commit()
    except Exception as exc:
        db.rollback()
        raise HTTPException(
            status_code=500,
            detail=f"Failed to persist chat messages: {exc}",
        ) from exc


@app.get("/")
def root():
    return {"status": "Ornina is alive"}


@app.get("/internal/health")
def internal_health():
    return {"status": "ok", "scope": "internal"}


@app.get("/public/health")
def public_health():
    return {"status": "ok", "scope": "public"}


@app.get("/internal/ping", dependencies=[Depends(verify_api_key)])
def internal_ping():
    return {
        "status": "ok",
        "message": "Ornina backend is alive",
        "service": "ornina-service",
    }


@app.post(
    "/internal/chat",
    response_model=ChatResponse,
    dependencies=[Depends(verify_api_key)],
)
def internal_chat(payload: ChatRequest, db: Session = Depends(get_db)):
    conversation = db.get(Conversation, payload.conversation_id)
    if conversation is None:
        conversation = Conversation(id=payload.conversation_id)
        db.add(conversation)
        db.flush()

    prior_messages = (
        db.query(Message)
        .filter(Message.conversation_id == payload.conversation_id)
        .order_by(Message.created_at.asc())
        .all()
    )

    user_message = Message(
        id=_new_id(),
        conversation_id=payload.conversation_id,
        role="user",
        content=payload.message,
    )
    db.add(user_message)
    db.flush()

    prev_assistant = last_assistant_message(prior_messages)
    quantity = parse_quantity(payload.message)

    if (
        prev_assistant is not None
        and prev_assistant.intent == "import_request"
        and quantity is not None
    ):
        prev_meta = load_message_meta(prev_assistant)
        category = prev_meta.get("detected_category")
        priced_items: list = []
        pricing_rules = fetch_pricing_rules()
        try:
            preview = fetch_matterhorn_feed_items(category, quantity)
            raw_items = preview.get("items") if isinstance(preview.get("items"), list) else []
            priced_items = [
                calculate_item_pricing(item, pricing_rules)
                for item in raw_items
                if isinstance(item, dict)
            ]
            reply_text = summarize_priced_preview(category, quantity, priced_items)
        except Exception as exc:
            reply_text = (
                f"I confirmed {quantity} {category or 'items'}, but couldn't read the "
                f"Matterhorn feed from WordPress yet: {exc}"
            )
            preview = {"items": [], "error": str(exc)}

        persist_turn(
            db,
            payload.conversation_id,
            reply_text,
            intent="import_confirmed",
            meta={
                "category": category,
                "quantity": quantity,
                "pricing_rules": pricing_rules,
                "items": priced_items,
                "preview": preview,
            },
        )
        return ChatResponse(
            reply=reply_text,
            conversation_id=payload.conversation_id,
            intent="import_confirmed",
            category=category,
            quantity=quantity,
        )

    if is_import_request(payload.message):
        detected_category = extract_category(payload.message)
        reply_text = build_import_request_reply(detected_category)
        persist_turn(
            db,
            payload.conversation_id,
            reply_text,
            intent="import_request",
            meta={"detected_category": detected_category},
        )
        return ChatResponse(
            reply=reply_text,
            conversation_id=payload.conversation_id,
            intent="import_request",
            detected_category=detected_category,
        )

    try:
        reply_text = call_gemini(payload.gemini_api_key, prior_messages, payload.message)
    except Exception as exc:
        db.rollback()
        raise HTTPException(
            status_code=502,
            detail=f"Gemini API call failed: {exc}",
        ) from exc

    persist_turn(db, payload.conversation_id, reply_text)
    return ChatResponse(reply=reply_text, conversation_id=payload.conversation_id)

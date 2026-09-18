import json
import os
import re
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


def build_import_confirmed_reply(category: Optional[str], quantity: int) -> str:
    label = category if category else "items"
    return (
        f"Got it — I'll import {quantity} {label}. "
        "Actual import will be built in the next development phase."
    )


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
        reply_text = build_import_confirmed_reply(category, quantity)
        persist_turn(
            db,
            payload.conversation_id,
            reply_text,
            intent="import_confirmed",
            meta={"category": category, "quantity": quantity},
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

import os
from datetime import datetime, timezone
from typing import Optional

from dotenv import load_dotenv
from fastapi import Depends, FastAPI, Header, HTTPException
from google import genai
from pydantic import BaseModel, Field
from sqlalchemy import Column, DateTime, ForeignKey, String, Text, create_engine
from sqlalchemy.orm import Session, declarative_base, relationship, sessionmaker

load_dotenv()

app = FastAPI()

DATABASE_URL = os.getenv("DATABASE_URL", "")
Base = declarative_base()
engine = None
SessionLocal: Optional[sessionmaker] = None


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
    created_at = Column(DateTime(timezone=True), nullable=False, default=lambda: datetime.now(timezone.utc))
    conversation = relationship("Conversation", back_populates="messages")


class ChatRequest(BaseModel):
    conversation_id: str = Field(..., min_length=1)
    message: str = Field(..., min_length=1)
    gemini_api_key: str = Field(..., min_length=1)


class ChatResponse(BaseModel):
    reply: str
    conversation_id: str


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


@app.on_event("startup")
def on_startup() -> None:
    init_db()


def _new_id() -> str:
    return os.urandom(16).hex()


def call_gemini(api_key: str, history_rows: list[Message], user_message: str) -> str:
    client = genai.Client(api_key=api_key)

    history = []
    for row in history_rows:
        gemini_role = "user" if row.role == "user" else "model"
        history.append({"role": gemini_role, "parts": [{"text": row.content}]})

    chat = client.chats.create(model="gemini-2.0-flash", history=history)
    response = chat.send_message(user_message)
    text = getattr(response, "text", None)
    if not text:
        raise RuntimeError("Gemini returned an empty response")
    return text


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

    try:
        reply_text = call_gemini(payload.gemini_api_key, prior_messages, payload.message)
    except Exception as exc:
        db.rollback()
        raise HTTPException(
            status_code=502,
            detail=f"Gemini API call failed: {exc}",
        ) from exc

    assistant_message = Message(
        id=_new_id(),
        conversation_id=payload.conversation_id,
        role="assistant",
        content=reply_text,
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

    return ChatResponse(reply=reply_text, conversation_id=payload.conversation_id)

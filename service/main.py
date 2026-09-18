import os

from fastapi import Depends, FastAPI, Header, HTTPException

app = FastAPI()


def verify_api_key(x_api_key: str | None = Header(default=None, alias="X-API-Key")):
    expected = os.getenv("WORDPRESS_API_KEY")
    if not expected or not x_api_key or x_api_key != expected:
        raise HTTPException(status_code=401, detail="Unauthorized")


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

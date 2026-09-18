from fastapi import FastAPI

app = FastAPI()


@app.get("/")
def root():
    return {"status": "Ornina is alive"}


@app.get("/internal/health")
def internal_health():
    return {"status": "ok", "scope": "internal"}


@app.get("/public/health")
def public_health():
    return {"status": "ok", "scope": "public"}

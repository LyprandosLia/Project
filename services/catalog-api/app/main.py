from fastapi import FastAPI

app = FastAPI(title="Multi-Agent Food OS Catalog API")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}

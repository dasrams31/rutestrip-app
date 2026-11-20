from __future__ import annotations

import os
import tempfile
from functools import lru_cache
from typing import Optional

from fastapi import FastAPI, File, HTTPException, UploadFile
from pydantic import BaseModel

import processor
import rag_chatbot
import search_scoring
from processor import mode_ingest, mode_search


app = FastAPI(title="RuteStrip Python Processor")


def use_shared_model(model) -> None:
    """Make processor and RAG use an already loaded SentenceTransformer
    (e.g. the one owned by webchat-api) so only one copy lives in memory."""
    processor._model = model
    rag_chatbot._model = model


@lru_cache(maxsize=512)
def _cached_query_embedding(processed_query: str) -> tuple:
    return tuple(processor.get_model().encode(processed_query).tolist())


class EmbedRequest(BaseModel):
    query: str


class SearchRequest(BaseModel):
    query: str
    routes: list[dict]


class IndexRequest(BaseModel):
    routes: list[dict]


class RagRequest(BaseModel):
    query: str
    routes: list[dict]
    history: list[dict] = []
    api_key: str = ""
    model: str = "gemini-3.6-flash"
    top_k: int = 3


@app.get("/")
def health() -> dict:
    return {"success": True, "service": "rutestrip-python-processor"}


@app.post("/ingest")
async def ingest(gpx_file: UploadFile = File(...)) -> dict:
    suffix = os.path.splitext(gpx_file.filename or "route.gpx")[1] or ".gpx"

    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        tmp.write(await gpx_file.read())
        tmp_path = tmp.name

    try:
        result = mode_ingest(tmp_path)
    finally:
        os.unlink(tmp_path)

    if not result.get("success"):
        raise HTTPException(status_code=422, detail=result.get("error", "GPX ingest failed"))

    return result


@app.post("/search")
def search(payload: SearchRequest) -> dict:
    result = mode_search(payload.query, payload.routes)
    if not result.get("success"):
        raise HTTPException(status_code=422, detail=result.get("error", "Search failed"))

    return result


@app.post("/embed")
def embed(payload: EmbedRequest) -> dict:
    processed = search_scoring.query_text(payload.query)
    try:
        embedding = list(_cached_query_embedding(processed))
    except Exception as e:
        raise HTTPException(status_code=422, detail=f"Embedding failed: {e}")

    return {"success": True, "embedding": embedding, "processed_query": processed}


@app.post("/index")
def index(payload: IndexRequest) -> dict:
    """Build search embeddings for routes: one for the whole document
    (name + narrative + description) and one per aspect sentence."""
    model = processor.get_model()
    items = []
    for route in payload.routes:
        doc = search_scoring.build_document(route)
        vectors = model.encode([doc["text"]] + doc["aspects"]).tolist()
        items.append({
            "id": route.get("id"),
            "embedding_text": doc["text"],
            "embedding": vectors[0],
            "aspect_embeddings": vectors[1:],
        })
    return {"success": True, "routes": items}


@app.post("/rag")
def rag(payload: RagRequest) -> dict:
    result = rag_chatbot.rag_chat(
        query=payload.query,
        routes_data=payload.routes,
        api_key=payload.api_key,
        chat_history=payload.history,
        top_k=payload.top_k,
        model_name=payload.model,
    )
    return result

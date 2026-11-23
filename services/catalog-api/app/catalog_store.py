import json
from datetime import datetime, timezone
from pathlib import Path
from tempfile import NamedTemporaryFile


DATA_FILE = Path(__file__).with_name("catalog.json")
CACHE_FILE = Path(__file__).with_name("catalog-cache.json")
CACHE_TTL_SECONDS = 24 * 60 * 60


def load_catalog() -> dict:
    with DATA_FILE.open("r", encoding="utf-8") as catalog_file:
        data = json.load(catalog_file)
    if not isinstance(data, dict) or "ingredients" not in data:
        raise RuntimeError("Catalog fixture must contain an ingredients object")
    return data


def normalize_ingredient(value: str) -> str:
    return " ".join(value.strip().split()).casefold()


def find_ingredient(name: str) -> dict | None:
    catalog = load_catalog()
    requested = normalize_ingredient(name)
    for ingredient_name, record in catalog["ingredients"].items():
        if normalize_ingredient(ingredient_name) == requested:
            return {
                "name": ingredient_name,
                **record,
            }
    return None


def catalog_freshness(record: dict) -> tuple[str, datetime]:
    source = str(record.get("source", "local fixture"))
    try:
        fetched_at = datetime.fromisoformat(str(record["fetched_at"]))
    except (KeyError, TypeError, ValueError):
        fetched_at = datetime.now(timezone.utc)
    return source, fetched_at


def load_price_cache() -> dict:
    if not CACHE_FILE.exists():
        return {}
    try:
        with CACHE_FILE.open("r", encoding="utf-8") as cache_file:
            data = json.load(cache_file)
        return data if isinstance(data, dict) else {}
    except (json.JSONDecodeError, OSError):
        return {}


def get_cached_price(name: str) -> dict | None:
    key = normalize_ingredient(name)
    record = load_price_cache().get(key)
    if not isinstance(record, dict):
        return None
    try:
        fetched_at = datetime.fromisoformat(str(record["fetched_at"]))
        if fetched_at.tzinfo is None:
            fetched_at = fetched_at.replace(tzinfo=timezone.utc)
    except (KeyError, TypeError, ValueError):
        return None
    age = datetime.now(timezone.utc) - fetched_at
    if age.total_seconds() > CACHE_TTL_SECONDS:
        return None
    return record


def cache_price(name: str, record: dict) -> None:
    cache = load_price_cache()
    cache[normalize_ingredient(name)] = record
    with NamedTemporaryFile(
        "w",
        encoding="utf-8",
        dir=CACHE_FILE.parent,
        delete=False,
    ) as cache_file:
        json.dump(cache, cache_file, ensure_ascii=False, indent=2, sort_keys=True)
        temp_path = Path(cache_file.name)
    temp_path.replace(CACHE_FILE)

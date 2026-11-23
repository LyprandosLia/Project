from fastapi import FastAPI, HTTPException, Query

from app.catalog_store import (
    cache_price,
    catalog_freshness,
    find_ingredient,
    get_cached_price,
    load_catalog,
)
from app.models import (
    NutritionLookupRequest,
    NutritionLookupResponse,
    PriceLookupRequest,
    PriceLookupResponse,
)
from app.nutrition_provider import OpenFoodFactsProvider
from app.provider import GreekPriceProvider, UpstreamError

app = FastAPI(title="Multi-Agent Food OS Catalog API", version="2.0.0")


def _converted_quantity(quantity: float, unit: str) -> float:
    if unit == "kg":
        return quantity * 1000.0
    if unit == "l":
        return quantity * 1000.0
    return quantity


def _find_catalog_record(ingredient: str) -> dict | None:
    cached = get_cached_price(ingredient)
    if cached is not None:
        return cached
    try:
        record = GreekPriceProvider().fetch(ingredient)
        record = {**record, "name": record.get("name", ingredient)}
        cache_price(ingredient, record)
        return record
    except UpstreamError:
        return find_ingredient(ingredient)


def _price_response(record: dict, ingredient: str, quantity: float, unit: str) -> PriceLookupResponse:
    source, fetched_at = catalog_freshness(record)
    price_unit = str(record["price_unit"])
    amount = _converted_quantity(quantity, unit)
    if price_unit in {"kg", "l"}:
        base_quantity = amount
        if price_unit == "kg" and unit == "g":
            base_quantity = amount / 1000.0
        elif price_unit == "l" and unit == "ml":
            base_quantity = amount / 1000.0
        elif price_unit == "kg" and unit == "kg":
            base_quantity = quantity
        elif price_unit == "l" and unit == "l":
            base_quantity = quantity
        elif price_unit == "kg" and unit != "g":
            raise HTTPException(status_code=422, detail="Requested unit is incompatible with kg pricing")
        elif price_unit == "l" and unit not in {"ml", "l"}:
            raise HTTPException(status_code=422, detail="Requested unit is incompatible with l pricing")
    elif price_unit == "piece":
        if unit != "piece":
            raise HTTPException(status_code=422, detail="Requested unit is incompatible with piece pricing")
        base_quantity = quantity
    else:
        raise HTTPException(status_code=422, detail=f"Unsupported catalog price unit: {price_unit}")

    return PriceLookupResponse(
        ingredient=record["name"],
        quantity=quantity,
        unit=unit,
        price=round(float(record["price"]) * base_quantity, 2),
        currency=str(record.get("currency", load_catalog()["currency"])),
        source=source,
        fetched_at=fetched_at,
    )


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/catalog/prices/{ingredient}", response_model=PriceLookupResponse)
@app.get("/prices/{ingredient}", response_model=PriceLookupResponse)
def get_price(
    ingredient: str,
    quantity: float = Query(default=1.0, gt=0),
    unit: str = Query(default="piece", pattern=r"^(g|kg|ml|l|piece)$"),
) -> PriceLookupResponse:
    record = _find_catalog_record(ingredient)
    if record is None:
        raise HTTPException(status_code=404, detail=f"Ingredient '{ingredient}' was not found")
    return _price_response(record, ingredient, quantity, unit)


@app.post("/catalog/prices", response_model=PriceLookupResponse)
@app.post("/prices", response_model=PriceLookupResponse)
def lookup_price(request: PriceLookupRequest) -> PriceLookupResponse:
    record = _find_catalog_record(request.ingredient)
    if record is None:
        raise HTTPException(status_code=404, detail=f"Ingredient '{request.ingredient}' was not found")
    return _price_response(record, request.ingredient, request.quantity, request.unit)


@app.get("/catalog/nutrition/{ingredient}", response_model=NutritionLookupResponse)
@app.get("/nutrition/{ingredient}", response_model=NutritionLookupResponse)
def get_nutrition(
    ingredient: str,
    serving_quantity: float = Query(default=100.0, gt=0),
    serving_unit: str = Query(default="g", pattern=r"^(g|kg|ml|l|piece)$"),
) -> NutritionLookupResponse:
    record = _find_nutrition_record(ingredient)
    if record is None:
        raise HTTPException(status_code=404, detail=f"Ingredient '{ingredient}' was not found")
    return _nutrition_response(record, serving_quantity, serving_unit)


def _find_nutrition_record(ingredient: str) -> dict | None:
    fallback = find_ingredient(ingredient)
    if fallback is not None:
        return fallback
    try:
        return OpenFoodFactsProvider().fetch(ingredient)
    except UpstreamError:
        return None


def _nutrition_response(record: dict, quantity: float, unit: str) -> NutritionLookupResponse:
    if unit == "g":
        grams = quantity
    elif unit == "kg":
        grams = quantity * 1000.0
    elif unit == "ml":
        grams = quantity
    elif unit == "l":
        grams = quantity * 1000.0
    else:
        raise HTTPException(status_code=422, detail="Nutrition lookup requires g, kg, ml, or l")

    source, fetched_at = catalog_freshness(record)
    nutrition = record["nutrition_per_100g"]
    scale = grams / 100.0
    return NutritionLookupResponse(
        ingredient=record["name"],
        serving_quantity=quantity,
        serving_unit=unit,
        calories_kcal=round(float(nutrition["calories_kcal"]) * scale, 2),
        protein_g=round(float(nutrition["protein_g"]) * scale, 2),
        carbohydrates_g=round(float(nutrition["carbohydrates_g"]) * scale, 2),
        fat_g=round(float(nutrition["fat_g"]) * scale, 2),
        fiber_g=round(float(nutrition["fiber_g"]) * scale, 2),
        source=source,
        fetched_at=fetched_at,
    )


@app.post("/catalog/nutrition", response_model=NutritionLookupResponse)
@app.post("/nutrition", response_model=NutritionLookupResponse)
def lookup_nutrition(request: NutritionLookupRequest) -> NutritionLookupResponse:
    record = _find_nutrition_record(request.ingredient)
    if record is None:
        raise HTTPException(status_code=404, detail=f"Ingredient '{request.ingredient}' was not found")
    return _nutrition_response(record, request.serving_quantity, request.serving_unit)

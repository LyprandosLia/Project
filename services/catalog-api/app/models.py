from datetime import datetime

from pydantic import BaseModel, Field


SUPPORTED_UNITS = {"g", "kg", "ml", "l", "piece"}


class PriceLookupRequest(BaseModel):
    ingredient: str = Field(min_length=1, max_length=160)
    quantity: float = Field(default=1.0, gt=0)
    unit: str = Field(default="piece", pattern=r"^(g|kg|ml|l|piece)$")


class NutritionLookupRequest(BaseModel):
    ingredient: str = Field(min_length=1, max_length=160)
    serving_quantity: float = Field(default=100.0, gt=0)
    serving_unit: str = Field(default="g", pattern=r"^(g|kg|ml|l|piece)$")


class FreshnessMetadata(BaseModel):
    source: str
    fetched_at: datetime


class PriceLookupResponse(BaseModel):
    ingredient: str
    quantity: float
    unit: str
    price: float
    currency: str
    source: str
    fetched_at: datetime


class NutritionLookupResponse(BaseModel):
    ingredient: str
    serving_quantity: float
    serving_unit: str
    calories_kcal: float
    protein_g: float
    carbohydrates_g: float
    fat_g: float
    fiber_g: float
    source: str
    fetched_at: datetime

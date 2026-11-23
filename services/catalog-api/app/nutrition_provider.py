import os
from datetime import datetime, timezone

import httpx

from app.provider import UpstreamError


class OpenFoodFactsProvider:
    def __init__(self) -> None:
        self.base_url = os.getenv(
            "OPEN_FOOD_FACTS_BASE_URL",
            "https://world.openfoodfacts.org",
        ).strip().rstrip("/")
        self.headers = {
            "Accept": "application/json",
            "User-Agent": "FoodOS-Catalog/2.0 (keyless nutrition lookup)",
        }

    def fetch(self, ingredient: str) -> dict:
        url = f"{self.base_url}/cgi/search.pl"
        try:
            with httpx.Client(
                headers=self.headers,
                timeout=httpx.Timeout(8.0, connect=4.0),
                follow_redirects=True,
            ) as client:
                response = client.get(
                    url,
                    params={
                        "search_terms": ingredient,
                        "search_simple": 1,
                        "action": "process",
                        "json": 1,
                        "page_size": 1,
                    },
                )
                response.raise_for_status()
                payload = response.json()
        except (httpx.HTTPError, httpx.TimeoutException, ValueError) as error:
            raise UpstreamError(
                f"Open Food Facts nutrition lookup failed: {error}"
            ) from error

        products = payload.get("products") if isinstance(payload, dict) else None
        if not isinstance(products, list) or not products:
            raise UpstreamError(f"No Open Food Facts nutrition found for '{ingredient}'")

        nutriments = products[0].get("nutriments")
        if not isinstance(nutriments, dict):
            raise UpstreamError(f"Open Food Facts returned no nutrients for '{ingredient}'")

        def nutrient(key: str) -> float:
            value = nutriments.get(f"{key}_100g")
            try:
                return max(0.0, float(value))
            except (TypeError, ValueError):
                return 0.0

        return {
            "name": ingredient,
            "nutrition_per_100g": {
                "calories_kcal": nutrient("energy-kcal"),
                "protein_g": nutrient("proteins"),
                "carbohydrates_g": nutrient("carbohydrates"),
                "fat_g": nutrient("fat"),
                "fiber_g": nutrient("fiber"),
            },
            "source": "world.openfoodfacts.org",
            "fetched_at": datetime.now(timezone.utc).isoformat(),
        }

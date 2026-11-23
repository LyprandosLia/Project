import os
from datetime import datetime, timezone
from typing import Any

import httpx


class UpstreamError(Exception):
    pass


class GreekPriceProvider:
    def __init__(self) -> None:
        self.base_url = os.getenv(
            "CATALOG_PRICE_SOURCE_URL",
            "https://api.posokanei.gov.gr",
        ).strip().rstrip("/")
        if self.base_url.endswith("/products/search"):
            self.search_url = self.base_url
        else:
            self.search_url = self.base_url + "/products/search"
        self.headers = {
            "Accept": "application/json",
            "User-Agent": "Multi-Agent-Food-OS/1.0",
        }

    def fetch(self, ingredient: str) -> dict:
        try:
            with httpx.Client(
                headers=self.headers,
                timeout=httpx.Timeout(8.0, connect=4.0),
                follow_redirects=True,
            ) as client:
                response = client.post(
                    self.search_url,
                    json={
                        "title": ingredient,
                        "page": 1,
                        "page_size": 5,
                        "sort_by": "name",
                        "sort_order": "asc",
                    },
                )
                response.raise_for_status()
                payload = response.json()
        except (httpx.HTTPError, httpx.TimeoutException) as error:
            raise UpstreamError(
                f"PosoKanei product search failed: {error}"
            ) from error
        except ValueError as error:
            raise UpstreamError("PosoKanei returned invalid JSON") from error

        product = self._find_json_product(payload, ingredient)
        if product is None:
            raise UpstreamError(f"No comparable PosoKanei price found for '{ingredient}'")
        return product

    def _find_json_product(self, payload: Any, ingredient: str) -> dict | None:
        name = " ".join(ingredient.split()).casefold()
        stack = [payload]
        while stack:
            value = stack.pop()
            if isinstance(value, dict):
                product_name = str(
                    value.get("name")
                    or value.get("product_name")
                    or value.get("title")
                    or ""
                )
                if name in " ".join(product_name.split()).casefold():
                    record = self._price_record(value, product_name)
                    if record is not None:
                        return record
                stack.extend(value.values())
            elif isinstance(value, list):
                stack.extend(value)
        return None

    def _price_record(self, product: dict[str, Any], name: str) -> dict | None:
        raw_unit = str(product.get("unit") or product.get("price_unit") or "").strip().casefold()
        unit = self._normalize_unit(raw_unit)
        if unit is None:
            return None

        raw_quantity = product.get("unit_quantity", product.get("quantity"))
        try:
            package_quantity = float(str(raw_quantity).replace(",", ".")) if raw_quantity is not None else 1.0
        except ValueError:
            return None
        if package_quantity <= 0:
            return None

        price_data = self._lowest_product_price(product)
        if price_data is None:
            return None
        price, normalized = price_data
        if normalized and unit in {"g", "kg"}:
            price_unit = "kg"
        elif normalized and unit in {"ml", "l"}:
            price_unit = "l"
        elif normalized:
            price_unit = unit
        elif unit == "g":
            price = price * 1000.0 / package_quantity
            price_unit = "kg"
        elif unit == "ml":
            price = price * 1000.0 / package_quantity
            price_unit = "l"
        else:
            price = price / package_quantity
            price_unit = unit

        return {
            "name": name,
            "price": round(price, 4),
            "price_unit": price_unit,
            "currency": "EUR",
            "source": "posokanei.gov.gr",
            "fetched_at": datetime.now(timezone.utc).isoformat(),
        }

    def _lowest_product_price(self, product: dict[str, Any]) -> tuple[float, bool] | None:
        normalized_values: list[float] = []
        retailer_values: list[float] = []
        retailers = product.get("retailer_prices")
        if isinstance(retailers, list):
            for retailer in retailers:
                if not isinstance(retailer, dict):
                    continue
                self._append_price(normalized_values, retailer.get("price_normalized"))
                self._append_price(retailer_values, retailer.get("price"))
        if normalized_values:
            return min(normalized_values), True

        values = retailer_values
        statistics = product.get("price_stats")
        if isinstance(statistics, dict):
            self._append_price(values, statistics.get("min_price"))
        self._append_price(values, product.get("min_price"))
        self._append_price(values, product.get("price"))
        return (min(values), False) if values else None

    def _append_price(self, values: list[float], value: Any) -> None:
        if value is None:
            return
        try:
            price = float(str(value).replace(",", "."))
        except (TypeError, ValueError):
            return
        if price > 0:
            values.append(price)

    def _normalize_unit(self, unit: str) -> str | None:
        if unit in {"g", "gr", "gram", "grams", "γραμμάριο", "γραμμάρια"}:
            return "g"
        if unit in {"kg", "kgr", "kilogram", "kilograms", "κιλό", "κιλά"}:
            return "kg"
        if unit in {"ml", "milliliter", "milliliters", "χιλιοστόλιτρο", "χιλιοστόλιτρα"}:
            return "ml"
        if unit in {"l", "lt", "liter", "liters", "λίτρο", "λίτρα"}:
            return "l"
        if unit in {"piece", "pieces", "item", "items", "τεμάχιο", "τεμάχια"}:
            return "piece"
        return None


CatalogProvider = GreekPriceProvider

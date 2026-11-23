import os
import re
from datetime import datetime, timezone
from typing import Any

import httpx


class UpstreamError(Exception):
    pass


class GreekPriceProvider:
    def __init__(self) -> None:
        self.base_url = os.getenv(
            "CATALOG_PRICE_SOURCE_URL",
            "https://posokanei.gov.gr/",
        ).strip().rstrip("/")
        self.headers = {
            "Accept": "text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8",
            "Accept-Language": "el-GR,el;q=0.9,en;q=0.6",
            "User-Agent": (
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/131.0.0.0 Safari/537.36"
            ),
        }

    def fetch(self, ingredient: str) -> dict:
        try:
            with httpx.Client(
                headers=self.headers,
                timeout=httpx.Timeout(8.0, connect=4.0),
                follow_redirects=True,
            ) as client:
                response = client.get(self.base_url)
                response.raise_for_status()
        except (httpx.HTTPError, httpx.TimeoutException) as error:
            raise UpstreamError(
                f"posokanei.gov.gr price source request failed: {error}"
            ) from error

        payload = response.json() if "application/json" in response.headers.get("content-type", "") else None
        if isinstance(payload, dict):
            product = self._find_json_product(payload, ingredient)
            if product:
                return product

        price = self._find_html_price(response.text, ingredient)
        if price is None:
            raise UpstreamError(
                f"No keyless PosoKanei price data found for '{ingredient}'"
            )
        return {
            "name": ingredient,
            "price": price,
            "price_unit": "kg",
            "currency": "EUR",
            "source": "posokanei.gov.gr",
            "fetched_at": datetime.now(timezone.utc).isoformat(),
        }

    def _find_json_product(self, payload: dict[str, Any], ingredient: str) -> dict | None:
        name = ingredient.casefold()
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
                raw_price = value.get("price") or value.get("min_price")
                if name in product_name.casefold() and raw_price is not None:
                    try:
                        price = float(str(raw_price).replace(",", "."))
                    except ValueError:
                        continue
                    if price > 0:
                        unit = str(value.get("unit") or "piece").casefold()
                        if unit not in {"g", "kg", "ml", "l", "piece"}:
                            unit = "piece"
                        return {
                            "name": product_name,
                            "price": price,
                            "price_unit": unit,
                            "currency": "EUR",
                            "source": "posokanei.gov.gr",
                            "fetched_at": datetime.now(timezone.utc).isoformat(),
                        }
                stack.extend(value.values())
            elif isinstance(value, list):
                stack.extend(value)
        return None

    def _find_html_price(self, html: str, ingredient: str) -> float | None:
        escaped = re.escape(re.sub(r"\s+", " ", ingredient.strip()))
        pattern = re.compile(
            rf"{escaped}.{{0,300}}?([0-9]+(?:[.,][0-9]+)?)\s*(?:€|EUR)",
            re.IGNORECASE | re.DOTALL,
        )
        match = pattern.search(html)
        if not match:
            return None
        try:
            price = float(match.group(1).replace(",", "."))
        except ValueError:
            return None
        return price if price > 0 else None


CatalogProvider = GreekPriceProvider

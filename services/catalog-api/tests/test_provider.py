from typing import Any

from app.provider import GreekPriceProvider


class FakeResponse:
    def __init__(self, payload: dict[str, Any]) -> None:
        self.payload = payload

    def raise_for_status(self) -> None:
        return None

    def json(self) -> dict[str, Any]:
        return self.payload


class FakeClient:
    def __init__(self, response: FakeResponse) -> None:
        self.response = response
        self.url = ""
        self.payload: dict[str, Any] = {}

    def __enter__(self) -> "FakeClient":
        return self

    def __exit__(self, *args: object) -> None:
        return None

    def post(self, url: str, json: dict[str, Any]) -> FakeResponse:
        self.url = url
        self.payload = json
        return self.response


def test_price_provider_uses_posokanei_search_api(monkeypatch) -> None:
    monkeypatch.setenv("CATALOG_PRICE_SOURCE_URL", "https://api.posokanei.gov.gr")
    client = FakeClient(FakeResponse({
        "products": [
            {
                "name": "Red lentils",
                "unit": "kg",
                "unit_quantity": 1,
                "price_stats": {"min_price": 2.4},
            },
        ],
    }))
    monkeypatch.setattr("app.provider.httpx.Client", lambda **kwargs: client)

    record = GreekPriceProvider().fetch("Red lentils")

    assert client.url == "https://api.posokanei.gov.gr/products/search"
    assert client.payload["title"] == "Red lentils"
    assert client.payload["page"] == 1
    assert record["price"] == 2.4
    assert record["price_unit"] == "kg"
    assert record["source"] == "posokanei.gov.gr"


def test_price_provider_converts_package_price_to_base_unit(monkeypatch) -> None:
    monkeypatch.setenv("CATALOG_PRICE_SOURCE_URL", "https://api.posokanei.gov.gr")
    client = FakeClient(FakeResponse({
        "products": [
            {
                "name": "Rice",
                "unit": "g",
                "unit_quantity": 500,
                "price_stats": {"min_price": 1.25},
            },
        ],
    }))
    monkeypatch.setattr("app.provider.httpx.Client", lambda **kwargs: client)

    record = GreekPriceProvider().fetch("Rice")

    assert record["price"] == 2.5
    assert record["price_unit"] == "kg"

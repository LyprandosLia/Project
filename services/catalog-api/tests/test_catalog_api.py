from fastapi.testclient import TestClient

from app.main import app
from app.provider import GreekPriceProvider, UpstreamError


client = TestClient(app)


def use_fixture_fallback(monkeypatch) -> None:
    def unavailable(self, ingredient: str) -> dict:
        raise UpstreamError("test uses local fixture fallback")

    monkeypatch.setattr(GreekPriceProvider, "fetch", unavailable)


def test_health() -> None:
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


def test_price_lookup_success(monkeypatch) -> None:
    use_fixture_fallback(monkeypatch)
    response = client.get("/catalog/prices/Red%20lentils", params={"quantity": 500, "unit": "g"})
    assert response.status_code == 200
    body = response.json()
    assert body["ingredient"] == "Red lentils"
    assert body["quantity"] == 500
    assert body["unit"] == "g"
    assert body["price"] == 1.2
    assert body["currency"] == "EUR"
    assert body["source"] == "Approximate local estimate"


def test_post_price_lookup_success(monkeypatch) -> None:
    use_fixture_fallback(monkeypatch)
    response = client.post("/prices", json={"ingredient": "Rice", "quantity": 1, "unit": "kg"})
    assert response.status_code == 200
    assert response.json()["price"] == 2.1


def test_nutrition_lookup_success() -> None:
    response = client.get("/catalog/nutrition/Rice", params={"serving_quantity": 200, "serving_unit": "g"})
    assert response.status_code == 200
    body = response.json()
    assert body["calories_kcal"] == 260
    assert body["protein_g"] == 5.4
    assert body["ingredient"] == "Rice"
    assert body["source"] == "Approximate local estimate"


def test_unknown_ingredient_is_not_found(monkeypatch) -> None:
    use_fixture_fallback(monkeypatch)
    response = client.get("/catalog/prices/dragonfruit")
    assert response.status_code == 404
    assert "not found" in response.json()["detail"]


def test_invalid_quantity_is_rejected() -> None:
    response = client.post("/nutrition", json={
        "ingredient": "Rice",
        "serving_quantity": 0,
        "serving_unit": "g",
    })
    assert response.status_code == 422


def test_invalid_unit_is_rejected() -> None:
    response = client.get("/nutrition/Rice", params={"serving_unit": "cups"})
    assert response.status_code == 422

from app.provider import GreekPriceProvider, UpstreamError
from app.updater import update_catalog


class FixtureProvider(GreekPriceProvider):
    def __init__(self) -> None:
        self.base_url = "unused"

    def fetch(self, ingredient: str) -> dict:
        if ingredient == "Rice":
            return {
                "price": 2.1,
                "price_unit": "kg",
                "nutrition_per_100g": {
                    "calories_kcal": 130,
                    "protein_g": 2.7,
                    "carbohydrates_g": 28.2,
                    "fat_g": 0.3,
                    "fiber_g": 0.4,
                },
                "source": "fixture",
                "currency": "EUR",
                "fetched_at": "2026-11-23T10:00:00+00:00",
            }
        raise UpstreamError("fixture failure")


def test_updater_uses_keyless_source(monkeypatch, tmp_path) -> None:
    monkeypatch.delenv("CATALOG_PRICE_SOURCE_URL", raising=False)
    from app import catalog_store
    monkeypatch.setattr("app.updater.DATA_FILE", tmp_path / "catalog.json")
    import shutil
    shutil.copy(catalog_store.DATA_FILE, tmp_path / "catalog.json")
    report = update_catalog(FixtureProvider())
    assert report["status"] in {"ok", "partial"}
    assert report["provider"] == "posokanei.gov.gr"

import argparse
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from tempfile import NamedTemporaryFile

from app.catalog_store import load_catalog
from app.provider import GreekPriceProvider, UpstreamError


DATA_FILE = Path(__file__).with_name("catalog.json")


def update_catalog(provider: GreekPriceProvider | None = None) -> dict:
    catalog = load_catalog()
    price_provider = provider or GreekPriceProvider()
    report = {
        "started_at": datetime.now(timezone.utc).isoformat(),
        "provider": "posokanei.gov.gr",
        "updated": 0,
        "unchanged": 0,
        "failed": 0,
        "errors": [],
    }

    changed = False
    for name in sorted(catalog["ingredients"]):
        try:
            incoming = price_provider.fetch(name)
            required = {"price", "price_unit", "currency", "source", "fetched_at"}
            missing = sorted(required - incoming.keys())
            if missing:
                raise UpstreamError(f"missing fields: {', '.join(missing)}")

            existing = catalog["ingredients"][name]
            incoming = {**existing, **incoming}
            if incoming != existing:
                catalog["ingredients"][name] = incoming
                changed = True
                report["updated"] += 1
            else:
                report["unchanged"] += 1
        except UpstreamError as error:
            report["failed"] += 1
            report["errors"].append({"ingredient": name, "error": str(error)})

    if changed:
        with NamedTemporaryFile("w", encoding="utf-8", dir=DATA_FILE.parent, delete=False) as temp_file:
            json.dump(catalog, temp_file, ensure_ascii=False, indent=2, sort_keys=True)
            temp_file.write("\n")
            temp_path = Path(temp_file.name)
        os.replace(temp_path, DATA_FILE)

    report["status"] = "ok" if report["failed"] == 0 else "partial"
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description="Update the local catalog from a configured provider")
    parser.parse_args()
    report = update_catalog()
    print(json.dumps(report, sort_keys=True))
    return 1 if report["status"] != "ok" else 0


if __name__ == "__main__":
    sys.exit(main())

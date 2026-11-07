#!/usr/bin/env python3
"""Read-only structural audit for the JSON emitted by vehicles:export-reference-workbook."""
import argparse
import json
import re
import unicodedata
from collections import defaultdict
from pathlib import Path

HEADER_KEYS = {"Marque", "Generation_ID", "Engine_ID", "Fitment_ID", "Oil_Fitment_ID", "Source_ID", "Rang", "Priorité"}
IDENTITIES = {
    "CAR_BRANDS": ["Marque"], "CAR_MODELS": ["Marque", "Modèle / famille"],
    "TIRE_BRANDS": ["Marque"], "TIRE_MODELS": ["Marque", "Gamme / modèle"],
    "TIRE_SIZES": ["Dimension affichée"], "OIL_BRANDS": ["Marque"],
    "OIL_MODELS": ["Marque", "Gamme", "Viscosité indicative"],
    "CAR_GENERATIONS": ["Generation_ID"], "CAR_ENGINES": ["Engine_ID"],
    "VEHICLE_TIRE_FITMENTS": ["Fitment_ID"], "VEHICLE_OIL_FITMENTS": ["Oil_Fitment_ID"],
    "TECH_SOURCES": ["Source_ID"],
}
REFERENCES = [
    ("CAR_GENERATIONS", "Source_ID", "TECH_SOURCES"),
    ("CAR_ENGINES", "Source_ID", "TECH_SOURCES"),
    ("CAR_ENGINES", "Generation_ID", "CAR_GENERATIONS"),
    ("VEHICLE_TIRE_FITMENTS", "Source_ID", "TECH_SOURCES"),
    ("VEHICLE_TIRE_FITMENTS", "Generation_ID", "CAR_GENERATIONS"),
    ("VEHICLE_TIRE_FITMENTS", "Engine_ID", "CAR_ENGINES"),
    ("VEHICLE_OIL_FITMENTS", "Source_ID requis", "TECH_SOURCES"),
    ("VEHICLE_OIL_FITMENTS", "Generation_ID", "CAR_GENERATIONS"),
    ("VEHICLE_OIL_FITMENTS", "Engine_ID", "CAR_ENGINES"),
]

def norm(value):
    value = unicodedata.normalize("NFKC", str(value or "")).strip().casefold()
    return re.sub(r"\s+", " ", value)

def records(sheet):
    rows = sheet.get("rows", [])
    header_index = next((i for i, row in enumerate(rows[:8])
                         if row.get("cells") and row["cells"][0] in HEADER_KEYS), None)
    if header_index is None:
        header_index = 0 if rows and rows[0].get("cells") else None
    if header_index is None:
        return []
    headers = rows[header_index].get("cells", [])
    result = []
    for row in rows[header_index + 1:]:
        cells = row.get("cells", [])
        meaningful = [v for v in cells if v not in (None, "") and not (isinstance(v, str) and v.startswith("="))]
        if not meaningful:
            continue
        result.append({"excel_row": row.get("excel_row"),
                       "data": {str(h): cells[i] if i < len(cells) else None
                                for i, h in enumerate(headers) if h not in (None, "")}})
    return result

def audit(payload):
    sheets = payload.get("sheets", {})
    parsed = {name: records(sheet) for name, sheet in sheets.items()}
    issues = []
    for sheet, keys in IDENTITIES.items():
        groups = defaultdict(list)
        for row in parsed.get(sheet, []):
            key = tuple(norm(row["data"].get(k)) for k in keys)
            if all(key):
                groups[key].append(row["excel_row"])
        for key, lines in groups.items():
            if len(lines) > 1:
                issues.append({"severity": "error", "type": "duplicate_key", "sheet": sheet,
                               "fields": keys, "key": list(key), "excel_rows": lines})

    ids = {
        "TECH_SOURCES": {norm(r["data"].get("Source_ID")) for r in parsed.get("TECH_SOURCES", [])},
        "CAR_GENERATIONS": {norm(r["data"].get("Generation_ID")) for r in parsed.get("CAR_GENERATIONS", [])},
        "CAR_ENGINES": {norm(r["data"].get("Engine_ID")) for r in parsed.get("CAR_ENGINES", [])},
    }
    for sheet, field, target in REFERENCES:
        for row in parsed.get(sheet, []):
            value = norm(row["data"].get(field))
            if value and value not in ids[target]:
                issues.append({"severity": "error", "type": "broken_reference", "sheet": sheet,
                               "field": field, "value": row["data"].get(field),
                               "excel_row": row["excel_row"], "target_sheet": target})

    models = {(norm(r["data"].get("Marque")), norm(r["data"].get("Modèle / famille")))
              for r in parsed.get("CAR_MODELS", [])}
    for sheet in ("CAR_GENERATIONS", "CAR_ENGINES", "VEHICLE_TIRE_FITMENTS", "VEHICLE_OIL_FITMENTS"):
        for row in parsed.get(sheet, []):
            pair = (norm(row["data"].get("Marque")), norm(row["data"].get("Modèle")))
            if all(pair) and pair not in models:
                issues.append({"severity": "error", "type": "vehicle_model_not_in_catalogue",
                               "sheet": sheet, "make": pair[0], "model": pair[1],
                               "excel_row": row["excel_row"]})

    for sheet, fields in {
        "TIRE_SIZES": ["Indice de charge", "Indice de vitesse", "RunFlat"],
        "OIL_MODELS": ["Norme ACEA confirmée", "Norme API confirmée", "Homologation constructeur",
                       "Référence article", "Source fiche technique"],
        "CAR_GENERATIONS": ["Code génération", "Année début production", "Année fin production"],
        "CAR_ENGINES": ["Code moteur", "Année début", "Année fin"],
    }.items():
        for field in fields:
            rows = parsed.get(sheet, [])
            missing = sum(r["data"].get(field) in (None, "") for r in rows)
            if rows and missing:
                issues.append({"severity": "warning", "type": "technical_coverage_gap",
                               "sheet": sheet, "field": field, "missing": missing, "total": len(rows)})

    for sheet, make_field, model_field in [
        ("MARKET_PRIORITY_2026", "Marque", "Modèle"),
        ("RENTAL_PRIORITY_2026", "Marque", "Modèle"),
    ]:
        for row in parsed.get(sheet, []):
            make, model = row["data"].get(make_field), row["data"].get(model_field)
            pair = (norm(make), norm(model))
            if not all(pair):
                continue
            if "/" in pair[0] or "/" in pair[1]:
                issues.append({"severity": "warning", "type": "composite_priority_label",
                               "sheet": sheet, "make": make, "model": model,
                               "excel_row": row["excel_row"]})
            elif pair not in models and (pair[0], norm(model.split()[0])) not in models:
                issues.append({"severity": "warning", "type": "priority_model_missing",
                               "sheet": sheet, "make": make, "model": model,
                               "excel_row": row["excel_row"]})

    catalog_sheets = ["CAR_BRANDS", "CAR_MODELS", "TIRE_BRANDS", "TIRE_MODELS", "TIRE_SIZES",
                      "OIL_BRANDS", "OIL_MODELS", "STOCK_LOCATIONS", "CAR_GENERATIONS",
                      "CAR_ENGINES", "VEHICLE_TIRE_FITMENTS", "VEHICLE_OIL_FITMENTS",
                      "TECH_SOURCES", "MARKET_PRIORITY_2026", "RENTAL_PRIORITY_2026"]
    summary = {
        "sheet_count": len(sheets),
        "catalogue_data_records": sum(len(parsed.get(name, [])) for name in catalog_sheets),
        "error_count": sum(i["severity"] == "error" for i in issues),
        "warning_count": sum(i["severity"] == "warning" for i in issues),
        "empty_templates": [name for name in ["VEHICLE_FITMENTS", "PRODUCTS_IMPORT", "STOCK_OPENING", "USED_TYRES_IMPORT"]
                            if name in sheets and not parsed.get(name)],
    }
    return {"file": payload.get("file"), "summary": summary,
            "sheet_counts": {name: len(rows) for name, rows in parsed.items()}, "issues": issues}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("input", type=Path)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    report = audit(json.loads(args.input.read_text(encoding="utf-8")))
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report["summary"], ensure_ascii=False))
    return 1 if report["summary"]["error_count"] else 0

if __name__ == "__main__":
    raise SystemExit(main())

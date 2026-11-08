import importlib.util
import unittest
from pathlib import Path

SCRIPT = Path(__file__).parents[1] / "audit_reference_workbook.py"
spec = importlib.util.spec_from_file_location("workbook_audit", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class WorkbookAuditTests(unittest.TestCase):
    def test_duplicate_and_broken_reference_are_errors(self):
        data = {"sheets": {
            "TECH_SOURCES": {"rows": [
                {"excel_row": 1, "cells": ["Source_ID", "Éditeur", "Titre", "URL", "Fiabilité"]},
                {"excel_row": 2, "cells": ["SRC-1", "OEM", "Manual", "https://example.test", "A"]}]},
            "CAR_GENERATIONS": {"rows": [
                {"excel_row": 1, "cells": ["Generation_ID", "Marque", "Modèle", "Libellé génération / gamme", "Source_ID"]},
                {"excel_row": 2, "cells": ["GEN-1", "Make", "Model", "Gen", "BAD-SOURCE"]},
                {"excel_row": 3, "cells": ["GEN-1", "Make", "Model", "Gen", "SRC-1"]}]},
            "CAR_MODELS": {"rows": [
                {"excel_row": 1, "cells": ["Marque", "Modèle / famille"]},
                {"excel_row": 2, "cells": ["Make", "Model"]}]}}}
        report = module.audit(data)
        types = {issue["type"] for issue in report["issues"]}
        self.assertIn("duplicate_key", types)
        self.assertIn("broken_reference", types)
        self.assertEqual(report["summary"]["error_count"], 2)

    def test_formula_only_rows_are_not_products(self):
        sheet = {"rows": [
            {"excel_row": 1, "cells": ["SKU", "HT"]},
            {"excel_row": 2, "cells": ["", '=IF(A2="","",ROUND(A2/1.2,2))']},
            {"excel_row": 3, "cells": ["SKU-1", '=IF(A3="","",ROUND(A3/1.2,2))']}]}
        result = module.records(sheet)
        self.assertEqual(len(result), 1)
        self.assertEqual(result[0]["data"]["SKU"], "SKU-1")

if __name__ == "__main__":
    unittest.main(verbosity=2)

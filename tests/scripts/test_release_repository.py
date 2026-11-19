"""Release feed checks; run with python3 -m unittest discover -s tests/scripts."""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("release_repository", ROOT / "scripts/release-repository.py")
RELEASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RELEASE)


class ReleaseRepositoryTests(unittest.TestCase):
    def test_feed_is_current_and_preserves_actual_dependency_edges(self):
        actual = json.loads((ROOT / "resources/distribution/packages.json").read_text())
        self.assertEqual(actual, RELEASE.repository(ROOT, "0.1.2", "v0.1.2"))
        split = json.loads((ROOT / "split.json").read_text())
        self.assertEqual(len(actual["packages"]), len(split))
        for prefix, target in split.items():
            manifest = json.loads((ROOT / prefix / "composer.json").read_text())
            package = actual["packages"][target["name"]]["0.1.2"]
            self.assertEqual(package["require"], manifest["require"])
            self.assertEqual(package.get("conflict"), manifest.get("conflict"))
            self.assertEqual(package.get("suggest"), manifest.get("suggest"))
            self.assertEqual(package.get("extra", {}).get("laravel"), manifest.get("extra", {}).get("laravel"))
            self.assertEqual(package["source"]["reference"], "v0.1.2")
            self.assertEqual(package["dist"]["url"], "https://api.github.com/repos/axiomasoft/packman/zipball/v0.1.2")
            for paths in package["autoload"]["psr-4"].values():
                for path in paths if isinstance(paths, list) else [paths]:
                    self.assertTrue((ROOT / path).is_dir(), path)
            for binary in package.get("bin", []):
                self.assertTrue((ROOT / binary).is_file(), binary)
            for include in package.get("extra", {}).get("phpstan", {}).get("includes", []):
                self.assertTrue((ROOT / include).is_file(), include)

    def test_all_composer_paths_move_together_without_mutating_manifest(self):
        manifest = {
            "name": "acme/example", "require": {"php": "^8.4"},
            "autoload": {
                "psr-4": {"Example\\": ["src/", "generated/"]},
                "psr-0": {"Legacy_": "lib/"},
                "classmap": ["legacy/"], "files": ["bootstrap.php"],
                "exclude-from-classmap": ["/tests/", "**/Fixtures/"],
            },
            "autoload-dev": {"psr-4": {"Example\\Tests\\": "tests/"}},
            "bin": ["bin/example"], "include-path": ["lib/"],
            "extra": {"phpstan": {"includes": ["extension.neon"]}},
        }
        original = json.loads(json.dumps(manifest))
        result = RELEASE.release_package(manifest, "packages/example", "0.1.2", "v0.1.2")
        self.assertEqual(manifest, original)
        self.assertEqual(result["autoload"]["psr-4"]["Example\\"],
                         ["packages/example/src/", "packages/example/generated/"])
        self.assertEqual(result["autoload"]["exclude-from-classmap"],
                         ["/packages/example/tests/", "packages/example/**/Fixtures/"])
        self.assertEqual(result["bin"], ["packages/example/bin/example"])
        self.assertEqual(result["extra"]["phpstan"]["includes"], ["packages/example/extension.neon"])

    def test_rejects_unrelated_tags_and_package_paths_outside_owner(self):
        for version, tag in (("0.1.2", "v0.1.3"), ("dev-main", "dev-main")):
            with self.assertRaises(ValueError):
                RELEASE.repository(ROOT, version, tag)
        with self.assertRaises(ValueError):
            RELEASE.prefixed_path("packages/core", "../other/src/")


if __name__ == "__main__":
    unittest.main()

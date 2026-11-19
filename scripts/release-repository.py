#!/usr/bin/env python3
"""Build the public Composer repository for a versioned monorepo release."""
from __future__ import annotations

import argparse
import copy
import json
from pathlib import Path, PurePosixPath
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
SOURCE_URL = "https://github.com/axiomasoft/packman.git"


def prefixed_path(prefix: str, path: str) -> str:
    """Keep Composer paths relative to the downloaded monorepo, including globs."""
    leading_slash = path.startswith("/")
    relative = path.removeprefix("/").removeprefix("./")
    if ".." in PurePosixPath(relative).parts:
        raise ValueError(f"package path must stay inside its package: {path}")
    return ("/" if leading_slash else "") + prefix + "/" + relative


def prefix_autoload(autoload: dict, prefix: str) -> dict:
    result = copy.deepcopy(autoload)
    for kind in ("psr-0", "psr-4"):
        for namespace, paths in result.get(kind, {}).items():
            result[kind][namespace] = (
                [prefixed_path(prefix, path) for path in paths]
                if isinstance(paths, list) else prefixed_path(prefix, paths)
            )
    for kind in ("classmap", "files", "exclude-from-classmap"):
        if kind in result:
            result[kind] = [prefixed_path(prefix, path) for path in result[kind]]
    return result


def release_package(manifest: dict, prefix: str, version: str, tag: str) -> dict:
    package = copy.deepcopy(manifest)
    # These are root-project controls, not dependency metadata.
    for key in ("repositories", "scripts", "config", "minimum-stability", "prefer-stable"):
        package.pop(key, None)
    for key in ("autoload", "autoload-dev"):
        if key in package:
            package[key] = prefix_autoload(package[key], prefix)
    for key in ("bin", "include-path"):
        if key in package:
            package[key] = [prefixed_path(prefix, path) for path in package[key]]
    phpstan = package.get("extra", {}).get("phpstan", {})
    if "includes" in phpstan:
        phpstan["includes"] = [prefixed_path(prefix, path) for path in phpstan["includes"]]
    package["version"] = version
    package["source"] = {"type": "git", "url": SOURCE_URL, "reference": tag}
    package["dist"] = {
        "type": "zip",
        "url": "https://api.github.com/repos/axiomasoft/packman/zipball/" + tag,
        "reference": tag,
    }
    return package


def repository(root: Path, version: str, tag: str) -> dict:
    if re.fullmatch(r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)", version) is None:
        raise ValueError("version must be a stable three-component SemVer")
    if tag != "v" + version:
        raise ValueError("release tag must use the repository convention: v" + version)
    split = json.loads((root / "split.json").read_text(encoding="utf-8"))
    packages = {}
    manifests = {}
    for prefix, target in sorted(split.items()):
        manifest = json.loads((root / prefix / "composer.json").read_text(encoding="utf-8"))
        name = manifest["name"]
        if name != target["name"] or name in manifests:
            raise ValueError(f"inconsistent or repeated split package: {name}")
        manifests[name] = manifest
        packages[name] = {version: release_package(manifest, prefix, version, tag)}
    dev_only = {name for name, manifest in manifests.items()
                if manifest.get("extra", {}).get("packman", {}).get("dev_only")}
    for name, manifest in manifests.items():
        if name not in dev_only and dev_only.intersection(manifest.get("require", {})):
            raise ValueError(f"runtime package requires a development package: {name}")
    return {"packages": packages}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", default="0.1.2")
    parser.add_argument("--tag", default="v0.1.2")
    parser.add_argument("--output", type=Path, default=ROOT / "resources/distribution/packages.json")
    parser.add_argument("--check", action="store_true", help="fail if the committed feed differs")
    args = parser.parse_args()
    try:
        encoded = json.dumps(repository(ROOT, args.version, args.tag), indent=4, ensure_ascii=False) + "\n"
        if args.check:
            if not args.output.is_file() or args.output.read_text(encoding="utf-8") != encoded:
                print(f"Release repository is stale: {args.output}", file=sys.stderr)
                return 1
            print(f"Release repository matches {args.tag}: {args.output}")
        else:
            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(encoded, encoding="utf-8")
            print(f"Wrote release repository for {args.tag}: {args.output}")
    except (OSError, ValueError, KeyError) as error:
        print(f"release-repository: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Exercise archived owner snapshots in real Laravel applications, entirely locally.

Run from Packman: ./bin/php84 python3 scripts/verify-ecosystem.py
The nine sibling repositories and Composer/PHP 8.4 are required. No owner checkout is
modified. Receipts, Composer logs and the upgraded application remain under build/.
"""
from __future__ import annotations

import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "fixtures/ecosystem-consumer"
# These are development snapshots, never assertions that consumer releases exist.
SNAPSHOTS = {
    "chatom": "1.0.x-dev", "vaulter": "1.0.x-dev", "azguard": "2.0.x-dev",
    "tenant": "1.0.x-dev", "sockit": "0.1.x-dev", "corex": "1.0.x-dev",
    "billium": "0.1.x-dev", "commerz": "0.1.x-dev", "proxyma": "1.0.x-dev",
}
JOINT = [
    "corex-access", "corex-tenancy", "corex-media", "vaulter-azguard", "tenant-laravel",
    "tenant-panels", "chatom-poll", "billium-core", "sockit-posts", "commerz-full", "proxyma-core",
]
UPGRADE = ["corex-access", "corex-tenancy", "corex-media", "tenant-laravel"]
PACKMAN_RUNTIME = ["packman", "packman-plugins", "packman-schema", "packman-modules", "packman-panels"]


def write_json(path: Path, data: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")


def git(repo: Path, *arguments: str) -> str:
    return subprocess.check_output(["git", "-C", str(repo), *arguments], text=True).strip()


class Check:
    def __init__(self, args: argparse.Namespace):
        self.args = args
        self.workspace = args.workspace.resolve()
        if args.work_dir:
            self.work = args.work_dir.resolve()
            self.work.mkdir(parents=True, exist_ok=False)
        else:
            (ROOT / "build").mkdir(exist_ok=True)
            self.work = Path(tempfile.mkdtemp(prefix="ecosystem-", dir=ROOT / "build"))
        self.archives = self.work / "archives"
        self.archives.mkdir()
        self.logs = self.work / "logs"
        self.logs.mkdir()
        self.env = {
            **os.environ, "APP_ENV": "local", "CACHE_STORE": "array", "SESSION_DRIVER": "array",
            "QUEUE_CONNECTION": "sync", "LOG_CHANNEL": "stderr", "COMPOSER_NO_INTERACTION": "1",
        }
        self.php = args.php
        self.composer = args.composer
        self.receipt: dict = {"repositories": {}, "archives": {}, "checks": {}, "applications": {}}
        self.counter = 0
        self.packages: dict[tuple[str, str], dict] = {}
        self.expected: dict[tuple[str, str], str] = {}
        self.baseline_expected: dict[tuple[str, str], str] = {}

    def run(self, command: list[str], cwd: Path, label: str, extra: dict | None = None) -> str:
        self.counter += 1
        print(f"[{self.counter}] {label}", flush=True)
        log = self.logs / f"{self.counter:02d}-{label}.log"
        with log.open("w") as stream:
            completed = subprocess.run(command, cwd=cwd, env=self.env | (extra or {}), text=True,
                                       stdout=stream, stderr=subprocess.STDOUT)
        output = log.read_text()
        if completed.returncode:
            raise RuntimeError(f"{label} exited {completed.returncode}; log: {log}\n{output[-5000:]}")
        return output

    def archive_owner(self, owner: str, ref: str, version: str, baseline: bool = False) -> None:
        repo = self.workspace / owner
        sha = git(repo, "rev-parse", f"{ref}^{{commit}}")
        label = "baseline:" + version if baseline else version
        self.receipt["repositories"].setdefault(owner, {})[label] = sha
        remote = git(repo, "remote", "get-url", "origin")
        archive_time = git(repo, "show", "-s", "--format=%cI", sha)
        manifests = [p for p in git(repo, "ls-tree", "-r", "--name-only", sha).splitlines()
                     if p.startswith("packages/") and p.count("/") == 2 and p.endswith("/composer.json")]
        if not manifests:
            raise RuntimeError(f"No package manifests at {owner}:{sha}")
        for manifest in sorted(manifests):
            package = json.loads(git(repo, "show", f"{sha}:{manifest}"))
            name = package["name"]
            target = self.archives / f"{name.replace('/', '-')}-{sha}.zip"
            if not target.is_file():
                subprocess.run(["git", "-C", str(repo), "archive", "--format=zip", f"--mtime={archive_time}",
                                f"--output={target}", f"{sha}:{manifest.rsplit('/', 1)[0]}"], check=True)
            content = target.read_bytes()
            package.update(version=version,
                           source={"type": "git", "url": remote, "reference": sha},
                           dist={"type": "zip", "url": target.as_uri(), "reference": sha,
                                 "shasum": hashlib.sha1(content).hexdigest()})
            self.packages[name, version] = package
            self.expected[name, version] = sha
            self.receipt["archives"][name + "@" + version + "#" + sha] = {
                "sha": sha, "sha256": hashlib.sha256(content).hexdigest(), "baseline": baseline,
            }

    def source_governance(self) -> None:
        if not (ROOT / "vendor/autoload.php").is_file():
            raise RuntimeError("Install Packman's development dependencies with ./bin/php84 composer install first")
        if git(ROOT, "status", "--porcelain"):
            raise RuntimeError("The current Packman gate source must be committed before verification")
        self.receipt["source_governance_tool"] = {"root": str(ROOT), "sha": git(ROOT, "rev-parse", "HEAD")}
        for owner in ["packman", *SNAPSHOTS]:
            repo = self.workspace / owner
            if git(repo, "status", "--porcelain"):
                raise RuntimeError(f"Commit {owner}'s changes before verifying its source governance")
            label = "source-governance-" + owner
            output = self.run([self.php, str(ROOT / "bin/packman"), "gate", f"--root={repo}",
                               "--only=ownership", "--only=attributes-architecture", "--json"], ROOT, label)
            report = json.loads(output)
            gates = report.get("gates", [])
            if (report.get("ok") is not True or len(gates) != 2
                    or {gate.get("id") for gate in gates} != {"ownership", "attributes-architecture"}
                    or any(gate.get("passed") is not True for gate in gates)):
                raise RuntimeError(f"{owner} did not pass both current source governance gates")
            self.receipt["checks"][label] = {"source_sha": git(repo, "rev-parse", "HEAD"), "report": report}

    def public_dependencies(self) -> None:
        # Reuse reviewed public-package metadata from the existing owner locks. This
        # permits offline execution when Composer's normal download cache is present.
        # Missing cached archives retain their original public dist URL.
        cache = subprocess.check_output([self.composer, "config", "cache-files-dir", "--absolute"],
                                        cwd=ROOT, env=self.env, text=True).strip()
        for owner in ["packman", *SNAPSHOTS]:
            lock = self.workspace / owner / "composer.lock"
            if not lock.is_file():
                continue
            data = json.loads(lock.read_text())
            for entry in [*data.get("packages", []), *data.get("packages-dev", [])]:
                if entry["name"].startswith("axiomasoft/"):
                    continue
                package = copy.deepcopy(entry)
                dist = package.get("dist", {})
                url = dist.get("url", "")
                if dist.get("type") == "zip" and url:
                    cached = Path(cache) / package["name"] / (hashlib.sha1(url.encode()).hexdigest() + ".zip")
                    if cached.is_file():
                        dist["url"] = cached.as_uri()
                self.packages.setdefault((package["name"], package["version"]), package)

    def prepare_catalog(self) -> None:
        self.archive_owner("packman", self.args.packman_ref, "0.1.2")
        self.archive_owner("packman", self.args.baseline_packman_ref, "0.1.1")
        for owner, version in SNAPSHOTS.items():
            self.archive_owner(owner, "HEAD", version)
        self.public_dependencies()
        candidate_packages = self.packages.copy()
        candidate_expected = self.expected.copy()
        self.archive_owner("vaulter", self.args.baseline_vaulter_ref, SNAPSHOTS["vaulter"], baseline=True)
        self.baseline_expected = self.expected.copy()
        for filename, packages in [("baseline-packages.json", self.packages), ("packages.json", candidate_packages)]:
            repository: dict[str, dict] = {}
            for (name, version), package in packages.items():
                repository.setdefault(name, {})[version] = package
            write_json(self.work / filename, {"packages": repository})
        self.packages = candidate_packages
        self.expected = candidate_expected
        write_json(self.work / "inputs.json", self.receipt)

    def manifest(self, requirements: list[str], packman_version: str, baseline: bool = False) -> dict:
        required = {"php": "^8.4", "laravel/framework": "^13.0"}
        for name in requirements:
            required["axiomasoft/" + name] = "*@dev"
        # Constrain every installed Packman runtime package so an upgrade cannot
        # accidentally leave the old kernel with a new adapter, or vice versa.
        selected = ["packman", "packman-plugins"] if not requirements else PACKMAN_RUNTIME
        for name in selected:
            required["axiomasoft/" + name] = packman_version
        return {
            "name": "packman-fixture/consumer", "type": "project", "require": required,
            "repositories": [{"type": "composer", "url": (self.work / ("baseline-packages.json" if baseline else "packages.json")).as_uri()}],
            "autoload": {"files": ["fixture.php"]},
            "scripts": {"post-autoload-dump": ["Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
                                                  "@php artisan package:discover --ansi"]},
            "minimum-stability": "dev", "prefer-stable": True,
            "config": {"allow-plugins": False},
        }

    def app(self, name: str, requirements: list[str], packman_version: str, minimal: bool = False, baseline: bool = False) -> Path:
        app = self.work / name
        app.mkdir()
        for path in ["bootstrap/cache", "config", "routes", "storage/framework/cache", "storage/framework/views",
                     "storage/logs", "storage/app/private", "database"]:
            (app / path).mkdir(parents=True, exist_ok=True)
        (app / "bootstrap/app.php").write_text("<?php\nreturn Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->withExceptions()->create();\n")
        (app / "bootstrap/providers.php").write_text("<?php\nreturn [PackmanEcosystemFixture\\FixtureServiceProvider::class];\n")
        (app / "artisan").write_text("<?php\nrequire __DIR__.'/vendor/autoload.php';\nexit((require __DIR__.'/bootstrap/app.php')->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n")
        source = FIXTURE / ("minimal" if minimal else "joint")
        for file in source.iterdir():
            if file.name == "config-tenant.php":
                shutil.copyfile(file, app / "config/tenant.php")
            else:
                shutil.copyfile(file, app / file.name)
        if not minimal:
            (app / "config/chatom.php").write_text("<?php\nreturn ['actors' => ['user_model' => Illuminate\\Foundation\\Auth\\User::class]];\n")
        write_json(app / "composer.json", self.manifest(requirements, packman_version, baseline))
        self.run([self.composer, "update", "--prefer-dist", "--no-progress", "--no-audit"], app, name + "-install")
        self.verify_installed(app, name + "-install", baseline)
        return app

    def verify_installed(self, app: Path, label: str, baseline: bool = False) -> None:
        lock = json.loads((app / "composer.lock").read_text())
        actual = json.loads((app / "vendor/composer/installed.json").read_text())
        actual_packages = {p["name"]: p for p in actual["packages"]}
        installed: dict = {}
        expected = self.baseline_expected if baseline else self.expected
        for package in lock["packages"]:
            name, version = package["name"], package["version"]
            if (name, version) not in expected:
                continue
            sha = expected[name, version]
            if package.get("dist", {}).get("reference") != sha or package.get("source", {}).get("reference") != sha:
                raise RuntimeError(f"Installed reference mismatch for {name}")
            path = app / "vendor" / name
            metapackage = package.get("type") == "metapackage"
            if not metapackage and (actual_packages[name].get("installation-source") != "dist" or (path / ".git").exists()):
                raise RuntimeError(f"Expected an archive installation; Composer fell back to source for {name}")
            if path.is_symlink() or any(p.is_symlink() for p in path.rglob("*")):
                raise RuntimeError(f"Package installation contains symlinks: {path}")
            installed[name] = {
                "version": version, "sha": sha, "dist": None if metapackage else "zip",
                "installation_source": "metapackage" if metapackage else "dist", "symlink": False,
            }
        if not installed:
            raise RuntimeError("No owner packages were installed")
        self.receipt["applications"][label] = installed

    def php_check(self, app: Path, file: str, label: str, *args: str, extra: dict | None = None) -> dict:
        output = self.run([self.php, file, *args], app, label, extra)
        # Laravel can render an uncaught bootstrap exception while the PHP process
        # returns zero. Successful process status alone is not an operation proof.
        try:
            result = json.loads(output.strip().splitlines()[-1])
        except (json.JSONDecodeError, IndexError) as error:
            raise RuntimeError(f"{label} did not return its JSON proof:\n{output[-3000:]}") from error
        self.receipt["checks"][label] = result
        return result

    def execute(self) -> None:
        print(f"Receipts and applications: {self.work}", flush=True)
        self.source_governance()
        self.prepare_catalog()
        minimal = self.app("minimal", [], "0.1.2", minimal=True)
        self.php_check(minimal, "check.php", "minimal-no-db-two-hosts")
        joint = self.app("joint", JOINT, "0.1.2")
        self.run([self.php, "artisan", "modules:compile"], joint, "joint-compile")
        self.php_check(joint, "check.php", "joint-no-db-two-hosts")
        database = joint / "database/media.sqlite"
        database.touch()
        self.php_check(joint, "media.php", "joint-corex-media", "seed",
                       extra={"DB_CONNECTION": "sqlite", "DB_DATABASE": str(database)})
        upgrade = self.app("upgrade", UPGRADE, "0.1.1", baseline=True)
        database = upgrade / "database/media.sqlite"
        database.touch()
        env = {"DB_CONNECTION": "sqlite", "DB_DATABASE": str(database)}
        before = self.php_check(upgrade, "media.php", "upgrade-baseline-seed", "seed", extra=env)
        manifest = json.loads((upgrade / "composer.json").read_text())
        for name in PACKMAN_RUNTIME:
            manifest["require"]["axiomasoft/" + name] = "0.1.2"
        manifest["repositories"][0]["url"] = (self.work / "packages.json").as_uri()
        write_json(upgrade / "composer.json", manifest)
        self.run([self.composer, "update", "axiomasoft/packman*", "axiomasoft/vaulter*", "--with-all-dependencies", "--prefer-dist", "--no-progress", "--no-audit"], upgrade, "upgrade-to-candidate", env)
        self.verify_installed(upgrade, "upgrade-candidate")
        baseline_vaulter = self.receipt["applications"]["upgrade-install"]["axiomasoft/vaulter-core"]["sha"]
        candidate_vaulter = self.receipt["applications"]["upgrade-candidate"]["axiomasoft/vaulter-core"]["sha"]
        if baseline_vaulter == candidate_vaulter:
            raise RuntimeError("Vaulter upgrade proof requires different baseline and candidate source references")
        after = self.php_check(upgrade, "media.php", "upgrade-old-data-read", "read", extra=env)
        if before["operation"] != after["operation"] or after["corex_migrations_run"]:
            raise RuntimeError("Upgrade changed old data or repeated already applied Corex migrations")
        self.receipt["status"] = "passed"
        self.receipt["limits"] = [
            "Consumer archives are exact development snapshots, not published consumer tags.",
            "The full graph uses local owner archives; this does not by itself verify remote downloads.",
            "SQLite verifies media persistence, not PostgreSQL-only product persistence or physical tenant cloning.",
            "Upgrade changes Packman 0.1.1 to 0.1.2 and baseline Vaulter to current HEAD in the same application; other consumers stay fixed.",
        ]
        write_json(self.work / "receipt.json", self.receipt)
        print(f"PASS: minimal/no-DB, simultaneous hosts, nine-consumer graph, Corex Media/Vaulter dedup, upgrade old-data read.\n{self.work / 'receipt.json'}", flush=True)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--workspace", type=Path, default=ROOT.parent)
    parser.add_argument("--work-dir", type=Path, help="New directory for retained apps/logs; must not already exist")
    parser.add_argument("--packman-ref", default="HEAD")
    parser.add_argument("--baseline-packman-ref", default="v0.1.1")
    parser.add_argument("--baseline-vaulter-ref", default="9c76f96b53430dae697aaf02f0369337e10ceed5")
    parser.add_argument("--php", default="php")
    parser.add_argument("--composer", default="composer")
    args = parser.parse_args()
    Check(args).execute()


if __name__ == "__main__":
    main()

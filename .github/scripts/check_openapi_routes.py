#!/usr/bin/env python3
"""Fails when contracts/openapi.yaml and the real Laravel API routes differ.

Usage: check_openapi_routes.py <openapi.yaml> <route-list.json>
route-list.json is the output of `php artisan route:list --path=api --json`.
Also checks that every operation documents its error responses consistently:
authenticated operations list 401, verification-gated ones list 403.
"""
import json
import re
import sys

import yaml

METHODS = {"get", "post", "put", "patch", "delete"}


def norm(path: str) -> str:
    return re.sub(r"\{[^}]+\}", "{}", path.rstrip("/") or "/")


def main() -> int:
    spec = yaml.safe_load(open(sys.argv[1], encoding="utf-8"))
    routes = json.load(open(sys.argv[2], encoding="utf-8"))

    documented = {}
    for path, item in spec["paths"].items():
        for method, op in item.items():
            if method in METHODS:
                documented[(method.upper(), norm("/api/v1" + path))] = op

    implemented = {}
    for route in routes:
        uri = "/" + route["uri"].lstrip("/")
        if not uri.startswith("/api/v1"):
            continue
        for method in route["method"].split("|"):
            if method == "HEAD":
                continue
            implemented[(method, norm(uri))] = route

    errors = []
    for key in sorted(set(implemented) - set(documented)):
        errors.append(f"route not in openapi.yaml: {key[0]} {key[1]}")
    for key in sorted(set(documented) - set(implemented)):
        errors.append(f"openapi.yaml documents a missing route: {key[0]} {key[1]}")

    default_security = spec.get("security", [])
    for (method, path), op in sorted(documented.items()):
        if (method, path) not in implemented:
            continue
        # route:list resolves aliases to classes (Authenticate:sanctum, ThrottleRequests:...).
        middleware = " ".join(implemented[(method, path)].get("middleware") or []).lower()
        authed = "auth:sanctum" in middleware or "authenticate:sanctum" in middleware
        gated = "member.verified" in middleware or "ensurememberverified" in middleware
        secured = op.get("security", default_security) != []
        codes = set(str(c) for c in op.get("responses", {}))
        if authed != secured:
            errors.append(f"{method} {path}: security does not match auth middleware ({middleware})")
        if authed and "401" not in codes:
            errors.append(f"{method} {path}: authenticated operation must document 401")
        if gated != (op.get("x-verification") == "required"):
            errors.append(f"{method} {path}: x-verification does not match member.verified")
        if gated and "403" not in codes:
            errors.append(f"{method} {path}: verification-gated operation must document 403")
        if "throttle" in middleware and "429" not in codes:
            errors.append(f"{method} {path}: rate-limited operation must document 429")
        if not any(c.startswith("2") for c in codes):
            errors.append(f"{method} {path}: no success response")

    for line in errors:
        print(f"::error title=openapi::{line}")
    print(f"documented={len(documented)} implemented={len(implemented)} errors={len(errors)}")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())

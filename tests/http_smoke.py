"""Exercise the organization and recruitment API over HTTP against an isolated database."""

import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import sys
import urllib.error
import urllib.request


class Session:
    def __init__(self, origin):
        self.origin = origin
        self.client = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )
        self.csrf = None

    def call(self, path, body=None, *, key=None, status=200, invalid_csrf=False):
        headers = {"Accept": "application/json"}
        data = None
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"
            if self.csrf and not invalid_csrf:
                headers["X-CSRF-Token"] = self.csrf
        if key:
            headers["Idempotency-Key"] = key
        try:
            response = self.client.open(
                urllib.request.Request(self.origin + path, data, headers), timeout=10
            )
        except urllib.error.HTTPError as error:
            response = error
        with response:
            payload = json.load(response)
            assert response.code == status, (path, response.code, payload)
        if "csrf_token" in payload:
            self.csrf = payload["csrf_token"]
        return payload


def run(origin, group):
    password = "Fixture-hcm-pass-99"
    admin = Session(origin)
    assert admin.call("/healthz") == {"ready": True}
    assert not admin.call("/api/session")["authenticated"]
    credentials = {"group": group, "email": "http-admin@example.test", "password": password}
    assert admin.call("/api/login", credentials, status=403, invalid_csrf=True)["error"] == "invalid_csrf_token"
    admin_id = admin.call("/api/login", credentials)["account_id"]

    company_id = admin.call(
        "/api/companies",
        {"code": "CN", "name": "Harbor Lab", "timezone": "Asia/Shanghai", "currency": "CNY"},
        status=201,
    )["company_id"]
    company = f"/api/companies/{company_id}"
    admin.call(company + "/roles", {"account_id": admin_id, "role": "hr"})
    department_id = admin.call(
        company + "/departments",
        {"code": "OPS", "name": "Operations", "effective_from": "2026-04-01"},
        key="ops",
        status=201,
    )["department_id"]
    assert admin.call(company + "/departments?on=2026-04-16")["departments"][0]["id"] == department_id
    position_id = admin.call(
        company + "/positions",
        {"code": "P", "title": "Coordinator", "department_id": department_id, "effective_from": "2026-04-01"},
        key="p",
        status=201,
    )["position_id"]
    position = f"{company}/positions/{position_id}"
    admin.call(position + "/budgets", {"starts_on": "2026-04-01", "ends_before": "2026-05-01", "capacity": 1}, key="cap")

    email = "http-recruiter@example.test"
    recruiter_id = admin.call("/api/accounts", {"email": email, "display_name": "Recruiter", "password": password}, status=201)["account_id"]
    admin.call(company + "/roles", {"account_id": recruiter_id, "role": "recruiter"})
    recruiter = Session(origin)
    recruiter.call("/api/session")
    recruiter.call("/api/login", {**credentials, "email": email})
    requisition = {"proposed_start": "2026-04-16", "justification": "Add a roster position"}
    recruiter.call(position + "/requisitions", requisition, key="req", status=404)
    admin.call(position + "/recruiters", {"account_id": recruiter_id})

    req = recruiter.call(position + "/requisitions", requisition, key="req")["requisition_id"]
    req_path = f"{position}/requisitions/{req}"
    admin.call(req_path + "/open", {"version": 1}, key="open")
    apply_path = req_path + "/apply"
    applicant = {"identity_ref": "HTTP-APPLICANT", "display_name": "Case Fixture"}
    submitted = recruiter.call(apply_path, applicant, key="apply")
    assert recruiter.call(apply_path, applicant, key="apply") == submitted
    assert recruiter.call(apply_path, {**applicant, "display_name": "Changed"}, key="apply", status=409)["error"] == "request_key_conflict"
    app = f"{position}/applications/{submitted['application_id']}"
    recruiter.call(app + "/interview", {"version": 1, "rating": 4, "notes": "Roster exercise"}, key="review")
    offer_id = recruiter.call(
        app + "/offer", {"version": 2, "effective_on": "2026-04-16", "monthly_minor": 900000}, key="draft"
    )["offer_id"]
    offer = f"{position}/offers/{offer_id}"
    recruiter.call(offer + "/sign", {"version": 1, "decision_ref": "SIGN-1"}, key="sign", status=404)
    admin.call(offer + "/sign", {"version": 1, "decision_ref": "SIGN-1"}, key="sign")
    assert admin.call(offer + "/accept", {"version": 2, "decision_ref": "ACCEPT-1"}, key="accept")["state"] == "accepted"
    assert [d["action"] for d in recruiter.call(app)["decisions"]] == ["sign", "accept"]
    recruiter.call("/api/logout", {})
    recruiter.call(app, status=401)
    print("HTTP session, CSRF, role isolation, recruitment and idempotency passed.")


if __name__ == "__main__":
    if not re.search(r"(?:^mysql:|;)dbname=hcm_test_[A-Za-z0-9_]+(?:;|$)", os.getenv("HCM_DSN", "")):
        sys.exit("Use a dedicated hcm_test_* database.")
    if len(sys.argv) != 2:
        sys.exit("Usage: python tests/http_smoke.py http://127.0.0.1:8080")
    group_code = "http-" + secrets.token_hex(5)
    php = ["php", "-d", "extension=pdo_mysql"] if sys.platform == "win32" else ["php"]
    subprocess.run(
        [*php, "bin/hcm.php", "create-group", group_code, "HTTP Fixture", "http-admin@example.test", "Admin"],
        input=b"Fixture-hcm-pass-99\n",
        check=True,
    )
    run(sys.argv[1].rstrip("/"), group_code)

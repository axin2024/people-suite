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
    requisition = {"proposed_start": "2026-04-01", "justification": "Add a roster position"}
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
        app + "/offer", {"version": 2, "effective_on": "2026-04-01", "monthly_minor": 900000}, key="draft"
    )["offer_id"]
    offer = f"{position}/offers/{offer_id}"
    recruiter.call(offer + "/sign", {"version": 1, "decision_ref": "SIGN-1"}, key="sign", status=404)
    admin.call(offer + "/sign", {"version": 1, "decision_ref": "SIGN-1"}, key="sign")
    assert admin.call(offer + "/accept", {"version": 2, "decision_ref": "ACCEPT-1"}, key="accept")["state"] == "accepted"
    assert [d["action"] for d in recruiter.call(app)["decisions"]] == ["sign", "accept"]
    intake = {"version": 3, "employee_code": "EMP-001", "contract_ref": "C-001", "contract_ends_before": "2026-05-01"}
    recruiter.call(offer + "/onboard", intake, key="onboard", status=404)
    employee = admin.call(offer + "/onboard", intake, key="onboard")
    assert admin.call(offer + "/onboard", intake, key="onboard") == employee
    employment_path = f"{company}/employments/{employee['employment_id']}"
    recruiter.call(employment_path, status=404)
    assert admin.call(employment_path)["contracts"][0]["reference"] == "C-001"
    assert admin.call(
        position + "/budgets",
        {"starts_on": "2026-05-01", "ends_before": "2026-06-01", "capacity": 0},
        key="over-occupied",
        status=409,
    )["error"] == "headcount_exhausted"

    finance = admin.call(
        company + "/departments",
        {"code": "FIN", "name": "Finance", "effective_from": "2026-04-01"},
        key="finance",
        status=201,
    )["department_id"]
    next_id = admin.call(
        company + "/positions",
        {"code": "P2", "title": "Finance coordinator", "department_id": finance, "effective_from": "2026-04-01"},
        key="p2",
        status=201,
    )["position_id"]
    cutover = {"assignment_version": 1, "position_id": next_id, "effective_from": "2026-04-16"}
    assert admin.call(employment_path + "/transfers", cutover, key="transfer", status=409)["error"] == "budget_not_found"
    next_position = f"{company}/positions/{next_id}"
    admin.call(next_position + "/budgets", {"starts_on": "2026-04-01", "ends_before": "2026-05-01", "capacity": 1}, key="cap-finance")
    transfer = admin.call(employment_path + "/transfers", cutover, key="transfer")
    assert transfer["previous"] == {"from": "2026-04-01", "until": "2026-04-16", "department_id": department_id}
    assert transfer["next"] == {"from": "2026-04-16", "until": None, "department_id": finance}
    assert admin.call(employment_path + "?on=2026-04-15")["department_id"] == department_id
    assert admin.call(employment_path + "?on=2026-04-16")["department_id"] == finance
    overlap = admin.call(employment_path + "/transfers", cutover, key="stale-transfer", status=409)
    assert overlap["error"] == "assignment_interval_conflict" and overlap["details"]["current_version"] == 2
    renewal = {"version": 1, "contract_ref": "C-002", "effective_from": "2026-05-01", "monthly_minor": 930000}
    assert admin.call(employment_path + "/contracts", renewal, key="renew")["version"] == 2
    assert [c["reference"] for c in admin.call(employment_path)["contracts"]] == ["C-001", "C-002"]

    target_id = admin.call(
        "/api/companies",
        {"code": "SOUTH", "name": "South Lab", "timezone": "Asia/Shanghai", "currency": "CNY"},
        status=201,
    )["company_id"]
    target = f"/api/companies/{target_id}"
    admin.call(target + "/roles", {"account_id": admin_id, "role": "hr"})
    receiving_email = "receiver@example.test"
    receiving_id = admin.call(
        "/api/accounts", {"email": receiving_email, "display_name": "Receiving HR", "password": password}, status=201,
    )["account_id"]
    admin.call(target + "/roles", {"account_id": receiving_id, "role": "hr"})
    receiving = Session(origin)
    receiving.call("/api/session")
    receiving.call("/api/login", {**credentials, "email": receiving_email})
    receiving.call(employment_path, status=404)
    target_dept = receiving.call(
        target + "/departments", {"code": "SVC", "name": "Service", "effective_from": "2026-04-01"},
        key="receiving-department", status=201,
    )["department_id"]
    target_position = receiving.call(
        target + "/positions",
        {"code": "S", "title": "Service coordinator", "department_id": target_dept, "effective_from": "2026-04-01"},
        key="receiving-position", status=201,
    )["position_id"]
    legal = admin.call(
        employment_path + "/legal-transfers",
        {
            "target_company_id": target_id, "target_position_id": target_position,
            "assignment_version": 2, "effective_from": "2026-04-25",
        },
        key="legal-transfer",
    )
    assert receiving.call(target + "/legal-transfers")["incoming"][0]["id"] == legal["transfer_id"]
    acceptance = f"{target}/legal-transfers/{legal['transfer_id']}/accept"
    decision = {"decision_ref": "RECEIPT-01", "contract_ref": "C-SOUTH-01", "monthly_minor": 930000}
    assert admin.call(acceptance, decision, key="self-accept", status=409)["error"] == "legal_transfer_requires_receiver"
    assert receiving.call(acceptance, decision, key="receive", status=409)["error"] == "budget_not_found"
    receiving.call(
        f"{target}/positions/{target_position}/budgets",
        {"starts_on": "2026-04-01", "ends_before": "2026-05-01", "capacity": 1},
        key="receiving-budget",
    )
    received = receiving.call(acceptance, decision, key="receive")
    assert receiving.call(acceptance, decision, key="receive") == received
    assert receiving.call(target + "/legal-transfers")["incoming"] == []
    received_path = f"{target}/employments/{received['employment_id']}"
    assert receiving.call(received_path)["employee"]["id"] == employee["employee_id"]
    assert receiving.call(received_path + "?on=2026-04-25")["department_id"] == target_dept
    receiving.call(received_path + "?on=2026-04-24", status=404)
    admin.call(employment_path + "?on=2026-04-25", status=404)
    assert admin.call(employment_path + "?on=2026-04-24")["department_id"] == finance

    recruiter.call("/api/logout", {})
    recruiter.call(app, status=401)
    print("HTTP recruitment, onboarding, contract history, department transfer and two-party legal transfer passed.")


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

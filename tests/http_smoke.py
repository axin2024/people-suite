"""Exercise the organization and recruitment API over HTTP against an isolated database."""

from concurrent.futures import ThreadPoolExecutor
import csv
import http.cookiejar
import io
import json
import os
import re
import secrets
import subprocess
import sys
import threading
import urllib.error
import urllib.request


class Session:
    def __init__(self, origin):
        self.origin = origin
        self.client = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )
        self.csrf = None
        self.account_id = None

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
            expected = (status,) if isinstance(status, int) else status
            assert response.code in expected, (path, response.code, payload)
        if "csrf_token" in payload:
            self.csrf = payload["csrf_token"]
        if path == "/api/login" and "account_id" in payload:
            self.account_id = payload["account_id"]
        return payload

    def download(self, path, filename):
        request = urllib.request.Request(self.origin + path, headers={"Accept": "text/csv"})
        with self.client.open(request, timeout=10) as response:
            assert response.code == 200
            assert response.headers.get_content_type() == "text/csv"
            assert response.headers.get_filename() == filename
            assert "no-store" in response.headers["Cache-Control"]
            stream = io.StringIO(response.read().decode("utf-8-sig"), newline="")
            return list(csv.DictReader(stream))


def create_login(admin, origin, company, credentials, name, role):
    email = f"{name}@example.test"
    account = admin.call(
        "/api/accounts", {"email": email, "display_name": name, "password": credentials["password"]}, status=201
    )["account_id"]
    admin.call(company + "/roles", {"account_id": account, "role": role})
    actor = Session(origin)
    actor.call("/api/session")
    actor.call("/api/login", {**credentials, "email": email})
    return account, actor


def attendance_and_leave(admin, origin, credentials, company, employment):
    employee_id, employee = create_login(admin, origin, company, credentials, "worker", "employee")
    manager_id, manager = create_login(admin, origin, company, credentials, "manager", "manager")
    _, outsider = create_login(admin, origin, company, credentials, "other-manager", "manager")
    admin.call(employment + "/account", {"account_id": employee_id}, key="employee-link")
    admin.call(
        employment + "/manager", {"account_id": manager_id, "effective_from": "2026-04-01"}, key="manager-link",
    )
    night = {
        "workday": "2026-04-15", "starts_at": "2026-04-15T22:00:00+08:00", "ends_at": "2026-04-16T06:00:00+08:00",
    }
    roster = employment + "/shifts"
    assert admin.call(roster, {**night, "workday": "2026-04-16"}, key="wrong-workday", status=422)["error"] == "shift_must_start_on_workday"
    shift = admin.call(roster, night, key="night", status=201)
    assert admin.call(roster, night, key="overlap", status=409)["error"] == "shift_overlap"
    path = f"{company}/attendance/shifts/{shift['shift_id']}"
    manager.call(path)
    outsider.call(path, status=404)
    employee.call(path)
    employee.call(path + "/resolution", {"state": "worked", "version": 1}, key="forbidden", status=404)
    in_punch = {"source": "GATE-01", "event_ref": "SHIFT-IN", "direction": "in", "punched_at": night["starts_at"]}
    out_punch = {"source": "GATE-01", "event_ref": "SHIFT-OUT", "direction": "out", "punched_at": night["ends_at"]}
    first = admin.call(path + "/punches", in_punch, key="first-in")
    assert admin.call(path + "/punches", {**in_punch, "punched_at": "2026-04-15T14:00:00Z"}, key="retry-in") == first
    assert admin.call(path + "/punches", {**in_punch, "direction": "out"}, key="mutated-in", status=409)["error"] == "punch_event_conflict"
    missing_out = manager.call(path)["attendance"]
    assert missing_out["state"] == "anomaly" and missing_out["evidence"]["anomalies"] == ["missing_out"]
    unresolved = {"state": "worked", "version": missing_out["version"], "decision_ref": "WORKED-01", "explanation": "Normal shift"}
    assert manager.call(path + "/resolution", unresolved, key="resolve-night", status=409)["error"] == "attendance_anomaly_unresolved"
    admin.call(path + "/punches", out_punch, key="first-out")
    ready = manager.call(path)["attendance"]
    assert ready["state"] == "ready" and ready["worked_minutes"] == 480
    approved = manager.call(path + "/resolution", {**unresolved, "version": ready["version"]}, key="resolve-night")
    assert approved["state"] == "worked" and approved["worked_minutes"] == 480
    assert admin.call(company + "/attendance/watermark")["watermark"] == approved["watermark"]
    assert len(manager.call(roster + "?workday=2026-04-15")["shifts"]) == 1
    assert manager.call(roster + "?workday=2026-04-16")["shifts"] == []

    imports = company + "/attendance/imports"
    roster_import = {
        "kind": "roster",
        "rows": [
            {"row_ref": "one", "employment_id": int(employment.rsplit("/", 1)[1]),
             "workday": "2026-04-23", "starts_at": "2026-04-23T09:00:00+08:00", "ends_at": "2026-04-23T17:00:00+08:00"},
            {"row_ref": "two", "employment_id": int(employment.rsplit("/", 1)[1]), **night},
        ],
    }
    import_id = admin.call(imports, roster_import, key="roster-import", status=201)["import_id"]
    assert admin.call(imports, roster_import, key="roster-import", status=201)["import_id"] == import_id
    import_path = f"{imports}/{import_id}"
    assert admin.call(import_path)["counts"] == {"pending": 2, "succeeded": 0, "failed": 0}
    assert admin.call(import_path + "/run", {"limit": 1})["counts"] == {"pending": 1, "succeeded": 1, "failed": 0}
    resumed = admin.call(import_path + "/run", {})
    assert resumed["counts"] == {"pending": 0, "succeeded": 1, "failed": 1}
    assert resumed["rows"][1]["outcome"]["error"] == "shift_overlap"
    retried = admin.call(import_path + "/run", {"retry_failed": True})
    assert [row["attempts"] for row in retried["rows"]] == [1, 2]
    imported_shift = resumed["rows"][0]["outcome"]["shift_id"]
    punches_import = {"kind": "punches", "rows": [
        {"row_ref": "in", "shift_id": imported_shift, "source": "GATE-01", "event_ref": "DAY-IN",
         "direction": "in", "punched_at": "2026-04-23T09:00:00+08:00"},
        {"row_ref": "out", "shift_id": imported_shift, "source": "GATE-01", "event_ref": "DAY-OUT",
         "direction": "out", "punched_at": "2026-04-23T17:00:00+08:00"},
    ]}
    punches_id = admin.call(imports, punches_import, key="punches-import", status=201)["import_id"]
    assert admin.call(f"{imports}/{punches_id}/run", {})["counts"]["succeeded"] == 2
    assert manager.call(f"{company}/attendance/shifts/{imported_shift}")["attendance"]["worked_minutes"] == 480

    leave = employment + "/leave"
    balance = {"kind": "annual", "year": 2026, "minutes": 480, "decision_ref": "INITIAL-ANNUAL"}
    assert admin.call(leave + "/grants", balance, key="grant-leave")["available_minutes"] == 480
    other_session = Session(origin)
    other_session.call("/api/session")
    other_session.call("/api/login", {**credentials, "email": "worker@example.test"})
    barrier = threading.Barrier(2)

    def try_leave(session, index):
        application = {
            "kind": "annual", "workday": f"2026-04-{20 + index}", "minutes": 480,
            "reason": "Personal leave", "reference": f"LEAVE-{index}",
        }
        barrier.wait(timeout=5)
        return session.call(leave, application, key=f"leave-{index}", status=(201, 409))

    with ThreadPoolExecutor(max_workers=2) as pool:
        attempts = [pool.submit(try_leave, actor, index) for index, actor in enumerate((employee, other_session))]
        results = [future.result(timeout=15) for future in attempts]
    pending, failure = sorted(results, key=lambda result: "error" in result)
    assert pending["state"] == "pending" and failure["error"] == "leave_balance_insufficient"
    request = f"{leave}/{pending['leave_id']}"
    assert employee.call(leave + "?workday=2026-04-20")["balances"][0]["available_minutes"] == 0
    outsider.call(request, status=404)
    employee.call(request + "/approve", {"version": 1, "decision_ref": "NO-SELF"}, key="self-approve", status=404)
    approved_leave = manager.call(request + "/approve", {"version": 1, "decision_ref": "APPROVED-01"}, key="approve-leave")
    assert approved_leave["used_change"] == 480 and approved_leave["reserved_change"] == -480
    employee.call(request + "/cancel", {"version": 2, "decision_ref": "NO-CANCEL"}, key="self-cancel", status=403)
    cancelled = manager.call(request + "/cancel", {"version": 2, "decision_ref": "CANCEL-01"}, key="cancel-leave")
    assert cancelled["used_change"] == -480
    denied = employee.call(
        leave, {"kind": "annual", "workday": "2026-04-22", "minutes": 480, "reason": "Extra request", "reference": "LEAVE-3"},
        key="leave-3", status=201,
    )
    manager.call(f"{leave}/{denied['leave_id']}/deny", {"version": 1, "decision_ref": "DENY-01"}, key="deny-leave")
    assert manager.call(leave + "?workday=2026-04-20")["balances"] == []
    assert employee.call(leave + "?workday=2026-04-20")["balances"][0]["available_minutes"] == 480
    assert [row["action"] for row in employee.call(request)["ledger"]] == ["reserve", "approve", "cancel"]
    return manager, employee, imported_shift


def payroll_trial(admin, origin, credentials, company, employment, department_id, finance, actors):
    manager, employee, imported_shift = actors
    maker_id, maker = create_login(admin, origin, company, credentials, "payroll-maker", "payroll_maker")
    _, reviewer = create_login(admin, origin, company, credentials, "payroll-reviewer", "payroll_reviewer")
    payroll = company + "/payroll"
    rule_input = {"rule_ref": "BASE-30", "title": "30 day demo", "proration_basis": "fixed_30"}
    reviewer.call(payroll + "/rules", rule_input, key="blocked-rule", status=404)
    rule = maker.call(payroll + "/rules", rule_input, key="demo-rule", status=201)["rule_id"]
    assert reviewer.call(payroll + "/rules")["rules"][0]["id"] == rule
    assert reviewer.call(payroll + f"/rules?after={rule}")["rules"] == []
    manager.call(payroll + "/rules", status=404)
    period = {"period_start": "2026-04-01"}
    run = maker.call(payroll + "/runs", period, key="april-run", status=201)
    run_path = f"{payroll}/runs/{run['run_id']}"
    assert reviewer.call(payroll + "/runs")["runs"][0]["state"] == "draft"
    manager.call(payroll + "/runs", status=404)
    manager.call(run_path, status=404)

    cut = maker.call(company + "/attendance/watermark")["watermark"]
    trial = {"version": run["version"], "rule_id": rule, "attendance_watermark": cut}
    first = maker.call(run_path + "/trial", trial, key="blocked-trial")
    missing = reviewer.call(run_path)["payslips"][0]
    assert first["blocked_count"] == 1 and missing["facts"]["blocking_reason"] == "attendance_certificate_missing"
    assert missing["net_minor"] is None
    blocked_submission = {"version": first["version"], "decision_ref": "SUBMIT-1", "notes": "Review fixture"}
    assert maker.call(run_path + "/submit", blocked_submission, key="submit-blocked", status=409)["error"] == "payroll_trial_blocked"

    certification = {
        "starts_on": "2026-04-01", "ends_before": "2026-05-01", "expected_shifts": 2,
        "decision_ref": "APRIL-ATTENDANCE", "notes": "Roster and cutoff checked against fixture calendar",
    }
    certify_path = employment + "/attendance-certifications"
    incomplete = admin.call(certify_path, certification, key="cert", status=409)
    assert incomplete["error"] == "attendance_period_incomplete" and incomplete["details"]["blocking_count"] == 1
    shift_path = f"{company}/attendance/shifts/{imported_shift}"
    arrived = manager.call(shift_path)["attendance"]
    manager.call(
        shift_path + "/resolution",
        {"version": arrived["version"], "state": "worked", "decision_ref": "WORKED-02", "explanation": "Recorded normal hours"},
        key="confirm-import",
    )
    certified = admin.call(certify_path, certification, key="cert", status=201)
    assert certified["worked_minutes"] == 960
    second_input = {**trial, "version": first["version"], "attendance_watermark": certified["watermark"]}
    second = maker.call(run_path + "/trial", second_input, key="valid-trial")
    assert maker.call(run_path + "/trial", second_input, key="valid-trial") == second
    assert second["eligible_count"] == 1 and second["blocked_count"] == 0 and second["gross_minor"] == 900000
    payslips = reviewer.call(run_path)["payslips"]
    payslip_path = f"{payroll}/payslips/{payslips[0]['id']}"
    employee.call(payslip_path, status=404)
    manager.call(payslip_path, status=404)
    detail = reviewer.call(payslip_path)
    assert detail["payslip"]["net_minor"] == 900000
    assert detail["payslip"]["attendance_certification_id"] == certified["certification_id"]
    assert [(row["department_id"], row["base_minor"], row["facts"]["days"]) for row in detail["segments"]] == [
        (department_id, 450000, 15), (finance, 450000, 15),
    ]
    cost_path = run_path + "/department-costs"
    manager.call(cost_path, status=404)
    employee.call(cost_path, status=404)
    preview = reviewer.call(cost_path)
    assert preview["run_state"] == "calculated" and preview["total_cost_minor"] == second["total_cost_minor"]
    assert [(row["department_id"], row["total_cost_minor"]) for row in preview["departments"]] == [
        (department_id, 450000), (finance, 450000),
    ]
    group_costs = "/api/payroll/department-costs?period_start=2026-04-01"
    assert reviewer.call(group_costs)["companies"] == []
    manager.call(group_costs, status=404)

    stale_shift = admin.call(
        employment + "/shifts",
        {"workday": "2026-04-24", "starts_at": "2026-04-24T09:00:00+08:00", "ends_at": "2026-04-24T17:00:00+08:00"},
        key="late-schedule", status=201,
    )
    stale_input = {**trial, "version": second["version"], "attendance_watermark": stale_shift["watermark"]}
    third = maker.call(run_path + "/trial", stale_input, key="stale-cert-trial")
    assert third["blocked_count"] == 1
    assert reviewer.call(run_path)["payslips"][0]["facts"]["blocking_reason"] == "attendance_certificate_stale"
    assert reviewer.call(payslip_path) == detail

    admin.call(
        f"{company}/attendance/shifts/{stale_shift['shift_id']}/resolution",
        {"version": 1, "state": "cancelled", "decision_ref": "REMOVE-ERRONEOUS-SHIFT", "explanation": "Duplicate roster source"},
        key="cancel-roster-row",
    )
    renewed = admin.call(certify_path, {**certification, "decision_ref": "APRIL-ATTENDANCE-2"}, key="recert", status=201)
    fourth = maker.call(
        run_path + "/trial", {**trial, "version": third["version"], "attendance_watermark": renewed["watermark"]},
        key="closing-trial",
    )
    assert fourth["gross_minor"] == 900000 and fourth["blocked_count"] == 0
    assert employee.call(payroll + "/my-payslips")["payslips"] == []
    author = {"version": fourth["version"], "decision_ref": "SUBMIT-2", "notes": "Confirm inputs"}
    reviewer.call(run_path + "/submit", author, key="reviewer-submit", status=404)
    submitted = maker.call(run_path + "/submit", author, key="submit")
    admin.call(company + "/roles", {"account_id": maker_id, "role": "payroll_reviewer"})
    close = {"version": submitted["version"], "decision_ref": "CLOSE-1", "notes": "Checked against attendance and contract"}
    assert maker.call(run_path + "/close", close, key="self-close", status=403)["error"] == "payroll_reviewer_must_differ"
    returned = reviewer.call(
        run_path + "/return", {**close, "decision_ref": "RETURN-1", "notes": "Check fixture reconciliation again"},
        key="return",
    )
    assert returned["state"] == "calculated"
    submitted = maker.call(run_path + "/submit", {**author, "version": returned["version"], "decision_ref": "SUBMIT-3"}, key="submit-again")
    close = {**close, "version": submitted["version"]}
    closed = reviewer.call(run_path + "/close", close, key="close")
    assert closed["state"] == "closed" and len(closed["digest"]) == 64
    assert reviewer.call(run_path + "/close", close, key="close") == closed
    assert reviewer.call(run_path + "/verify-closure") == {"run_id": run["run_id"], "digest": closed["digest"], "valid": True}
    assert employee.call(payroll + "/my-payslips")["payslips"] == []
    assert maker.call(run_path + "/trial", {**trial, "version": closed["version"]}, key="retry-closed", status=409)["error"] == "payroll_run_conflict"

    published = reviewer.call(
        run_path + "/publish", {"version": closed["version"], "decision_ref": "PUBLISH-1", "notes": "Publish approved salary"},
        key="publish",
    )
    assert published["state"] == "published" and published["digest"] == closed["digest"]
    final = employee.call(payroll + "/my-payslips")["payslips"]
    assert len(final) == 1 and final[0]["net_minor"] == 900000 and final[0]["digest"] == closed["digest"]
    employee_view = f"{payroll}/my-payslips/{final[0]['id']}"
    assert employee.call(employee_view)["payslip"]["net_minor"] == 900000
    archived = reviewer.call(group_costs)
    assert archived["companies"] == [reviewer.call(cost_path)]
    assert archived["companies"][0]["digest"] == closed["digest"]
    assert archived["totals_by_currency"] == [{
        "currency": "CNY", "company_count": 1, "base_cost_minor": 900000, "adjustment_minor": 0, "total_cost_minor": 900000,
    }]
    group_csv = reviewer.download(group_costs.replace("?", ".csv?"), "department-costs.csv")
    assert [(row["entry_type"], row["total_cost_minor"]) for row in group_csv] == [
        ("company", "900000"), ("department", "450000"), ("department", "450000"), ("currency", "900000"),
    ]
    salary_csv = employee.download(employee_view + ".csv", f"payslip-{final[0]['id']}.csv")
    assert salary_csv[0]["digest"] == closed["digest"] and salary_csv[0]["payable_minor"] == "900000"
    assert [row["gross_minor"] for row in salary_csv[1:]] == ["450000", "450000"]
    reviewer.download(payslip_path + ".csv", f"payslip-{payslips[0]['id']}.csv")
    manager.call(payslip_path + ".csv", status=404)
    manager.call(cost_path + ".csv", status=404)
    manager.call(employee_view, status=404)
    _, other_employee = create_login(admin, origin, company, credentials, "other-employee", "employee")
    assert other_employee.call(payroll + "/my-payslips")["payslips"] == []
    other_employee.call(employee_view, status=404)
    other_employee.call(employee_view + ".csv", status=404)
    return maker, reviewer, employee, other_employee, run_path, final[0]["id"], closed["digest"]


def retro_trial(company, original, department_id):
    maker, reviewer, employee, other_employee, april_path, original_id, original_digest = original
    payroll = company + "/payroll"
    employee_path = payroll + "/my-payslips"
    april_slip_path = f"{employee_path}/{original_id}"
    april_slip = employee.call(april_slip_path)
    rule = maker.call(
        payroll + "/rules", {"rule_ref": "ACTUAL-DAYS", "title": "Calendar days", "proration_basis": "actual_days"},
        key="calendar-rule", status=201,
    )["rule_id"]
    run = maker.call(payroll + "/runs", {"period_start": "2026-05-01"}, key="may-run", status=201)
    may_path = f"{payroll}/runs/{run['run_id']}"
    cut = maker.call(company + "/attendance/watermark")["watermark"]
    trial_input = {"version": run["version"], "rule_id": rule, "attendance_watermark": cut}
    empty = maker.call(may_path + "/trial", trial_input, key="may-empty-trial")
    assert empty["eligible_count"] == 0 and empty["payable_minor"] == 0

    adjustment = {
        "original_payslip_id": original_id, "original_line_no": 1, "decision_ref": "APRIL-RATE-CORRECTION",
        "corrected_monthly_minor": 960000, "notes": "Approved revision for first April assignment",
    }
    adjustment_path = may_path + "/adjustments"
    reviewer.call(adjustment_path, adjustment, key="reviewer-retro", status=404)
    maker.call(adjustment_path, {**adjustment, "original_line_no": 3}, key="wrong-line", status=404)
    change = maker.call(adjustment_path, adjustment, key="may-retro", status=201)
    saved, = reviewer.call(adjustment_path)["adjustments"]
    assert saved["id"] == change["id"] and saved["gross_delta_minor"] == 30000 and saved["notes"] == adjustment["notes"]
    assert reviewer.call(adjustment_path + f"?after={change['id']}")["adjustments"] == []
    assert change == maker.call(adjustment_path, adjustment, key="may-retro", status=201)
    assert change == maker.call(adjustment_path, adjustment, key="may-retro-retry", status=201)
    assert change["original_minor"] == 450000 and change["recalculated_minor"] == 480000
    assert change["original_gross_minor"] == 900000 and change["recalculated_gross_minor"] == 930000
    assert change["payable_delta_minor"] == 30000 and change["department_id"] == department_id
    assert maker.call(
        adjustment_path, {**adjustment, "corrected_monthly_minor": 970000}, key="may-retro-conflict", status=409,
    )["error"] == "payroll_adjustment_decision_conflict"
    assert maker.call(
        adjustment_path, {**adjustment, "decision_ref": "STALE-REVISION"}, key="may-retro-baseline", status=409,
    )["error"] == "payroll_adjustment_baseline_changed"

    review_input = {"version": empty["version"], "decision_ref": "MAY-SEND", "notes": "Check supplementary payroll"}
    assert maker.call(
        may_path + "/submit", review_input, key="may-submit", status=409,
    )["error"] == "payroll_adjustments_require_new_trial"
    trial = maker.call(may_path + "/trial", {**trial_input, "version": empty["version"]}, key="may-ready-trial")
    assert trial["eligible_count"] == 1 and trial["gross_minor"] == 0 and trial["blocked_count"] == 0
    assert trial["gross_adjustment_minor"] == trial["payable_minor"] == trial["total_cost_minor"] == 30000
    summary = reviewer.call(may_path)
    assert summary["trial"]["payable_minor"] == 30000 and len(summary["payslips"]) == 1
    new_id = summary["payslips"][0]["id"]
    may_slip_path = f"{employee_path}/{new_id}"
    employee.call(may_slip_path, status=404)
    submitted = maker.call(may_path + "/submit", {**review_input, "version": trial["version"]}, key="may-submit")
    closed = reviewer.call(may_path + "/close", {**review_input, "version": submitted["version"], "decision_ref": "MAY-CLOSE"}, key="may-close")
    assert reviewer.call(may_path + "/verify-closure")["valid"]
    reviewer.call(may_path + "/publish", {**review_input, "version": closed["version"], "decision_ref": "MAY-PUBLISH"}, key="may-publish")
    assert change == maker.call(adjustment_path, adjustment, key="may-retro-after-close", status=201)
    assert employee.call(april_slip_path) == april_slip
    assert reviewer.call(april_path + "/verify-closure")["digest"] == original_digest
    assert reviewer.call(april_path + "/verify-closure")["valid"]
    other_employee.call(may_slip_path, status=404)
    assert [slip["payable_minor"] for slip in employee.call(employee_path)["payslips"]] == [900000, 30000]
    released = employee.call(may_slip_path)
    assert released["payslip"]["payable_minor"] == 30000 and released["segments"] == []
    line, = released["adjustments"]
    assert line["id"] == change["id"] and line["original_payslip_id"] == original_id
    assert line["department_id"] == department_id and line["payable_delta_minor"] == 30000
    assert "recorded_by" not in line and "notes" not in line["facts"]
    may_cost = reviewer.call(may_path + "/department-costs")
    entry, = may_cost["departments"]
    assert entry["department_id"] == department_id and entry["adjustment_count"] == 1
    assert may_cost["base_cost_minor"] == 0 and may_cost["total_cost_minor"] == entry["adjustment_minor"] == 30000
    adjustment_csv = reviewer.download(may_path + "/department-costs.csv", "department-costs.csv")
    assert adjustment_csv[1]["department_id"] == str(department_id) and adjustment_csv[1]["adjustment_minor"] == "30000"
    salary_csv = employee.download(may_slip_path + ".csv", f"payslip-{new_id}.csv")
    assert salary_csv[1]["entry_type"] == "adjustment" and salary_csv[1]["decision_ref"] == adjustment["decision_ref"]
    assert salary_csv[1]["original_payslip_id"] == str(original_id) and salary_csv[1]["payable_minor"] == "30000"
    april_cost = reviewer.call(april_path + "/department-costs")
    assert april_cost["total_cost_minor"] == 900000 and april_cost["adjustment_minor"] == 0


def payslip_inquiries(company, original, manager, receiving, target):
    _, reviewer, employee, other_employee, april_path, payslip_id, digest = original
    payroll = company + "/payroll"
    payslip = f"{payroll}/my-payslips/{payslip_id}"
    path = payslip + "/inquiries"
    before = employee.call(payslip)
    question = {"reference": "APRIL-SPLIT", "category": "base_pay", "question": "=1+1\nWhy does April show two lines?"}
    assert employee.call(path)["inquiries"] == []
    other_employee.call(path, question, key="other-question", status=404)
    inquiry = employee.call(path, question, key="april-question", status=201)
    assert employee.call(path, question, key="april-question", status=201) == inquiry
    assert employee.call(path, question, key="april-question-retry", status=201) == inquiry
    assert employee.call(path, {**question, "question": "Changed"}, key="conflicting-question", status=409)["error"] == "inquiry_reference_conflict"
    assert inquiry["state"] == "open" and inquiry["version"] == 1
    inquiry_path = f"{payroll}/inquiries/{inquiry['inquiry_id']}"
    manager.call(payroll + "/inquiries", status=404)
    assert receiving.call(target + "/payroll/inquiries")["inquiries"] == []
    other_employee.call(path, status=404)
    other_employee.call(inquiry_path + "/acknowledge", {"version": 1}, key="other-ack", status=404)
    employee.call(inquiry_path + "/acknowledge", {"version": 1}, key="early-ack", status=409)
    pending, = reviewer.call(payroll + "/inquiries?state=open")["inquiries"]
    assert pending["id"] == inquiry["inquiry_id"] and pending["question"] == question["question"]
    assert reviewer.call(inquiry_path) == pending
    manager.call(inquiry_path, status=404)
    receiving.call(target + f"/payroll/inquiries/{inquiry['inquiry_id']}", status=404)
    response = {"version": 1, "decision_ref": "APRIL-RECONCILED", "answer": "The appointment changed on April 16. Each department covers 15 days."}
    foreign_path = target + f"/payroll/inquiries/{inquiry['inquiry_id']}/answer"
    assert receiving.call(foreign_path, response, key="foreign-inquiry", status=404)["error"] == "payslip_inquiry_not_found"
    manager.call(inquiry_path + "/answer", response, key="manager-answer", status=404)
    answer = reviewer.call(inquiry_path + "/answer", response, key="review-answer")
    assert reviewer.call(inquiry_path + "/answer", response, key="review-answer") == answer
    assert reviewer.call(inquiry_path + "/answer", response, key="different-answer", status=409)["error"] == "payslip_inquiry_conflict"
    detail, = employee.call(path)["inquiries"]
    assert detail["answer"] == response["answer"] and detail["version"] == 2
    assert "answered_by" not in detail
    acknowledgement = {"version": answer["version"]}
    closed = employee.call(inquiry_path + "/acknowledge", acknowledgement, key="acknowledged")
    assert employee.call(inquiry_path + "/acknowledge", acknowledgement, key="acknowledged") == closed
    assert closed["state"] == "acknowledged" and closed["version"] == 3
    assert reviewer.call(payroll + "/inquiries?state=open")["inquiries"] == []
    assert reviewer.call(payroll + "/inquiries?state=acknowledged")["inquiries"][0]["acknowledged_at"]
    review_csv, = reviewer.download(payroll + "/inquiries.csv?state=acknowledged", "payslip-inquiries.csv")
    assert review_csv["state"] == "acknowledged" and review_csv["question"] == "'" + question["question"]
    employee_csv, = employee.download(path + ".csv", "payslip-inquiries.csv")
    assert employee_csv["answer"] == response["answer"] and "answered_by" not in employee_csv
    manager.call(payroll + "/inquiries.csv", status=404)
    other_employee.call(path + ".csv", status=404)
    assert employee.call(payslip) == before
    verified = reviewer.call(april_path + "/verify-closure")
    assert verified["valid"] and verified["digest"] == digest


def performance_cycle(admin, origin, company, credentials, employment_path, payroll_actors, attendance_actors):
    manager, employee, _ = attendance_actors
    _, payroll_reviewer, _, other_employee, _, _, _ = payroll_actors
    successor_id, successor = create_login(admin, origin, company, credentials, "successor-manager", "manager")
    cycles = company + "/performance/cycles"
    spec = {"reference": "APRIL-REVIEW", "title": "April operations goals", "starts_on": "2026-04-01", "ends_before": "2026-05-01"}
    created = admin.call(cycles, spec, key="april-performance", status=201)
    assert admin.call(cycles, spec, key="april-performance", status=201) == created
    path = f"{cycles}/{created['cycle_id']}"
    assert employee.call(cycles)["cycles"] == []
    employee.call(path, status=404)
    payroll_reviewer.call(cycles, status=404)
    membership = {"employment_id": int(employment_path.rsplit("/", 1)[1]), "scope_on": "2026-04-16"}
    bad_day = {**membership, "scope_on": "2026-05-01"}
    assert admin.call(path + "/participants", bad_day, key="wrong-scope-day", status=422)["error"] == "performance_scope_date_outside_cycle"
    enrolled = admin.call(path + "/participants", membership, key="review-enrollment", status=201)
    assert admin.call(path + "/participants", membership, key="review-enrollment", status=201) == enrolled
    assert [cycle["id"] for cycle in employee.call(cycles)["cycles"]] == [created["cycle_id"]]
    assert other_employee.call(cycles)["cycles"] == []
    assert len(manager.call(path)["participants"]) == 1
    person = f"{path}/participants/{enrolled['participant_id']}"
    other_employee.call(person, status=404)
    successor.call(person, status=404)
    goals = [
        {"code": "SERVICE", "title": "Service quality", "target": "Acknowledge 95% of work orders in the SLA", "weight_bps": 6000},
        {"code": "TRAINING", "title": "Skills transfer", "target": "Complete two training sessions", "weight_bps": 4000},
    ]
    phase = {"version": created["version"], "decision_ref": "START-APRIL-EVALUATION"}
    blocked = admin.call(path + "/advance", phase, key="evaluate-unready", status=409)
    assert blocked["error"] == "performance_cycle_incomplete" and blocked["details"]["pending"] == 1
    invalid = employee.call(person + "/goals", {"version": 1, "goals": goals[:1]}, key="bad-weights", status=422)
    assert invalid["error"] == "performance_goal_weights_must_total_10000"
    goals_update = {"version": 1, "goals": goals}
    saved = employee.call(person + "/goals", goals_update, key="employee-goals")
    assert employee.call(person + "/goals", goals_update, key="employee-goals") == saved
    assert manager.call(person)["participant"]["goals"] == goals
    assert manager.call(person + "/goals", goals_update, key="stale-goals", status=409)["error"] == "performance_participant_conflict"
    confirmed = manager.call(person + "/confirm-goals", {"version": saved["version"], "decision_ref": "APRIL-CONFIRM-GOALS"}, key="confirm-goals")
    locked = employee.call(person + "/goals", {**goals_update, "version": confirmed["version"]}, key="locked-goals", status=409)
    assert locked["error"] == "performance_goals_already_confirmed"
    evaluating = admin.call(path + "/advance", phase, key="begin-evaluation")
    assert evaluating["state"] == "evaluation"
    self_evaluation = {"version": confirmed["version"], "assessment": "Completed both training sessions and logged work-order evidence."}
    reflected = employee.call(person + "/self-assessment", self_evaluation, key="self-evaluation")
    assert manager.call(person)["participant"]["self_assessment"] == self_evaluation["assessment"]
    evaluation = {
        "version": reflected["version"], "decision_ref": "APRIL-MANAGER-EVALUATION", "feedback": "Both goals met the documented expectations.",
        "goal_scores": [{"code": "SERVICE", "score": 90}, {"code": "TRAINING", "score": 81}],
    }
    employee.call(person + "/evaluation", evaluation, key="employee-cannot-rate", status=404)
    successor.call(person + "/evaluation", evaluation, key="peer-cannot-rate", status=404)
    score_gap = manager.call(person + "/evaluation", {**evaluation, "goal_scores": evaluation["goal_scores"][:1]}, key="score-missing", status=422)
    assert score_gap["error"] == "performance_goal_scores_must_match"
    submitted = manager.call(person + "/evaluation", evaluation, key="manager-score", status=201)
    assert submitted["score"] == 86 and submitted["revision"] == 1
    assert manager.call(person + "/evaluation", evaluation, key="manager-score", status=201) == submitted
    assert employee.call(person)["revisions"] == []
    assert manager.call(person)["revisions"][0]["goal_scores"] == evaluation["goal_scores"]
    assert employee.call(person + "/self-assessment", {**self_evaluation, "version": submitted["version"]}, key="review-already-sent", status=409)["error"] == "performance_evaluation_already_submitted"

    calibrating = admin.call(path + "/advance", {"version": evaluating["version"], "decision_ref": "APRIL-BEGIN-CALIBRATION"}, key="begin-calibration")
    assert calibrating["state"] == "calibration"
    close = {"version": calibrating["version"], "decision_ref": "APRIL-RELEASE-RATINGS"}
    assert admin.call(path + "/advance", close, key="unresolved-calibrations", status=409)["details"]["pending"] == 1
    decision = {"version": submitted["version"], "decision_ref": "APRIL-CALIBRATED", "score": 88,
                "feedback": "The quality goal exceeded the team standard.", "rationale": "Calibration panel checked the support ledger."}
    manager.call(person + "/calibration", decision, key="manager-cannot-calibrate", status=404)
    calibrated = admin.call(person + "/calibration", decision, key="calibrate", status=201)
    assert calibrated["revision"] == 2 and admin.call(person)["revisions"][1]["rationale"] == decision["rationale"]
    assert len(manager.call(person)["revisions"]) == 1 and employee.call(person)["revisions"] == []
    locked_cycle = admin.call(path + "/advance", close, key="release-ratings")
    assert locked_cycle["state"] == "finalized" and employee.call(path)["cycle"]["finalized_at"]
    published, = employee.call(person)["revisions"]
    assert published["score"] == 88 and published["feedback"] == decision["feedback"]
    assert "rationale" not in published and "recorded_by" not in published

    amendment = {**decision, "version": calibrated["version"], "score": 89, "decision_ref": "APRIL-AMENDMENT",
                 "feedback": "Training evidence was updated on review.", "rationale": "The panel accepted the attendance receipt."}
    corrected = admin.call(person + "/amendment", amendment, key="amend-rating", status=201)
    assert corrected["revision"] == 3 and admin.call(person + "/amendment", amendment, key="amend-rating", status=201) == corrected
    assert [row["score"] for row in admin.call(person)["revisions"]] == [86, 88, 89]
    assert employee.call(person)["revisions"][0] == published
    assert employee.call(person)["revisions"][-1]["score"] == 89
    stale = admin.call(person + "/amendment", amendment, key="stale-amendment", status=409)
    assert stale["error"] == "performance_participant_conflict" and stale["details"]["version"] == corrected["version"]

    admin.call(employment_path + "/manager", {"account_id": successor_id, "effective_from": "2026-04-20"}, key="manager-successor")
    later = admin.call(cycles, {**spec, "reference": "LATER-REVIEW"}, key="later-performance", status=201)
    later_path = f"{cycles}/{later['cycle_id']}"
    admin.call(later_path + "/participants", {**membership, "scope_on": "2026-04-21"}, key="later-enrollment", status=201)
    assert [cycle["id"] for cycle in manager.call(cycles)["cycles"]] == [created["cycle_id"]]
    assert [cycle["id"] for cycle in successor.call(cycles)["cycles"]] == [later["cycle_id"]]
    manager.call(later_path, status=404)
    successor.call(person, status=404)
    assert manager.call(person)["revisions"][-1]["score"] == 89
    return person, later_path, successor


def employee_services(admin, company, employment, payroll_actors, manager, source_department):
    _, _, employee, other_employee, _, original_payslip, _ = payroll_actors
    base = company + "/employee"
    profile_path = base + "/me?on=2026-04-05"
    initial = employee.call(profile_path)
    assert initial["employments"][0]["employment_id"] == employment
    assert initial["employments"][0]["department_id"] == source_department
    old_name = initial["employee"]["display_name"]
    manager.call(profile_path, status=404)
    other_employee.call(profile_path, status=404)
    initial_slip = employee.call(f"{company}/payroll/my-payslips/{original_payslip}")
    routes = base + "/requests"
    change = {"employment_id": employment, "kind": "name_correction", "reference": "CHECK-NAME", "details": "Update preferred workforce display name",
              "requested_name": "Avery Chen"}
    other_employee.call(routes, change, key="wrong-identity", status=404)
    submitted = employee.call(routes, change, key="name-check", status=201)
    assert employee.call(routes, change, key="name-check", status=201) == submitted
    assert employee.call(routes, change, key="name-check-replay", status=201) == submitted
    conflict = employee.call(routes, {**change, "requested_name": "Different display name"}, key="name-check-conflict", status=409)
    assert conflict["error"] == "employee_request_reference_conflict"
    assert [item["id"] for item in admin.call(base + "/review-queue?state=pending")["requests"]] == [submitted["request_id"]]
    employee.call(base + "/review-queue", status=404)
    path = f"{routes}/{submitted['request_id']}"
    assert employee.call(path)["outcome"] is None and employee.call(profile_path) == initial
    other_employee.call(path, status=404)
    manager.call(path, status=404)
    decision = {"version": 1, "decision_ref": "CONFIRM-NAME", "response": "Name change reviewed and recorded."}
    employee.call(path + "/approve", decision, key="self-approval", status=404)
    approval = admin.call(path + "/approve", decision, key="approve-name")
    assert admin.call(path + "/approve", decision, key="approve-name") == approval
    assert admin.call(path + "/approve", decision, key="stale-approval", status=409)["error"] == "employee_request_conflict"
    assert employee.call(path)["outcome"] == {"previous_name": old_name, "display_name": change["requested_name"]}
    assert employee.call(profile_path)["employee"]["display_name"] == change["requested_name"]
    assert employee.call(f"{company}/payroll/my-payslips/{original_payslip}") == initial_slip

    certificate = {"employment_id": employment, "kind": "employment_certificate", "reference": "PROOF-OF-SERVICE", "details": "Employment verification request"}
    issued = employee.call(routes, certificate, key="request-certificate", status=201)
    issued_path = f"{routes}/{issued['request_id']}"
    admin.call(issued_path + "/approve", {**decision, "decision_ref": "CERTIFICATE-ISSUED"}, key="issue-certificate")
    proof = employee.call(issued_path)["outcome"]
    assert proof["employee_name"] == change["requested_name"] and proof["starts_on"] == "2026-04-01"
    assert proof["employer"] and proof["issued_on"]
    duplicate = employee.call(routes, {**certificate, "reference": "WITHDRAW-REQUEST"}, key="duplicate-certificate", status=201)
    withdrawn = f"{routes}/{duplicate['request_id']}/withdraw"
    other_employee.call(withdrawn, decision, key="withdraw-other", status=404)
    assert employee.call(withdrawn, decision, key="withdraw-own")["state"] == "withdrawn"
    assert [row["state"] for row in employee.call(routes)["requests"]] == ["approved", "approved", "withdrawn"]
    assert other_employee.call(routes)["requests"] == []
    return issued_path, certificate


def publish_exit_payroll(admin, maker, reviewer, company, employment_path, rule, month, next_month, expected_shifts, expected_gross):
    cert = admin.call(employment_path + "/attendance-certifications", {
        "starts_on": month, "ends_before": next_month, "expected_shifts": expected_shifts,
        "decision_ref": f"EXIT-CERT-{month}", "notes": "Attendance reconciled for this payroll period",
    }, key=f"certify-{month}", status=201)
    payroll = company + "/payroll"
    run = maker.call(payroll + "/runs", {"period_start": month}, key=f"run-{month}", status=201)
    path = f"{payroll}/runs/{run['run_id']}"
    trial = maker.call(path + "/trial", {"version": run["version"], "rule_id": rule, "attendance_watermark": cert["watermark"]}, key=f"trial-{month}")
    assert trial["gross_minor"] == expected_gross and trial["blocked_count"] == 0
    base = {"version": trial["version"], "decision_ref": f"SUBMIT-{month}", "notes": "Commit final payroll evidence"}
    submitted = maker.call(path + "/submit", base, key=f"submit-{month}")
    closed = reviewer.call(path + "/close", {**base, "version": submitted["version"], "decision_ref": f"CLOSE-{month}"}, key=f"close-{month}")
    reviewer.call(path + "/publish", {**base, "version": closed["version"], "decision_ref": f"PUBLISH-{month}"}, key=f"publish-{month}")
    assert reviewer.call(path + "/verify-closure")["valid"]
    payslip, = reviewer.call(path)["payslips"]
    return path, payslip["id"]


def departure(admin, origin, credentials, company, worker, employment, source_company):
    old_id, old_manager = create_login(admin, origin, company, credentials, "handover-former-manager", "manager")
    new_id, manager = create_login(admin, origin, company, credentials, "handover-current-manager", "manager")
    _, maker = create_login(admin, origin, company, credentials, "handover-payroll-maker", "payroll_maker")
    _, reviewer = create_login(admin, origin, company, credentials, "handover-payroll-reviewer", "payroll_reviewer")
    _, outsider = create_login(admin, origin, company, credentials, "handover-other-worker", "employee")
    for role in ("employee", "payroll_maker"):
        admin.call(company + "/roles", {"account_id": worker.account_id, "role": role})

    path = f"{company}/employments/{employment}"
    admin.call(path + "/manager", {"account_id": old_id, "effective_from": "2026-04-25"}, key="former-reporting")
    admin.call(path + "/manager", {"account_id": new_id, "effective_from": "2026-05-10"}, key="current-reporting")
    shift_dates = ("2026-05-04", "2026-05-12", "2026-05-22")
    shift_paths = []
    for index, day in enumerate(shift_dates):
        roster = {"workday": day, "starts_at": f"{day}T09:00:00+08:00", "ends_at": f"{day}T17:00:00+08:00"}
        scheduled = admin.call(path + "/shifts", roster, key=f"exit-shift-{index}", status=201)
        shift_paths.append(f"{company}/attendance/shifts/{scheduled['shift_id']}")
        if index < 2:
            for action, timestamp in (("in", roster["starts_at"]), ("out", roster["ends_at"])):
                admin.call(shift_paths[-1] + "/punches", {"source": "EXIT-ACCESS", "event_ref": f"SHIFT-{index}-{action}", "direction": action,
                                                          "punched_at": timestamp}, key=f"exit-{index}-{action}")
            revision = admin.call(shift_paths[-1])["attendance"]["version"]
            admin.call(shift_paths[-1] + "/resolution", {"version": revision, "state": "worked", "decision_ref": f"EXIT-WORKED-{index}",
                                                        "explanation": "Clock records match scheduled hours"}, key=f"exit-worked-{index}")

    payroll = company + "/payroll"
    rule = maker.call(payroll + "/rules", {"rule_ref": "EXIT-RULE", "title": "Final payroll", "proration_basis": "actual_days"}, key="exit-rule", status=201)["rule_id"]
    april, old_slip = publish_exit_payroll(admin, maker, reviewer, company, path, rule, "2026-04-01", "2026-05-01", 0, 186000)
    old_slip_path = f"{payroll}/my-payslips/{old_slip}"
    inquiry_path = old_slip_path + "/inquiries"
    inquiry = {"reference": "EXIT-APRIL-CHECK", "category": "base_pay", "question": "Check the joining date used for April pay."}
    question = worker.call(inquiry_path, inquiry, key="ask-exit-salary", status=201)
    employee_path = company + "/employee/requests"
    leave = {"kind": "ANNUAL", "workday": "2026-05-13", "minutes": 480, "reference": "EXIT-LEAVE", "reason": "Appointment"}
    admin.call(path + "/leave/grants", {"kind": "ANNUAL", "year": 2026, "minutes": 480, "decision_ref": "EXIT-LEAVE-GRANT"}, key="exit-leave-grant")
    leave_id = worker.call(path + "/leave", leave, key="exit-leave", status=201)["leave_id"]
    cert = {"employment_id": employment, "kind": "employment_certificate", "reference": "EXIT-SERVICE-PROOF", "details": "Request a copy of employment dates"}
    request = worker.call(employee_path, cert, key="exit-service-proof", status=201)
    cert_path = f"{employee_path}/{request['request_id']}"
    cases = company + "/offboarding"
    start = {"employment_id": employment, "assignment_version": 1, "ends_before": "2026-05-16", "reference": "EXIT-CASE-1",
             "reason": "Voluntary departure", "equipment": [{"code": "LAPTOP-06", "label": "Work laptop 06"}]}
    paused = admin.call(cases, {**start, "reference": "EXIT-CASE-CANCEL"}, key="start-cancelled-case", status=201)
    cancelled = admin.call(f"{cases}/{paused['case_id']}/cancel", {"version": 1, "decision_ref": "EXIT-CANCELLED"}, key="cancel-provisional")
    assert cancelled["state"] == "cancelled"
    case = admin.call(cases, start, key="start-exit", status=201)
    assert admin.call(cases, start, key="start-exit", status=201) == case
    active = f"{cases}/{case['case_id']}"
    outsider.call(active, status=404)
    old_manager.call(active, status=404)
    assert [task["code"] for task in manager.call(active)["tasks"]] == ["HANDOVER"]
    assert [task["code"] for task in reviewer.call(active)["tasks"]] == ["PAYROLL"]
    assert len(worker.call(active)["tasks"]) == 5 and "reason" not in worker.call(active)["case"]
    assert {item["kind"] for item in admin.call(active)["blockers"]} == {"task", "employee_request", "leave", "shift", "payslip_inquiry"}
    for url, body, key in (
        (employee_path, {**cert, "reference": "FROZEN-CASE"}, "frozen-request"),
        (path + "/leave", {**leave, "reference": "FROZEN-LEAVE"}, "frozen-leave"),
        (inquiry_path, {**inquiry, "reference": "FROZEN-INQUIRY"}, "frozen-inquiry"),
    ):
        assert worker.call(url, body, key=key, status=409)["error"] == "offboarding_new_work_frozen"
    schedule = {"workday": "2026-05-23", "starts_at": "2026-05-23T09:00:00+08:00", "ends_at": "2026-05-23T17:00:00+08:00"}
    assert admin.call(path + "/shifts", schedule, key="frozen-schedule", status=409)["error"] == "offboarding_new_work_frozen"
    assert admin.call(company + "/roles", {"account_id": worker.account_id, "role": "manager"}, status=409)["error"] == "offboarding_role_grants_locked"
    admin.call(cert_path + "/approve", {"version": 1, "decision_ref": "EXIT-CERT-APPROVED", "response": "Employment date record supplied"}, key="approve-exit-proof")
    worker.call(f"{path}/leave/{leave_id}/cancel", {"version": 1, "decision_ref": "EXIT-LEAVE-WITHDRAWN"}, key="withdraw-exit-leave")
    admin.call(shift_paths[-1] + "/resolution", {"version": 1, "state": "cancelled", "decision_ref": "EXIT-SHIFT-DROPPED",
                                                  "explanation": "Employment ends before this shift"}, key="cancel-future-shift")
    question_path = f"{payroll}/inquiries/{question['inquiry_id']}"
    answered = reviewer.call(question_path + "/answer", {"version": 1, "decision_ref": "EXIT-APRIL-ANSWER", "answer": "April covers April 25 to 30."}, key="exit-payroll-answer")
    worker.call(question_path + "/acknowledge", {"version": answered["version"]}, key="ack-exit-salary")

    version = case["version"]
    for code, person in (("HANDOVER", manager), ("ACCESS", admin), ("EQUIPMENT", admin), ("PAYROLL", reviewer)):
        decision = {"version": version, "decision_ref": f"EXIT-{code}", "evidence": "Checklist evidence verified"}
        result = person.call(f"{active}/tasks/{code}/confirm", decision, key=f"exit-task-{code}")
        assert person.call(f"{active}/tasks/{code}/confirm", decision, key=f"exit-task-{code}") == result
        version = result["version"]
    completion = {"version": version, "decision_ref": "EXIT-COMPLETE"}
    blocked = admin.call(active + "/complete", completion, key="complete-exit", status=409)
    assert blocked["error"] == "offboarding_clearance_incomplete"
    assert blocked["details"]["blockers"] == [{"kind": "task", "code": "LAPTOP-06", "label": "Work laptop 06", "owner_role": "hr"}]
    assert admin.call(path + "?on=2026-05-16")["effective_from"] == "2026-04-25"
    device = {"version": version, "decision_ref": "EXIT-LAPTOP-06", "evidence": "Registered device was returned"}
    manager.call(active + "/tasks/LAPTOP-06/confirm", device, key="forbidden-asset", status=404)
    cleared = admin.call(active + "/tasks/LAPTOP-06/confirm", device, key="returned-laptop")
    completion["version"] = cleared["version"]
    finished = admin.call(active + "/complete", completion, key="complete-exit")
    assert finished["state"] == "completed" and finished["revoked_role_count"] == 2
    assert admin.call(active + "/complete", completion, key="complete-exit") == finished
    assert admin.call(path)["employment"]["ends_before"] == "2026-05-16"
    admin.call(path + "?on=2026-05-16", status=404)
    assert old_manager.call(shift_paths[0])["shift"]["workday"] == shift_dates[0]
    old_manager.call(shift_paths[1], status=404)
    old_manager.call(shift_paths[2], status=404)
    assert manager.call(shift_paths[1])["shift"]["workday"] == shift_dates[1]
    manager.call(shift_paths[2], status=404)

    assert worker.call(active)["case"]["state"] == "completed"
    assert worker.call(cert_path)["outcome"]["starts_on"] == "2026-04-25"
    assert len(worker.call(company + "/employee/requests")["requests"]) == 1
    assert worker.call(inquiry_path)["inquiries"][0]["state"] == "acknowledged"
    assert {item["id"] for item in worker.call("/api/companies")["companies"]} == {int(company.rsplit("/", 1)[1]), int(source_company.rsplit("/", 1)[1])}
    worker.call(april, status=404)
    worker.call(employee_path, cert, key="after-exit-request", status=404)
    worker.call(inquiry_path, inquiry, key="after-exit-inquiry", status=404)
    outsider.call(old_slip_path + ".csv", status=404)
    assert admin.call(company + "/roles", {"account_id": worker.account_id, "role": "employee"}, status=409)["error"] == "offboarding_role_grants_locked"

    _, final_slip = publish_exit_payroll(admin, maker, reviewer, company, path, rule, "2026-05-01", "2026-06-01", 2, 450000)
    historical = worker.call(payroll + "/my-payslips")["payslips"]
    assert [item["payable_minor"] for item in historical] == [186000, 450000]
    final_path = f"{payroll}/my-payslips/{final_slip}"
    last, = worker.call(final_path)["segments"]
    assert last["ends_before"] == "2026-05-16" and last["facts"]["days"] == 15
    assert worker.download(final_path + ".csv", f"payslip-{final_slip}.csv")[0]["payable_minor"] == "450000"
    outsider.call(final_path + ".csv", status=404)
    assert len(worker.call(source_company + "/payroll/my-payslips")["payslips"]) == 2
    return int(company.rsplit("/", 1)[1]), final_slip, worker.account_id


def verify_export_audit(group_id, archived_download):
    query = r"""
        require 'src/bootstrap.php';
        $db = PeopleSuite\Database::connect();
        echo json_encode($db->rows(
            'SELECT company_id, actor_id, object_id, action, facts FROM audit_events WHERE group_id=? AND action LIKE ? ORDER BY id',
            [(int) $argv[1], 'payroll.%_exported']
        ), JSON_THROW_ON_ERROR);
    """
    exports = json.loads(subprocess.check_output([*php, "-r", query, str(group_id)]))
    for event in exports:
        event["facts"] = json.loads(event["facts"])
    routes = {(event["action"], event["facts"]["view"]) for event in exports}
    assert routes == {
        ("payroll.payslip_exported", "employee"),
        ("payroll.payslip_exported", "payroll"),
        ("payroll.costs_exported", "group"),
        ("payroll.costs_exported", "run"),
        ("payroll.inquiries_exported", "employee"),
        ("payroll.inquiries_exported", "review_queue"),
    }
    company, slip, actor = archived_download
    final_events = [event for event in exports if event["action"] == "payroll.payslip_exported" and event["object_id"] == slip]
    assert final_events == [{"company_id": company, "actor_id": actor, "object_id": slip,
                             "action": "payroll.payslip_exported", "facts": {"view": "employee"}}]


def run(origin, group):
    password = "Fixture-hcm-pass-99"
    admin = Session(origin)
    assert admin.call("/healthz") == {"ready": True}
    with urllib.request.urlopen(origin, timeout=10) as home:
        assert home.headers.get_content_type() == "text/html"
        assert "frame-ancestors 'none'" in home.headers["Content-Security-Policy"]
        assert "登录人力资源工作台" in home.read().decode("utf-8")
    with urllib.request.urlopen(origin + "/workspace.js", timeout=10) as script:
        assert script.headers.get_content_type() == "text/javascript"
    with urllib.request.urlopen(origin + "/recruitment-workspace.js", timeout=10) as script:
        assert script.headers.get_content_type() == "text/javascript"
    assert not admin.call("/api/session")["authenticated"]
    admin.call("/api/workspace", status=401)
    credentials = {"group": group, "email": "http-admin@example.test", "password": password}
    assert admin.call("/api/login", credentials, status=403, invalid_csrf=True)["error"] == "invalid_csrf_token"
    login = admin.call("/api/login", credentials)
    admin_id = login["account_id"]

    company_id = admin.call(
        "/api/companies",
        {"code": "CN", "name": "Harbor Lab", "timezone": "Asia/Shanghai", "currency": "CNY"},
        status=201,
    )["company_id"]
    company = f"/api/companies/{company_id}"
    admin.call(company + "/roles", {"account_id": admin_id, "role": "hr"})
    workspace = admin.call("/api/workspace")
    assert workspace["account_id"] == admin_id and workspace["group_code"] == group and workspace["group_admin"]
    assert workspace["companies"][0]["id"] == company_id and workspace["companies"][0]["roles"] == ["hr"]
    assert admin.call(company + "/employments")["employments"] == []
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
    position_directory = company + "/positions?on=2026-04-16"
    headcount = position + "/headcount?on=2026-04-16"
    assert admin.call(position_directory)["positions"][0]["id"] == position_id
    assert admin.call(position_directory + f"&after={position_id}")["positions"] == []
    assert admin.call(company + "/positions?on=2026-03-31")["positions"] == []
    assert admin.call(headcount)["budget"] is None
    admin.call(position + "/budgets", {"starts_on": "2026-04-01", "ends_before": "2026-05-01", "capacity": 1}, key="cap")
    assert admin.call(headcount)["available"] == 1

    email = "http-recruiter@example.test"
    recruiter_id = admin.call("/api/accounts", {"email": email, "display_name": "Recruiter", "password": password}, status=201)["account_id"]
    admin.call(company + "/roles", {"account_id": recruiter_id, "role": "recruiter"})
    recruiter = Session(origin)
    recruiter.call("/api/session")
    recruiter.call("/api/login", {**credentials, "email": email})
    requisition = {"proposed_start": "2026-04-01", "justification": "Add a roster position"}
    assert recruiter.call(position_directory)["positions"] == []
    recruiter.call(position + "/requisitions", status=404)
    recruiter.call(position + "/requisitions", requisition, key="req", status=404)
    admin.call(position + "/recruiters", {"account_id": recruiter_id})
    assert [row["id"] for row in recruiter.call(position_directory)["positions"]] == [position_id]
    recruiter.call(headcount, status=404)

    req = recruiter.call(position + "/requisitions", requisition, key="req")["requisition_id"]
    req_path = f"{position}/requisitions/{req}"
    assert recruiter.call(position + "/requisitions")["requisitions"][0]["id"] == req
    assert recruiter.call(position + f"/requisitions?after={req}")["requisitions"] == []
    assert recruiter.call(req_path)["requisition"]["state"] == "pending"
    admin.call(req_path + "/open", {"version": 1}, key="open")
    apply_path = req_path + "/apply"
    applicant = {"identity_ref": "HTTP-APPLICANT", "display_name": "Case Fixture"}
    submitted = recruiter.call(apply_path, applicant, key="apply")
    applications = recruiter.call(req_path)["applications"]
    assert [(row["id"], row["display_name"]) for row in applications] == [(submitted["application_id"], "Case Fixture")]
    assert recruiter.call(req_path + f"?after={submitted['application_id']}")["applications"] == []
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
    dossier = recruiter.call(app)
    assert [d["action"] for d in dossier["decisions"]] == ["sign", "accept"]
    assert dossier["offers"][0]["monthly_minor"] == 900000
    assert admin.call(headcount)["occupied"] == 1 and admin.call(headcount)["available"] == 0
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
    assert [row["id"] for row in recruiter.call(position_directory)["positions"]] == [position_id]
    recruiter.call(next_position + "/requisitions", status=404)
    admin.call(next_position + f"/requisitions/{req}", status=404)
    admin.call(next_position + "/budgets", {"starts_on": "2026-04-01", "ends_before": "2026-05-01", "capacity": 1}, key="cap-finance")
    transfer = admin.call(employment_path + "/transfers", cutover, key="transfer")
    assert transfer["previous"] == {"from": "2026-04-01", "until": "2026-04-16", "department_id": department_id}
    assert transfer["next"] == {"from": "2026-04-16", "until": None, "department_id": finance}
    assert admin.call(employment_path + "?on=2026-04-15")["department_id"] == department_id
    assert admin.call(employment_path + "?on=2026-04-16")["department_id"] == finance
    assert admin.call(headcount)["occupied"] == 0
    assert admin.call(next_position + "/headcount?on=2026-04-16")["occupied"] == 1
    overlap = admin.call(employment_path + "/transfers", cutover, key="stale-transfer", status=409)
    assert overlap["error"] == "assignment_interval_conflict" and overlap["details"]["current_version"] == 2
    renewal = {"version": 1, "contract_ref": "C-002", "effective_from": "2026-05-01", "monthly_minor": 930000}
    assert admin.call(employment_path + "/contracts", renewal, key="renew")["version"] == 2
    assert [c["reference"] for c in admin.call(employment_path)["contracts"]] == ["C-001", "C-002"]
    actors = attendance_and_leave(admin, origin, credentials, company, employment_path)
    original_payroll = payroll_trial(admin, origin, credentials, company, employment_path, department_id, finance, actors)
    performance_cycle(admin, origin, company, credentials, employment_path, original_payroll, actors)
    certificate_path, certificate = employee_services(admin, company, employee["employment_id"], original_payroll, actors[0], department_id)

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
    employee_session = original_payroll[2]
    assert employee_session.call(certificate_path)["outcome"]["ends_before"] is None
    barred = employee_session.call(company + "/employee/requests", {**certificate, "reference": "AFTER-TRANSFER"}, key="old-employment-request", status=409)
    assert barred["error"] == "employee_request_requires_active_employment"
    retro_trial(company, original_payroll, department_id)
    admin.call(target + "/roles", {"account_id": receiving_id, "role": "payroll_reviewer"})
    payslip_inquiries(company, original_payroll, actors[0], receiving, target)
    group_costs = "/api/payroll/department-costs?period_start=2026-04-01"
    assert receiving.call(group_costs)["companies"] == []
    receiving.call(original_payroll[4] + "/department-costs", status=404)
    archived_download = departure(admin, origin, credentials, target, employee_session, received["employment_id"], company)
    verify_export_audit(login["group_id"], archived_download)
    archived_company, = [entry for entry in employee_session.call("/api/workspace")["companies"] if entry["id"] == target_id]
    assert archived_company["archive"] and archived_company["roles"] == []
    former_employment, = admin.call(target + "/employments")["employments"]
    assert former_employment["id"] == received["employment_id"] and former_employment["ends_before"] == "2026-05-16"
    employee_session.call(target + "/employments", status=404)

    recruiter.call("/api/logout", {})
    recruiter.call(app, status=401)
    print("HTTP recruitment, employment, attendance, leave, payroll, performance, offboarding and archived payslip flows passed.")


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

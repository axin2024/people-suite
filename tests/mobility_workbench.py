"""Read the synthetic workforce acceptance record through a real Chromium workbench."""

import argparse
import json
from pathlib import Path

from playwright.sync_api import expect, sync_playwright


def check(page, origin, record, screenshot):
    page.goto(origin)
    button = lambda label: page.get_by_role("button", name=label, exact=True)

    def login(email):
        page.get_by_label("集团代码").fill(record["group"])
        page.get_by_label("邮箱").fill(email)
        page.get_by_label("密码").fill("Synthetic-assertions-44")
        button("登录").click()
        expect(button("退出")).to_be_visible()

    login(record["source_manager"])
    button("内部调任").click()
    expect(page.get_by_role("cell", name="已撤回", exact=True)).to_be_visible()
    row = page.get_by_role("row").filter(has=page.get_by_role("cell", name="已生效", exact=True)).filter(has_text="发展专员")
    row.get_by_role("button", name="查看调任").click()
    expect(page.get_by_role("heading", name=f"调任单 #{record['effective']['move_id']}", exact=True)).to_be_visible()
    expect(page.get_by_text(record["handover"]["checklist"], exact=True)).to_be_visible()
    expect(page.get_by_text("确认重新录用", exact=True)).to_be_visible()
    expect(page.get_by_text("原部门已确认交接", exact=True)).to_be_visible()
    expect(page.locator("dl div").filter(has=page.get_by_text("生效任职记录", exact=True)).locator("dd")).to_have_text(str(record["effective"]["assignment_id"]))
    if screenshot:
        page.screenshot(path=screenshot, full_page=True)
    button("退出").click()
    login("finance@example.test")
    button("薪资账期").click()
    for month, closure in [("2027-06", record["june"][0]), ("2027-07", record["july"][0])]:
        row = page.get_by_role("row").filter(has_text=month)
        row.get_by_role("button", name="查看账期").click()
        expect(page.get_by_text(month + " 薪资账期", exact=True)).to_be_visible()
        expect(page.get_by_text(closure["digest"], exact=True)).to_be_visible()
        button("薪资账期").click()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("origin")
    parser.add_argument("evidence", type=Path)
    parser.add_argument("--chrome")
    parser.add_argument("--screenshot")
    args = parser.parse_args()
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=args.chrome)
        try:
            page = browser.new_page(viewport={"width": 1366, "height": 900})
            check(page, args.origin, json.loads(args.evidence.read_text(encoding="utf-8")), args.screenshot)
            print("Verified withdrawn and effective moves, both signatures, handover, assignment and two payroll closures.")
        finally:
            browser.close()

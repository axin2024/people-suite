import { attachment, badge, button, empty, facts, field, money, node, panel, reference, statuses, table, today } from "./workspace-ui.js";

const payrollRole = (company) => company.roles.some((role) => role === "payroll_maker" || role === "payroll_reviewer");
const canMake = (ctx) => ctx.company.roles.includes("payroll_maker");
const canReview = (ctx) => ctx.company.roles.includes("payroll_reviewer");
const ruleBases = { actual_days: "按当月自然日", fixed_30: "按 30 日" };
const reviewLabels = { submit: "送审", return: "退回", close: "封账", publish: "发布" };
const inquiryKinds = { base_pay: "基本工资", attendance: "考勤", withholding: "扣缴", adjustment: "补差", other: "其他" };
const reasons = {
    attendance_certificate_missing: "选定水位下没有本账期的考勤确认",
    attendance_certificate_stale: "考勤确认后有更新，请重新确认",
    payroll_contract_missing: "任职期间缺少合同覆盖",
    payroll_assignment_missing: "任职期间缺少岗位分段",
    payroll_assignment_overlap: "任职分段存在重叠",
};

export const payrollPages = [
    { id: "payroll-runs", label: "薪资账期", allow: payrollRole, load: payrollRuns },
    { id: "payroll-rules", label: "计薪规则", allow: payrollRole, load: payrollRules },
    { id: "payroll-inquiries", label: "工资核对", allow: (company) => company.roles.includes("payroll_reviewer"), load: payrollInquiries },
    { id: "payroll-costs", label: "集团薪资成本", allow: payrollRole, load: groupCosts },
];

const amount = (ctx, value) => money(ctx.company, value);

function numberField(label, name, options = {}) {
    return field(label, name, { type: "number", min: 0, ...options });
}

function monthField(company, value) {
    return field("薪资月份", "month", { type: "month", value: value ?? today(company).slice(0, 7), pattern: "[0-9]{4}-[0-9]{2}" });
}

function periodOf(value) {
    return value.slice(0, 7);
}

function noteField(label = "处理说明", name = "notes", limit = 600) {
    return field(label, name, { multiline: true, maxLength: limit, wide: true });
}

async function payrollRules(ctx) {
    const path = ctx.base + "/payroll/rules";
    const list = await ctx.list("计薪规则版本", path, "rules",
        ["编号", "规则", "版本", "计薪基准", "固定津贴", "每小时缺勤扣减", "扣缴基点", "扣缴免征额"],
        (row) => [row.id, `${row.rule_ref} · ${row.title}`, row.revision, ruleBases[row.proration_basis],
            amount(ctx, row.monthly_allowance_minor), amount(ctx, row.absence_hourly_minor),
            row.withholding_bps, amount(ctx, row.withholding_exempt_minor)]);
    if (!canMake(ctx)) return [list];
    const form = ctx.form([
        reference("规则代码", "rule_ref"),
        field("规则名称", "title", { maxLength: 160 }),
        field("计薪基准", "proration_basis", { choices: Object.entries(ruleBases) }),
        numberField("固定月津贴（最小货币单位）", "monthly_allowance_minor", { value: 0, max: 100000000000 }),
        numberField("每小时缺勤扣减（最小货币单位）", "absence_hourly_minor", { value: 0, max: 1000000000 }),
        numberField("扣缴比例（基点）", "withholding_bps", { value: 0, max: 10000 }),
        numberField("扣缴免征额（最小货币单位）", "withholding_exempt_minor", { value: 0, max: 100000000000 }),
    ], [["create", "创建规则版本"]], (_, body, key) => ctx.request(path, body, key),
    (result) => ctx.go(undefined, `计薪规则 ${result.rule_ref} 已创建，编号 ${result.rule_id}。`));
    return [list, panel("新建计薪规则", empty("扣缴参数由部署方审核。1 个基点等于 0.01%。"), form)];
}

async function payrollRuns(ctx, route) {
    const source = ctx.base + "/payroll/runs";
    if (!route) {
        const pages = [await ctx.list("账期与最新试算", source, "runs", ["账期", "状态", "版本", "试算批次", "应计人数", "阻断人数", "操作"],
            (run) => [periodOf(run.period_start), badge(run.state), run.version, run.trial_revision || "—", run.eligible_count,
                run.blocked_count, button("查看账期", () => ctx.go({ run: run.id }))])];
        if (canMake(ctx)) {
            const form = ctx.form([monthField(ctx.company)], [["create", "创建账期"]],
                (_, body, key) => ctx.request(source, { period_start: body.month + "-01" }, key),
                (result) => ctx.go({ run: result.run_id }, "薪资账期已创建。"));
            pages.push(panel("新建自然月账期", form));
        }
        return pages;
    }
    if (route.slip) return salaryDetail(ctx, route);
    if (route.costs) return runCosts(ctx, route.run);
    const path = `${source}/${route.run}`;
    const details = await ctx.request(path);
    if (!ctx.current()) return [];
    const { run, trial, review_events: reviewEvents, closure } = details;
    ctx.tools(button("返回账期列表", () => ctx.go()));
    const result = [panel(`${periodOf(run.period_start)} 薪资账期`, facts([
        ["状态", statuses[run.state]], ["账期编号", run.id], ["当前版本", run.version], ["试算批次", run.trial_revision || "—"],
        ["起始日", run.period_start], ["结束日（不含）", run.period_end],
    ]))];
    if (trial) {
        ctx.tools(button("查看部门成本", () => ctx.go({ run: run.id, costs: true })));
        result.push(panel("试算摘要", facts([
            ["计薪规则编号", trial.rule_id], ["考勤水位", trial.attendance_watermark], ["应计人数", trial.eligible_count],
            ["阻断人数", trial.blocked_count], ["正常应发", amount(ctx, trial.gross_minor)],
            ["净补差", amount(ctx, trial.adjustment_minor)], ["实付合计", amount(ctx, trial.payable_minor)],
            ["部门成本合计", amount(ctx, trial.total_cost_minor)],
        ])));
        result.push(await ctx.list("员工工资单", path, "payslips",
            ["工号", "人员", "状态", "阻断原因", "应发", "实付", "操作"],
            (row) => [row.employee_code, row.employee_name, badge(row.state), blockingReason(row.facts),
                amount(ctx, row.gross_minor), amount(ctx, row.payable_minor),
                button("查看试算单", () => ctx.go({ run: run.id, slip: row.id }))],
            "本次试算没有工资单。", { first: details, accept: (page) => page.trial?.id === trial.id }));
    }
    if (!ctx.current()) return [];
    const editable = ["draft", "calculated"].includes(run.state);
    if (editable && canMake(ctx)) result.push(await trialPanel(ctx, run, trial, path));
    const actions = [];
    if (run.state === "calculated" && canMake(ctx) && trial.calculated_by === ctx.accountId && !trial.blocked_count && trial.eligible_count) {
        actions.push(["submit", "送交复核"]);
    }
    if (canReview(ctx) && trial && trial.calculated_by !== ctx.accountId && run.state === "in_review") {
        actions.push(["close", "确认封账"], ["return", "退回重算", true]);
    }
    if (canReview(ctx) && trial && trial.calculated_by !== ctx.accountId && run.state === "closed") {
        actions.push(["publish", "发布工资单"]);
    }
    if (actions.length) {
        const form = ctx.form([reference("决定编号"), noteField()], actions,
            (action, body, key) => ctx.request(`${path}/${action}`, { ...body, version: run.version }, key),
            (_, action) => ctx.go(route, `账期已${reviewLabels[action]}。`));
        result.push(panel("送审与复核", form));
    }
    if (run.state === "calculated" && trial.blocked_count) {
        result.push(panel("送审条件", empty("请处理上表的阻断工资单，再按新的考勤水位重新试算。")));
    }
    if (closure) result.push(closurePanel(ctx, path, closure));
    if (reviewEvents.length) result.push(panel("审核时间线", table(["结果版本", "操作", "决定编号", "处理人", "处理说明", "记录时间"],
        reviewEvents.map((event) => [event.resulting_version, reviewLabels[event.action], event.decision_ref,
            event.actor_id, event.notes, event.recorded_at]))));
    result.push(await adjustmentsPanel(ctx, path, route, editable));
    return result;
}

function blockingReason(data) {
    const reason = data?.blocking_reason;
    return reason ? reasons[reason] ?? reason : "—";
}

async function trialPanel(ctx, run, trial, path) {
    const [{ rules }, { watermark }] = await Promise.all([
        ctx.request(ctx.base + "/payroll/rules"), ctx.request(ctx.base + "/attendance/watermark"),
    ]);
    if (!rules.length) return panel("工资试算", empty("请先创建用于该账期的计薪规则。"),
        button("进入计薪规则", () => ctx.navigate("payroll-rules")));
    const options = node("datalist");
    options.id = "payroll-rule-options";
    options.append(...rules.map((rule) => new Option(`${rule.rule_ref} · ${rule.title}`, rule.id)));
    const form = ctx.form([
        numberField("计薪规则编号", "rule_id", { min: 1, value: trial?.rule_id ?? rules.at(-1).id, list: options.id }),
        numberField("考勤水位", "attendance_watermark", { value: watermark, max: watermark }),
    ], [["trial", trial ? "重新试算" : "执行工资试算"]],
    (_, body, key) => ctx.request(path + "/trial", { ...body, version: run.version }, key),
    (result) => ctx.go({ run: run.id }, `试算第 ${result.trial_revision} 版已完成，阻断 ${result.blocked_count} 人。`));
    return panel("工资试算", form, options);
}

function closurePanel(ctx, path, closure) {
    const verify = button("核验封账摘要", async () => {
        verify.disabled = true;
        try {
            const result = await ctx.request(path + "/verify-closure");
            if (ctx.current()) ctx.notice(result.valid && result.digest === closure.digest
                ? "封账摘要核验通过。" : "封账摘要核验失败。", !result.valid || result.digest !== closure.digest);
        } catch (error) {
            if (ctx.current()) ctx.notice(error.message, true);
        } finally {
            verify.disabled = false;
        }
    });
    const actions = node("div", null, "actions panel-actions");
    actions.append(verify);
    return panel("封账凭据", facts([["SHA-256", closure.digest], ["封账人", closure.closed_by], ["封账时间", closure.closed_at]]), actions);
}

async function adjustmentsPanel(ctx, path, route, editable) {
    const source = path + "/adjustments";
    const list = await ctx.list("追溯补差", source, "adjustments",
        ["补差编号", "决定编号", "原工资单", "原行号", "原行金额", "重算金额", "计提差额", "处理说明"],
        (row) => [row.id, row.decision_ref, row.original_payslip_id, row.original_line_no,
            amount(ctx, row.original_minor), amount(ctx, row.recalculated_minor), amount(ctx, row.gross_delta_minor), row.notes]);
    if (!editable || !canMake(ctx)) return list;
    const form = ctx.form([
        numberField("原工资单编号", "original_payslip_id", { min: 1 }),
        numberField("原工资单分段行号", "original_line_no", { min: 1, max: 31 }),
        numberField("更正后月工资（最小货币单位）", "corrected_monthly_minor", { max: 100000000000 }),
        numberField("上一笔补差编号", "previous_adjustment_id", { value: 0 }),
        reference("决定编号"), noteField("补差原因"),
    ], [["adjust", "登记补差"]], (_, body, key) => ctx.request(source, body, key),
    (response) => ctx.go(route, `补差已登记，编号 ${response.id}，实付差额 ${amount(ctx, response.payable_delta_minor)}。请重新试算本账期。`));
    const section = node("section", null, "task-form");
    section.append(node("h3", "登记已封账工资的补差"), form);
    list.append(section);
    return list;
}

async function salaryDetail(ctx, route) {
    const path = `${ctx.base}/payroll/payslips/${route.slip}`;
    const { payslip, segments, adjustments } = await ctx.request(path);
    if (!ctx.current()) return [];
    ctx.tools(button("返回账期", () => ctx.go(route.run ? { run: route.run } : undefined)), attachment("下载试算单", path + ".csv"));
    return [
        panel(`${payslip.employee_name} · ${payslip.employee_code}`, facts([
            ["工资单编号", payslip.id], ["状态", statuses[payslip.state]], ["考勤确认编号", payslip.attendance_certification_id],
            ["阻断原因", blockingReason(payslip.facts)], ["正常应发", amount(ctx, payslip.gross_minor)],
            ["缺勤扣减", amount(ctx, payslip.absence_minor)], ["扣缴", amount(ctx, payslip.withholding_minor)],
            ["实付", amount(ctx, payslip.payable_minor)],
        ])),
        panel("出勤与合同计算分段", table(["行号", "起始日", "结束日", "天数", "部门编号", "合同编号", "月工资", "基本工资", "津贴"],
            segments.map((line) => [line.line_no, line.starts_on, line.ends_before, line.facts.days, line.department_id,
                line.facts.contract_id, amount(ctx, line.facts.contract_monthly_minor), amount(ctx, line.base_minor), amount(ctx, line.allowance_minor)]))),
        panel("账期补差明细", table(["决定编号", "原工资单", "原行号", "金额", "实付差额"],
            adjustments.map((item) => [item.decision_ref, item.original_payslip_id, item.original_line_no,
                amount(ctx, item.gross_delta_minor), amount(ctx, item.payable_delta_minor)]))),
    ];
}

async function runCosts(ctx, run) {
    const path = `${ctx.base}/payroll/runs/${run}/department-costs`;
    const report = await ctx.request(path);
    if (!ctx.current()) return [];
    ctx.tools(button("返回账期", () => ctx.go({ run })), attachment("下载部门成本", path + ".csv"));
    return [costPanel(report)];
}

function costPanel(report) {
    const display = (value) => money(report, value);
    return panel(`${report.company_code} · ${periodOf(report.period_start)} 部门成本`,
        facts([["状态", statuses[report.run_state]], ["正常成本", display(report.base_cost_minor)],
            ["补差成本", display(report.adjustment_minor)], ["成本合计", display(report.total_cost_minor)], ["封账摘要", report.digest]]),
        table(["部门", "正常成本", "补差成本", "成本合计", "任职分段数", "补差数"],
            report.departments.map((item) => [`${item.code} · ${item.name}`, display(item.base_cost_minor),
                display(item.adjustment_minor), display(item.total_cost_minor), item.segment_count, item.adjustment_count])));
}

async function groupCosts(ctx, month) {
    const selected = month ?? today(ctx.company).slice(0, 7);
    const path = "/api/payroll/department-costs";
    const query = "?period_start=" + encodeURIComponent(selected + "-01");
    const report = await ctx.request(path + query);
    if (!ctx.current()) return [];
    const form = node("form", null, "actions date-picker");
    form.append(monthField(ctx.company, selected), node("button", "查询成本"));
    form.addEventListener("submit", (event) => {
        event.preventDefault();
        ctx.go(new FormData(form).get("month"));
    });
    ctx.tools(form, attachment("下载集团成本", path + ".csv" + query));
    return [
        panel("按币种汇总", table(["币种", "法人数量", "正常成本", "补差成本", "成本合计"],
            report.totals_by_currency.map((row) => [row.currency, row.company_count, money(row, row.base_cost_minor),
                money(row, row.adjustment_minor), money(row, row.total_cost_minor)]), "该账期暂无可见的已封账成本。")),
        ...report.companies.map(costPanel),
    ];
}

async function payrollInquiries(ctx, route) {
    const source = ctx.base + "/payroll/inquiries";
    const selected = route?.state ?? "open";
    const suffix = selected ? "?state=" + encodeURIComponent(selected) : "";
    if (!route?.inquiry) {
        const form = node("form", null, "actions date-picker");
        const choice = field("核对状态", "state", { choices: [["", "全部"], ["open", "待答复"], ["answered", "已答复"],
            ["acknowledged", "已确认"]], value: selected, required: false });
        form.append(choice, node("button", "筛选"));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            ctx.go({ state: new FormData(form).get("state") });
        });
        ctx.tools(form, attachment("下载核对记录", source + ".csv" + suffix));
        return [await ctx.list("员工工资核对", source + suffix, "inquiries", ["核对编号", "工资单编号", "问题类型", "状态", "发起时间", "操作"],
            (row) => [row.reference, row.payslip_id, inquiryKinds[row.category], badge(row.state), row.opened_at,
                button("查看核对", () => ctx.go({ state: selected, inquiry: row.id }))])];
    }
    const path = `${source}/${route.inquiry}`;
    const entry = await ctx.request(path);
    if (!ctx.current()) return [];
    ctx.tools(button("返回核对列表", () => ctx.go({ state: selected })));
    const reports = [panel(entry.reference, facts([
        ["工资单编号", entry.payslip_id], ["类别", inquiryKinds[entry.category]], ["状态", statuses[entry.state]], ["版本", entry.version],
        ["员工疑问", entry.question], ["答复", entry.answer], ["决定编号", entry.decision_ref], ["员工确认时间", entry.acknowledged_at],
    ]))];
    if (entry.state === "open" && entry.opened_by !== ctx.accountId) {
        const form = ctx.form([reference("答复编号"), noteField("答复内容", "answer", 1200)], [["answer", "答复员工"]],
            (_, body, key) => ctx.request(path + "/answer", { ...body, version: entry.version }, key),
            () => ctx.go({ ...route, state: "answered" }, "已向员工提交答复。"));
        reports.push(panel("复核答复", form));
    }
    return reports;
}

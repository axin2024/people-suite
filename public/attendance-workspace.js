import { button, empty, facts, field, node, panel, reference, table, today } from "./workspace-ui.js";

const isHr = (company) => company.roles.includes("hr");
const canView = (company) => company.roles.some((role) => ["hr", "manager", "employee"].includes(role));
const date = (label, name, value) => field(label, name, { type: "date", value });
const number = (label, name, options = {}) => field(label, name, { type: "number", min: 1, ...options });
const instant = (label, name) => field(label, name, { maxLength: 25, hint: "2026-04-15T22:00:00+08:00" });
const memo = (label, name) => field(label, name, { multiline: true, maxLength: 600, wide: true });
const states = {
    scheduled: "已排班", anomaly: "打卡异常", ready: "待核对", worked: "已核定出勤", absent: "缺勤",
    excused: "免计缺勤", cancelled: "已取消", pending: "待处理", approved: "已批准", denied: "已拒绝",
    succeeded: "已成功", failed: "失败", roster: "班表", punches: "打卡",
    reserve: "预占", approve: "批准扣减", deny: "拒绝释放", cancel: "撤回或销假",
    in: "上班", out: "下班",
};
const problems = {
    no_punches: "无打卡", missing_out: "缺少下班卡", repeated_in: "重复上班卡", out_without_in: "下班卡无对应上班卡",
    outside_schedule: "打卡超出排班窗口", duration_exceeds_day: "工时超过 24 小时", shift_overlap: "班次时间重叠",
    shift_must_start_on_workday: "班次开始日与归属工作日不一致", workday_outside_employment: "工作日不在任职期间",
    shift_not_found: "班次不可见或不存在", employment_not_found: "任职不可见或不存在",
    line_not_found: "该行不存在",
};
const text = (code) => states[code] ?? code;
const reason = (code) => problems[code] ?? text(code);
const asTime = (company, value) => new Intl.DateTimeFormat("zh-CN", {
    timeZone: company.timezone, year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23",
}).format(Number(value) * 1000);

export const attendancePages = [
    { id: "attendance", label: "排班与假勤", allow: canView, load: attendance },
    { id: "attendance-imports", label: "假勤导入", allow: isHr, load: imports },
];

async function attendance(ctx, route = {}) {
    const on = route.on ?? today(ctx.company);
    if (!route.person) {
        const form = node("form", null, "actions date-picker");
        const search = node("button", "查询工作日", "subtle");
        search.type = "submit";
        form.append(date("工作日", "on", on), search);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            ctx.go({ on: new FormData(form).get("on") });
        });
        return [panel(null, form),
            await ctx.list("当日人员与待办", `${ctx.base}/attendance/workforce?workday=${on}`, "workforce",
                ["工号", "员工", "班次待核对", "待审批请假", "操作"],
                (person) => [person.employee_code, person.display_name, person.open_shifts, person.pending_leave,
                    button("查看假勤", () => ctx.go({ on, person }))], "该工作日没有可查看的任职。")];
    }
    const current = { ...route, on };
    const path = `${ctx.base}/employments/${route.person.id}`;
    if (route.shift) return shiftDetail(ctx, current);
    if (route.leave) return leaveDetail(ctx, current, path);
    if (route.certify) return certifications(ctx, current, path);
    return personDay(ctx, current, path);
}

function personHeader(route) {
    return panel(route.person.display_name, facts([["工号", route.person.employee_code], ["任职编号", route.person.id], ["工作日", route.on]]));
}

async function personDay(ctx, route, path) {
    const { on, person } = route;
    const role = person.access_role;
    const [{ shifts }, leave] = await Promise.all([
        ctx.request(`${path}/shifts?workday=${on}`),
        ctx.request(`${path}/leave?workday=${on}`),
    ]);
    if (ctx.current()) {
        ctx.tools(button("返回当日人员", () => ctx.go({ on })));
        if (role === "hr") ctx.tools(button("考勤期间确认", () => ctx.go({ ...route, certify: true })));
    }
    const result = [
        personHeader(route),
        panel("当日班次", table(["开始时间", "结束时间", "状态", "工时（分钟）", "操作"], shifts.map((shift) =>
            [asTime(ctx.company, shift.starts_at), asTime(ctx.company, shift.ends_at), text(shift.state), shift.worked_minutes,
                button("班次详情", () => ctx.go({ ...route, shift: shift.id }))]), "该工作日暂无班次。")),
        panel("当日请假", table(["类型", "分钟", "状态", "版本", "操作"],
            leave.requests.map((item) => [item.leave_kind, item.minutes, text(item.state), item.version,
                button("查看请假", () => ctx.go({ ...route, leave: item.id }))]), "该工作日暂无请假。")),
    ];
    if (role !== "manager") {
        result.push(panel("年度假期额度", table(["类型", "额度", "预占", "已用", "可用（分钟）"],
            leave.balances.map((row) => [row.leave_kind, row.granted_minutes, row.reserved_minutes, row.used_minutes, row.available_minutes]))));
        if (leave.balances.length) {
            const request = ctx.form([
                field("假期类型", "kind", { choices: leave.balances.map((item) => [item.leave_kind, item.leave_kind]) }),
                number("请假分钟", "minutes", { max: 1440 }), reference("申请编号", "reference"), memo("请假事由", "reason"),
            ], [["request", "提交请假"]], (_, body, key) => ctx.request(`${path}/leave`, { ...body, workday: on }, key),
            (response) => ctx.go({ ...route, leave: response.leave_id }, "请假已提交，额度已预占。"));
            result.push(panel("申请请假", request));
        }
    }
    if (role === "hr") {
        const shift = ctx.form([instant("班次开始", "starts_at"), instant("班次结束", "ends_at")],
            [["schedule", "创建班次"]], (_, body, key) => ctx.request(`${path}/shifts`, { ...body, workday: on }, key),
            (response) => ctx.go({ ...route, shift: response.shift_id }, "班次已创建。"));
        const grant = ctx.form([
            reference("假期类型代码", "kind"), number("额度分钟", "minutes", { max: 527040 }), reference("额度决定编号"),
        ], [["grant", "发放年度额度"]], (_, body, key) => ctx.request(`${path}/leave/grants`, { ...body, year: on.slice(0, 4) }, key),
        () => ctx.go(route, `已发放 ${on.slice(0, 4)} 年假期额度。`));
        const relations = ctx.form([
            number("员工账号编号", "account_id"),
        ], [["link", "关联员工账号"]], (_, body, key) => ctx.request(`${path}/account`, body, key),
        () => ctx.go(route, "员工账号已关联。"));
        const supervisor = ctx.form([
            number("主管账号编号", "account_id"), date("汇报关系生效日", "effective_from", on),
        ], [["assign", "保存直属主管"]], (_, body, key) => ctx.request(`${path}/manager`, body, key),
        () => ctx.go(route, "直属主管已保存。"));
        result.push(panel("新增班次", node("p", "时间需带时区偏移。夜班归属班次开始所在的工作日。", "muted"), shift),
            panel("年度额度发放", grant), panel("员工账号与直属主管", relations, supervisor));
    }
    return result;
}

function backToDay(ctx, route) {
    const { on, person } = route;
    if (ctx.current()) ctx.tools(button("返回当日假勤", () => ctx.go({ on, person })));
}

async function shiftDetail(ctx, route) {
    const path = `${ctx.base}/attendance/shifts/${route.shift}`;
    const { shift, attendance: status, punches } = await ctx.request(path);
    backToDay(ctx, route);
    const role = route.person.access_role;
    const issues = status.evidence.anomalies ?? [];
    const result = [
        personHeader(route),
        panel(`班次 ${shift.id}`, facts([["归属工作日", shift.workday], ["开始", asTime(ctx.company, shift.starts_at)],
            ["结束", asTime(ctx.company, shift.ends_at)], ["当前状态", text(status.state)], ["版本", status.version],
            ["核对工时（分钟）", status.worked_minutes], ["异常", issues.map(reason).join("、") || "无"],
            ["处理编号", status.decision_ref], ["处理说明", status.explanation]])),
        panel("打卡记录", table(["设备来源", "事件编号", "方向", "时间"],
            punches.map((row) => [row.source, row.event_ref, text(row.direction), asTime(ctx.company, row.punched_at)]), "暂无打卡。")),
    ];
    if (status.state === "cancelled") return result;
    if (role === "hr") {
        const punch = ctx.form([
            reference("设备来源", "source"), reference("打卡事件编号", "event_ref"),
            field("打卡方向", "direction", { choices: [["in", "上班"], ["out", "下班"]] }), instant("打卡时间", "punched_at"),
        ], [["record", "录入打卡"]], (_, body, key) => ctx.request(`${path}/punches`, body, key),
        () => ctx.go(route, "打卡已登记。"));
        result.push(panel("录入设备回执", punch));
    }
    if (role === "hr" || role === "manager") {
        const choices = [["worked", "确认出勤"], ["absent", "核定缺勤"], ["excused", "免计缺勤"]];
        if (role === "hr" && !punches.length) choices.push(["cancelled", "取消未打卡班次"]);
        const fields = [
            field("核对结果", "state", { choices }), reference("核对决定编号"), memo("核对依据", "explanation"),
        ];
        if (role === "hr") fields.push(number("人工核定分钟（可空）", "worked_minutes", { max: 1440, required: false }));
        const resolution = ctx.form(fields, [["review", "保存核对结果"]], (_, { worked_minutes, ...body }, key) => ctx.request(
            `${path}/resolution`, { ...body, version: status.version, worked_minutes: worked_minutes || undefined }, key),
        () => ctx.go(route, "班次核对已保存。"));
        result.push(panel("核对考勤", resolution));
    }
    return result;
}

async function leaveDetail(ctx, route, root) {
    const path = `${root}/leave/${route.leave}`;
    const { request, ledger } = await ctx.request(path);
    backToDay(ctx, route);
    const actions = [];
    const reviewer = route.person.access_role !== "employee";
    if (request.state === "pending") {
        if (reviewer && !route.person.is_self) actions.push(["approve", "批准请假"]);
        if (reviewer) actions.push(["deny", "拒绝请假", true]);
        actions.push(["cancel", "撤回请假", true]);
    } else if (request.state === "approved" && reviewer) actions.push(["cancel", "办理销假", true]);
    const result = [
        personHeader(route),
        panel(`请假单 ${request.id}`, facts([["请假日", request.workday], ["分钟", request.minutes],
            ["状态", text(request.state)], ["版本", request.version], ["事由", request.reason]])),
        panel("假期账本", table(["操作", "决定编号", "预占变化", "已用变化", "时间"],
            ledger.map((line) => [text(line.action), line.decision_ref, line.reserved_change, line.used_change, line.recorded_at]))),
    ];
    if (actions.length) {
        const approve = ctx.form([reference("请假处理编号")], actions,
            (action, body, key) => ctx.request(`${path}/${action}`, { ...body, version: request.version }, key),
            (response) => ctx.go(route, `请假已更新为「${text(response.state)}」。`));
        result.push(panel("处理请假", approve));
    }
    return result;
}

async function certifications(ctx, route, root) {
    const path = `${root}/attendance-certifications`;
    backToDay(ctx, route);
    const history = await ctx.list("已确认期间", path, "certifications", ["开始日", "截止日（不含）", "班次", "出勤分钟", "缺勤分钟", "水位", "决定编号"],
        (row) => [row.starts_on, row.ends_before, row.expected_shifts, row.worked_minutes, row.absent_minutes, row.watermark, row.decision_ref],
        "当前任职暂无已确认期间。");
    const blockers = node("div");
    const confirm = ctx.form([
        date("期间开始日", "starts_on", route.on), date("截止日（不含）", "ends_before"),
        number("预计班次", "expected_shifts", { min: 0, max: 1000 }), reference("期间确认编号"), memo("核对说明", "notes"),
    ], [["certify", "确认考勤期间"]], (_, body, key) => {
        blockers.replaceChildren();
        return ctx.request(path, body, key);
    }, (response) => ctx.go(route, `期间已确认，考勤水位 ${response.watermark}。`),
    (error) => {
        if (error.code !== "attendance_period_incomplete") return;
        const { blocking_shifts, expected_shifts, scheduled_shifts, blocking_count } = error.details;
        blockers.replaceChildren(facts([["预计班次", expected_shifts], ["实排班次", scheduled_shifts], ["阻断班次", blocking_count]]),
            table(["班次编号", "阻断原因"], blocking_shifts.map((row) => [row.shift_id, reason(row.reason)])));
    });
    return [personHeader(route), history, panel("确认考勤供薪资使用", confirm, blockers)];
}

async function imports(ctx, route) {
    const root = `${ctx.base}/attendance/imports`;
    if (route) {
        const id = route.id ?? route;
        return importDetail(ctx, id, `${root}/${id}`, route.receipt);
    }
    const list = await ctx.list("我的导入批次", root, "batches", ["批次", "类型", "行数", "创建时间", "操作"],
        (row) => [row.id, text(row.kind), row.row_count, row.created_at, button("打开批次", () => ctx.go(row.id))], "暂无导入批次。");
    const draft = memo("导入行（JSON 数组）", "rows");
    draft.querySelector("textarea").maxLength = 64000;
    const hint = node("p", "每批 1 至 100 行。班表需要任职编号、工作日和起止时刻。打卡需要班次编号、设备、事件和时刻。", "muted");
    const example = node("div");
    const kind = field("导入类型", "kind", { choices: [["roster", "班表"], ["punches", "打卡"]] });
    const examples = {
        roster: [{ row_ref: "roster-1", employment_id: 1, workday: "2026-04-15",
            starts_at: "2026-04-15T22:00:00+08:00", ends_at: "2026-04-16T06:00:00+08:00" }],
        punches: [{ row_ref: "punch-1", shift_id: 1, source: "GATE-01", event_ref: "SHIFT-IN",
            direction: "in", punched_at: "2026-04-15T22:00:00+08:00" }],
    };
    const file = field("加载 JSON 文件", "json_file", { type: "file", required: false });
    const fileInput = file.querySelector("input");
    fileInput.accept = ".json,application/json";
    const textarea = draft.querySelector("textarea");
    fileInput.addEventListener("change", async () => {
        const chosen = fileInput.files[0];
        if (!chosen) return;
        const before = textarea.value;
        try {
            if (chosen.size > 64000) throw new Error();
            const source = await chosen.text();
            if (ctx.current() && fileInput.files[0] === chosen && textarea.value === before && !textarea.disabled) {
                textarea.value = source;
            }
        } catch {
            if (ctx.current()) ctx.notice("请选择可读且小于 64 KB 的 JSON 文件。", true);
        }
    });
    example.className = "actions panel-actions";
    example.append(file, button("填入格式示例", () => {
        textarea.value = JSON.stringify(examples[kind.querySelector("select").value], null, 2);
    }));
    const submit = ctx.form([kind, draft], [["submit", "建立导入批次"]], (_, body, key) => {
        let rows;
        try {
            rows = JSON.parse(body.rows);
            if (!Array.isArray(rows)) throw new Error();
        } catch {
            throw Object.assign(new Error("Invalid batch format."), { code: "invalid_attendance_batch_json" });
        }
        return ctx.request(root, { kind: body.kind, rows }, key);
    }, (response) => ctx.go(response.import_id, "导入批次已创建。"));
    return [list, panel("创建导入批次", hint, submit, example)];
}

async function importDetail(ctx, id, path, receipt) {
    const report = await ctx.request(path);
    if (ctx.current()) ctx.tools(button("返回导入列表", () => ctx.go()));
    const rows = report.rows.map((row) => [row.line_no, row.row_ref, text(row.state), row.attempts,
        row.outcome?.error ? reason(row.outcome.error) : row.outcome?.shift_id ?? "—"]);
    const menu = [panel(`导入批次 ${id}`, facts([
        ["类型", text(report.import.kind)], ["待处理", report.counts.pending],
        ["已成功", report.counts.succeeded], ["失败", report.counts.failed],
    ])), panel("逐行结果", table(["行号", "行标识", "状态", "尝试次数", "班次编号或失败原因"], rows))];
    if (receipt) {
        menu.push(panel("本次重试回执", table(["所选行", "结果", "原因或班次编号"], receipt.results.map((row) => {
            const error = row.reason ?? row.outcome?.error;
            return [row.line_no, row.state === "rejected" ? "已拒绝" : text(row.state),
                error ? reason(error) : row.outcome?.shift_id ?? "—"];
        }))));
    }
    if (report.counts.pending) {
        const run = ctx.form([
            number("每次最多行数", "limit", { max: 25, value: 25 }), number("从行号之后继续", "after_row", { min: 0, max: 100, value: 0 }),
        ], [["run", "继续处理待处理行"]], (_, body) => ctx.request(`${path}/run`, body),
        () => ctx.go(id, "已更新逐行处理结果。"));
        menu.push(panel("分批执行", run));
    }
    const failed = report.rows.filter((row) => row.state === "failed");
    if (failed.length) {
        const options = node("fieldset", null, "wide import-retry");
        options.append(node("legend", "选择失败行（单次最多 25 行）"));
        for (const row of failed) {
            const label = field(`第 ${row.line_no} 行 · ${row.row_ref} · ${reason(row.outcome.error)}`,
                String(row.line_no), { type: "checkbox", required: false });
            label.querySelector("input").checked = receipt?.line_nos.includes(row.line_no) ?? false;
            options.append(label);
        }
        const retry = ctx.form([options], [["retry", "重试所选行"]], (_, body, key) => {
            const selected = Object.keys(body).map(Number);
            if (!selected.length || selected.length > 25) {
                throw Object.assign(new Error(), { code: "invalid_attendance_retry_selection" });
            }
            return ctx.request(`${path}/retry`, { line_nos: selected }, key);
        }, (result) => ctx.go({ id, receipt: result }, "所选行已处理。"));
        menu.push(panel("重试导入", retry));
    }
    return menu;
}

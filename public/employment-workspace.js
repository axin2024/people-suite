import { button, empty, facts, field, money, node, panel, reference, table, today } from "./workspace-ui.js";

const date = (label, name, value, required = true) => field(label, name, { type: "date", value, required });
const number = (label, name, options = {}) => field(label, name, { type: "number", min: 1, ...options });
const transferState = (row) => {
    if (row.state === "accepted") return "已接收";
    return row.stale ? "任职已变更，需重新申请" : "待接收";
};

export const employmentPages = [
    { id: "directory", label: "任职档案", allow: (c) => c.roles.includes("hr"), load: directory },
    { id: "legal-transfers", label: "跨法人接收", allow: (c) => c.roles.includes("hr"), load: incoming },
];

async function directory(ctx, record) {
    const root = `${ctx.base}/employments`;
    if (!record) {
        return [await ctx.list("法人任职记录", root, "employments", ["工号", "员工", "起始日期", "任职截至", "任职版本", "操作"],
            (row) => [row.employee_code, row.display_name, row.starts_on, row.ends_before, row.assignment_version,
                button("查看任职", () => ctx.go(row.id))], "当前法人暂无任职档案。")];
    }
    const id = record.id ?? record;
    const path = `${root}/${id}`;
    const { employment, employee, assignments, contracts } = await ctx.request(path);
    const on = record.on ?? (employment.ends_before ? assignments.at(-1).effective_from : today(ctx.company));
    const [atDate, outgoing] = await Promise.all([
        ctx.request(`${path}?on=${on}`).catch((error) => {
            if (error.code === "assignment_not_effective") return null;
            throw error;
        }),
        ctx.list("跨法人转出记录", `${path}/legal-transfers`, "outgoing",
            ["单号", "接收法人", "目标岗位", "生效日", "状态", "接收决定"],
            (row) => [row.id, row.target_company_name, row.target_position_code, row.effective_on, transferState(row), row.decision_ref],
            "该员工暂无跨法人转出。"),
    ]);
    if (ctx.current()) ctx.tools(button("返回任职列表", () => ctx.go()));
    const result = [
        panel(employee.display_name, facts([["工号", employee.employee_code], ["起始日期", employment.starts_on],
            ["任职截至", employment.ends_before], ["任职版本", employment.assignment_version]])),
        panel("按日查看任职", assignmentLookup(ctx, id, on),
            atDate ? facts([["岗位", `${atDate.position_code} · ${atDate.position_title}`], ["部门", atDate.department_name],
                ["版本", atDate.version], ["开始日", atDate.effective_from], ["截止日（不含）", atDate.effective_until]])
                : empty("该日期没有有效任职。")),
        panel("岗位生效历史", table(["版本", "岗位", "部门", "起始日期", "截止日（不含）", "调岗影响"],
            assignments.map((row) => [row.version, `${row.position_code} · ${row.position_title}`,
                row.department_name, row.effective_from, row.effective_until, row.version > 1
                    ? button("查看排班与成本期间", () => ctx.go({ id, on: row.effective_from, impact: row.id })) : "—"]))),
        panel("合同版本", table(["版本", "合同编号", "生效日期", "约定截止日（不含）", "月工资"],
            contracts.map((row) => [row.version, row.reference, row.effective_from, row.ends_before, money(ctx.company, row.monthly_minor)]))),
        outgoing,
    ];
    if (record.impact) result.push(...await transferImpact(ctx, path, record.impact, record.receipt));
    if (!employment.ends_before) {
        const route = { id, on };
        result.push(renew(ctx, route, path, contracts.at(-1)), ...transferForms(ctx, route, path, employment, assignments.at(-1)),
            offboarding(ctx, id, employment.assignment_version));
    }
    return result;
}

async function transferImpact(ctx, path, assignment, receipt) {
    const source = `${path}/transfer-impact?assignment_id=${assignment}`;
    const localTime = new Intl.DateTimeFormat("zh-CN", {
        timeZone: ctx.company.timezone, year: "numeric", month: "2-digit", day: "2-digit",
        hour: "2-digit", minute: "2-digit", hourCycle: "h23",
    });
    return Promise.all([
        ctx.list("受调岗影响的排班", source + "&kind=shifts", "shifts", ["班次编号", "归属工作日", "开始", "结束"],
            (row) => [row.id, row.workday, localTime.format(row.starts_at * 1000), localTime.format(row.ends_at * 1000)],
            "该任职区间暂无相关班次。", { first: receipt }),
        ctx.list("受调岗影响的成本期间", source + "&kind=cost_periods", "cost_periods", ["账期编号", "开始日", "截止日（不含）", "处理方式"],
            (row) => [row.id, row.period_start, row.period_end,
                ["closed", "published"].includes(row.state) ? "已封账，使用追溯补差" : "核对任职分摊，必要时重新试算"],
            "该任职区间暂无相关账期。", { first: receipt }),
    ]);
}

function assignmentLookup(ctx, id, on) {
    const wrapper = date("任职查询日期", "on", on);
    const form = node("form", null, "actions date-picker panel-actions");
    const search = node("button", "查看当日任职", "subtle");
    search.type = "submit";
    form.append(wrapper, search);
    form.addEventListener("submit", (event) => {
        event.preventDefault();
        ctx.go({ id, on: new FormData(form).get("on") });
    });
    return form;
}

function salary(ctx, amount) {
    return number(`月工资（${ctx.company.currency} 最小货币单位）`, "monthly_minor", { max: 100000000000, value: amount });
}

function renew(ctx, route, path, previous) {
    return panel("合同续签", ctx.form([
        reference("新合同编号", "contract_ref"), date("续签生效日", "effective_from", previous.ends_before || ""),
        date("新合同截止日（不含，可空）", "ends_before", "", false), salary(ctx, previous.monthly_minor),
    ], [["renew", "提交合同续签"]], (_, body, key) => ctx.request(`${path}/contracts`, {
        ...body, version: previous.version, ends_before: body.ends_before || undefined,
    }, key), (result) => ctx.go(route, `续签已登记为合同第 ${result.version} 版，生效日 ${result.effective_from}。`)));
}

function transferForms(ctx, route, path, employment, assignment) {
    const within = node("div");
    const between = node("div");
    const reportConflict = (container) => (error) => {
        if (error.code !== "assignment_interval_conflict") return;
        const details = error.details;
        container.replaceChildren(facts([["当前任职版本", details.current_version], ["当前区间开始日", details.from],
            ["当前区间截止日（不含）", details.until ?? "持续有效"]]));
    };
    const submit = (resource, container) => (_, body, key) => {
        container.replaceChildren();
        return ctx.request(`${path}/${resource}`, { ...body, assignment_version: employment.assignment_version }, key);
    };
    const positions = node("datalist");
    positions.id = "transfer-positions";
    const effective = date("调岗生效日", "effective_from", "");
    const effectiveInput = effective.querySelector("input");
    async function loadPositions(on) {
        if (!on) return;
        const { positions: rows } = await ctx.request(`${ctx.base}/positions?on=${on}`);
        if (ctx.current() && (effectiveInput.value || route.on) === on) {
            positions.replaceChildren(...rows.filter((row) => row.active && row.id !== assignment.position_id)
                .map((row) => new Option(`${row.code} · ${row.title}`, row.id)));
        }
    }
    function updatePositions(on) {
        positions.replaceChildren();
        loadPositions(on).catch(() => {});
    }
    effectiveInput.addEventListener("change", () => updatePositions(effectiveInput.value));
    updatePositions(route.on);
    const transfer = ctx.form([
        effective, number("目标岗位编号", "position_id", { list: positions.id }),
    ], [["transfer", "确认法人内调岗"]], submit("transfers", within),
    (result) => ctx.go({ ...route, on: result.next.from, impact: result.assignment_id, receipt: result.impact },
        `已登记 ${result.next.from} 生效的调岗。请复核 ${result.schedule_review_from} 起的排班和 ${result.cost_recalculation_from} 账期成本。`),
    reportConflict(within));
    const legal = ctx.form([
        date("转出生效日", "effective_from", ""),
        number("接收法人编号", "target_company_id"), number("接收岗位编号", "target_position_id"),
    ], [["legal", "提交跨法人调动"]], submit("legal-transfers", between),
    (result) => ctx.go(route, `跨法人调动单 ${result.transfer_id} 已提交，等待接收法人 HR 确认。`), reportConflict(between));
    return [
        panel("法人内调岗", node("p", "按生效日选岗位，也可输入组织与编制中的岗位编号。", "muted"), transfer, positions, within),
        panel("跨法人转出", node("p", "请与接收法人 HR 核对法人及岗位编号，再提交转出。", "muted"), legal, between),
    ];
}

function offboarding(ctx, id, version) {
    return panel("发起离职交接", ctx.form([
        reference("离职单号", "reference"), date("离职生效日", "ends_before", today(ctx.company)),
        field("离职事由", "reason", { multiline: true, maxLength: 600, wide: true }),
        field("应归还设备", "assets", { multiline: true, required: false, wide: true, hint: "每行填写设备编号、空格、设备名称" }),
    ], [["start", "发起离职"]], (_, body, key) => {
        const equipment = body.assets.split(/\r?\n/).map((line) => line.trim()).filter(Boolean).map((line) => {
            const [code, ...label] = line.split(/\s+/);
            return { code, label: label.join(" ") };
        });
        return ctx.request(`${ctx.base}/offboarding`, {
            employment_id: id, assignment_version: version, ends_before: body.ends_before,
            reference: body.reference, reason: body.reason, equipment,
        }, key);
    }, (result) => ctx.navigate("offboarding", result.case_id, "离职单已发起。")));
}

async function incoming(ctx, route = {}) {
    const root = `${ctx.base}/legal-transfers`;
    const state = route.state ?? "pending";
    if (!route.id) {
        ctx.tools(state === "pending"
            ? button("已接收记录", () => ctx.go({ state: "accepted" }))
            : button("待接收调动", () => ctx.go()));
        return [await ctx.list(state === "pending" ? "待接收调动" : "已接收调动", `${root}?state=${state}`, "incoming",
            ["编号", "员工", "工号", "转出法人", "接收岗位", "生效日", "操作"],
            (row) => [row.id, row.display_name, row.employee_code, row.source_company_name, row.target_position_code, row.effective_on,
                button("查看调动单", () => ctx.go({ id: row.id, state }))], "当前列表没有调动单。")];
    }
    const path = `${root}/${route.id}`;
    const row = await ctx.request(path);
    if (ctx.current()) ctx.tools(button("返回接收列表", () => ctx.go({ state })));
    const header = panel(`跨法人调动单 ${row.id}`, facts([["员工", row.display_name], ["工号", row.employee_code],
        ["转出法人", row.source_company_name], ["接收法人", ctx.company.name], ["接收岗位", row.target_position_code],
        ["生效日", row.effective_on], ["状态", transferState(row)], ["接收决定", row.decision_ref]]));
    if (row.state === "accepted") {
        const actions = node("div", null, "actions panel-actions");
        actions.append(button("查看接收任职", () => ctx.navigate("directory", row.target_employment_id)));
        header.append(actions);
        return [header];
    }
    if (row.stale || row.requested_by === ctx.accountId) {
        header.append(empty(row.stale ? "源任职版本已变化，请由转出方重新申请。"
            : "请由接收法人的另一位 HR 处理此申请。"));
        return [header];
    }
    const accept = ctx.form([
        reference("接收决定编号"), reference("接收合同编号", "contract_ref"),
        salary(ctx), date("接收合同截止日（不含，可空）", "ends_before", "", false),
    ], [["accept", "确认接收调动"]], (_, body, key) => ctx.request(`${path}/accept`, {
        ...body, ends_before: body.ends_before || undefined,
    }, key), () => ctx.go({ ...route, state: "accepted" }, "调动已接收，接收任职和合同已创建。"));
    return [header, panel("办理接收", accept)];
}

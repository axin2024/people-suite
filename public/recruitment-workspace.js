import { button, empty, facts, field, money, node, panel, reference, table, today } from "./workspace-ui.js";

const canManage = (company) => company.roles.includes("hr");
const canRecruit = (company) => canManage(company) || company.roles.includes("recruiter");
const positionPath = (ctx, id) => `${ctx.base}/positions/${id}`;
const byDate = (path, on) => `${path}?on=${on}`;
const states = {
    pending: "待审批", open: "招聘中", submitted: "已登记", interviewed: "已面试",
    offered: "录用审批中", draft: "待签核", signed: "已签核", accepted: "已接受",
    revoked: "已撤销", hired: "已入职", sign: "签核", accept: "接受录用", revoke: "撤销录用",
};
const label = (state) => states[state] ?? state;
const dateField = (title, name, value, required = true) => field(title, name, { type: "date", value, required });
const numberField = (title, name, min = 1, max) => field(title, name, { type: "number", min, max });
const notes = (title, name, length) => field(title, name, { multiline: true, wide: true, maxLength: length });

export const recruitmentPages = [
    { id: "organization", label: "组织与编制", allow: canManage, load: organization },
    { id: "recruitment", label: "招聘与录用", allow: canRecruit, load: recruitment },
];

function moveDate(ctx, route, on) {
    const date = dateField("查看日期", "on", on);
    const input = date.querySelector("input");
    const pick = node("form", null, "actions date-picker");
    const submit = node("button", "查询日期", "subtle");
    submit.type = "submit";
    pick.append(date, submit);
    pick.addEventListener("submit", (event) => {
        event.preventDefault();
        ctx.go({ ...route, on: input.value });
    });
    return pick;
}

function positionList(ctx, on, action) {
    return ctx.list("岗位目录", byDate(`${ctx.base}/positions`, on), "positions",
        ["岗位代码", "岗位", "部门", "状态", ""],
        (row) => [row.code, row.title, row.department_name, row.active ? "启用" : "停用", action(row)],
        "该日期暂无可见岗位。");
}

function departmentChoices(departments) {
    return departments.map((item) => [item.id, `${item.code} · ${item.name}`]);
}

function departmentRows(departments) {
    const children = new Map();
    for (const item of departments) {
        const siblings = children.get(item.parent_id) ?? [];
        siblings.push(item);
        children.set(item.parent_id, siblings);
    }
    const rows = [];
    function add(parent, depth) {
        for (const item of children.get(parent) ?? []) {
            const name = node("span", item.name);
            name.style.marginInlineStart = `${Math.min(depth, 8)}em`;
            rows.push([name, item.code, item.effective_from]);
            add(item.id, depth + 1);
        }
    }
    add(null, 0);
    return rows;
}

async function organization(ctx, route = {}) {
    const on = route.on ?? today(ctx.company);
    if (route.position) return positionDetails(ctx, { ...route, on });

    const [{ departments }, positions] = await Promise.all([
        ctx.request(byDate(`${ctx.base}/departments`, on)),
        positionList(ctx, on, (row) => button("查看岗位", () => ctx.go({ on, position: row.id, title: row.title }))),
    ]);
    const choices = departmentChoices(departments);
    const addDepartment = ctx.form([
        reference("部门代码", "code"), field("部门名称", "name", { maxLength: 160 }),
        dateField("生效日期", "effective_from", on),
        field("上级部门", "parent_id", { choices: [["", "集团直属"], ...choices], required: false }),
    ], [["create", "创建部门"]], (_, body, key) => ctx.request(`${ctx.base}/departments`, {
        ...body, parent_id: body.parent_id || undefined,
    }, key), (result) => ctx.go({ on: result.effective_from }, "部门已创建。"));
    const addPosition = choices.length ? ctx.form([
        reference("岗位代码", "code"), field("岗位名称", "title", { maxLength: 160 }),
        field("所属部门", "department_id", { choices }), dateField("生效日期", "effective_from", on),
    ], [["create", "创建岗位"]], (_, body, key) => ctx.request(`${ctx.base}/positions`, body, key),
    (result) => ctx.go({
        on: result.effective_from, position: result.position_id, title: result.title,
    }, "岗位已创建。")) : empty("请先在该日期建立一个有效部门。");
    return [
        panel(null, moveDate(ctx, {}, on)),
        panel("部门树", table(["部门", "代码", "生效日"], departmentRows(departments))),
        positions, panel("新增部门", addDepartment), panel("新增岗位", addPosition),
    ];
}

async function positionDetails(ctx, route) {
    const { position, on } = route;
    const path = positionPath(ctx, position);
    const [{ revisions }, headcount] = await Promise.all([
        ctx.request(`${path}/revisions`), ctx.request(byDate(`${path}/headcount`, on)),
    ]);
    if (ctx.current()) ctx.tools(button("返回组织目录", () => ctx.go({ on })));
    const latest = revisions.at(-1);
    const atDate = revisions.find((row) => row.effective_from <= on && (row.effective_until === null || row.effective_until > on));
    const availableDate = latest.effective_from > on ? latest.effective_from : on;
    const { departments } = await ctx.request(byDate(`${ctx.base}/departments`, availableDate));
    const departmentNames = new Map(departments.map((item) => [item.id, item.name]));
    const conflicts = node("div");
    conflicts.hidden = true;
    const revise = ctx.form([
        field("岗位名称", "title", { value: latest.title, maxLength: 160 }),
        field("所属部门", "department_id", { choices: departmentChoices(departments), value: latest.department_id }),
        dateField("新版本生效日", "effective_from", on > latest.effective_from ? on : ""),
        field("岗位状态", "active", { choices: [["1", "启用"], ["0", "停用"]], value: Number(latest.active) }),
    ], [["revise", "保存岗位版本"]], (_, body, key) => {
        conflicts.hidden = true;
        return ctx.request(`${path}/revisions`, { ...body, revision_id: latest.id, active: body.active === "1" }, key);
    }, (result) => ctx.go({ ...route, on: result.effective_from, title: result.title }, "岗位版本已保存。"),
    (error) => {
        if (error.code !== "position_still_occupied") return;
        const { assignments, occupancies } = error.details;
        conflicts.replaceChildren(
            table(["任职编号", "开始日", "结束日前"], assignments.map((row) => [row.employment_id, row.effective_from, row.effective_until]), "没有任职记录。"),
            table(["占用编号", "录用编号", "开始日", "结束日前"], occupancies.map((row) => [row.id, row.offer_id, row.starts_on, row.ends_before]), "没有录用占用记录。"),
        );
        conflicts.hidden = false;
    });
    const budget = ctx.form([
        dateField("预算开始日", "starts_on", on), dateField("预算截止日（不含）", "ends_before"),
        numberField("编制数", "capacity", 0, 1000000),
    ], [["budget", "建立编制预算"]], (_, body, key) => ctx.request(`${path}/budgets`, body, key),
    (result) => ctx.go({ ...route, on: result.starts_on }, "编制预算已建立。"));
    const authorization = ctx.form([
        numberField("招聘专员账号编号", "account_id"),
    ], [["grant", "授权此岗位"]], (_, body) => ctx.request(`${path}/recruiters`, body),
    () => ctx.go(route, "招聘专员已获本岗位权限。"));
    return [
        panel(atDate?.title ?? latest.title, moveDate(ctx, route, on)),
        panel("编制占用", headcount.budget ? facts([
            ["预算期间", `${headcount.budget.starts_on} 至 ${headcount.budget.ends_before}（不含）`],
            ["总编制", headcount.budget.capacity], ["已占用", headcount.occupied], ["可用", headcount.available],
        ]) : empty("该日期暂无有效编制预算。")),
        panel("岗位生效历史", table(["版本", "岗位", "部门", "开始日", "结束日前", "状态"],
            revisions.map((row) => [row.id, row.title, departmentNames.get(row.department_id), row.effective_from,
                row.effective_until, row.active ? "启用" : "停用"]))),
        panel("新增岗位版本", revise, conflicts), panel("新增编制预算", budget), panel("岗位招聘授权", authorization),
    ];
}

async function recruitment(ctx, route = {}) {
    const on = route.on ?? today(ctx.company);
    const open = { ...route, on };
    if (route.application) return applicationDetails(ctx, open);
    if (route.requisition) return requisitionDetails(ctx, open);
    if (route.position) return positionRequisitions(ctx, open);
    return [
        panel(null, moveDate(ctx, {}, on)),
        await positionList(ctx, on, (row) => button("查看招聘", () => ctx.go({ on, position: row.id, title: row.title }))),
    ];
}

async function positionRequisitions(ctx, route) {
    const { position, title, on } = route;
    const path = `${positionPath(ctx, position)}/requisitions`;
    if (ctx.current()) ctx.tools(button("返回岗位目录", () => ctx.go({ on })));
    const list = await ctx.list("招聘申请", path, "requisitions", ["编号", "计划到岗", "说明", "状态", ""],
        (row) => [row.id, row.proposed_start, row.justification, label(row.state),
            button("进入申请", () => ctx.go({ ...route, requisition: row.id }))], "该岗位暂无招聘申请。");
    const request = ctx.form([
        dateField("计划到岗日", "proposed_start", on), notes("招聘原因", "justification", 600),
    ], [["request", "提交招聘申请"]], (_, body, key) => ctx.request(path, body, key),
    (result) => ctx.go({ ...route, requisition: result.requisition_id }, "招聘申请已提交，等待 HR 审批。"));
    return [panel(title), list, panel("新增招聘申请", request)];
}

async function requisitionDetails(ctx, route) {
    const { position, requisition, title, on } = route;
    const path = `${positionPath(ctx, position)}/requisitions/${requisition}`;
    if (ctx.current()) ctx.tools(button("返回招聘申请", () => ctx.go({ on, position, title })));
    const first = await ctx.request(path);
    const item = first.requisition;
    const contents = [
        panel(`${title} · 招聘申请 ${requisition}`, facts([
            ["计划到岗", item.proposed_start], ["原因", item.justification], ["状态", label(item.state)], ["当前版本", item.version],
        ])),
        await ctx.list("候选人申请", path, "applications", ["姓名", "候选编号", "状态", "当前版本", ""],
            (row) => [row.display_name, row.identity_ref, label(row.state), row.version,
                button("查看档案", () => ctx.go({ ...route, proposedStart: item.proposed_start, application: row.id }))],
            "该招聘申请暂无候选人。", { first }),
    ];
    if (item.state === "pending" && canManage(ctx.company)) {
        const approve = ctx.form([], [["approve", "审批并开放招聘"]],
            (_, body, key) => ctx.request(`${path}/open`, { version: item.version }, key),
            () => ctx.go(route, "招聘已开放。"));
        contents.push(panel("招聘审批", approve));
    }
    if (item.state === "open") {
        const apply = ctx.form([
            reference("候选编号", "identity_ref"), field("候选姓名", "display_name", { maxLength: 160 }),
        ], [["apply", "登记候选人"]], (_, body, key) => ctx.request(`${path}/apply`, body, key),
        (result) => ctx.go({ ...route, proposedStart: item.proposed_start, application: result.application_id }, "候选人申请已登记。"));
        contents.push(panel("新增候选人", apply));
    }
    return contents;
}

async function applicationDetails(ctx, route) {
    const { position, application, ...remainder } = route;
    const root = positionPath(ctx, position);
    const path = `${root}/applications/${application}`;
    if (ctx.current()) ctx.tools(button("返回候选人列表", () => ctx.go({ ...remainder, position })));
    const data = await ctx.request(path);
    const current = data.application;
    const contents = [
        panel(data.candidate.display_name, facts([
            ["岗位", route.title], ["候选编号", data.candidate.identity_ref], ["申请状态", label(current.state)], ["当前版本", current.version],
        ])),
        panel("面试评估", table(["评分", "意见", "评估人编号", "记录时间"],
            data.reviews.map((item) => [item.rating, item.notes, item.author_id, item.recorded_at]))),
        panel("录用历史", table(["录用编号", "生效日", "月薪", "状态", "当前版本", "最近决定"],
            data.offers.map((item) => [item.id, item.effective_on, money(ctx.company, item.monthly_minor),
                label(item.state), item.version, item.decision_ref])),
        table(["录用编号", "操作", "决定编号", "操作者编号", "记录时间"],
            data.decisions.map((item) => [item.offer_id, label(item.action), item.decision_ref, item.actor_id, item.decided_at]),
            "暂无签核决定。")),
    ];
    if (["submitted", "interviewed"].includes(current.state)) {
        const evaluate = ctx.form([
            numberField("评分（1 至 5）", "rating", 1, 5), notes("面试意见", "notes", 1200),
        ], [["interview", "保存面试评估"]], (_, body, key) => ctx.request(`${path}/interview`, {
            ...body, version: current.version,
        }, key), () => ctx.go(route, "面试评估已保存。"));
        contents.push(panel("新增面试评估", evaluate));
    }
    if (current.state === "interviewed") {
        const draft = ctx.form([
            dateField("录用生效日", "effective_on", route.proposedStart),
            numberField(`月薪（${ctx.company.currency} 最小货币单位）`, "monthly_minor", 1, 100000000000),
        ], [["draft", "生成录用草稿"]], (_, body, key) => ctx.request(`${path}/offer`, {
            ...body, version: current.version,
        }, key), () => ctx.go(route, "录用草稿已生成，等待 HR 签核。"));
        contents.push(panel("拟定录用", draft));
    }
    const active = data.offers.at(-1);
    if (canManage(ctx.company) && active && ["draft", "signed", "accepted"].includes(active.state)) {
        contents.push(...offerActions(ctx, route, `${root}/offers/${active.id}`, active));
    }
    return contents;
}

function offerActions(ctx, route, path, offer) {
    const actions = { draft: [["sign", "签核录用"]], signed: [["accept", "接受录用并占编"]], accepted: [] };
    const decide = ctx.form([reference("录用决定编号")],
        [...actions[offer.state], ["revoke", "撤销录用", true]], (action, body, key) => ctx.request(`${path}/${action}`, {
            ...body, version: offer.version,
        }, key), (result) => ctx.go(route, `录用已更新为「${label(result.state)}」。`));
    const contents = [panel(`录用决定 · ${offer.id}`, decide)];
    if (offer.state === "accepted") {
        const intake = ctx.form([
            reference("员工编号", "employee_code"), reference("合同编号", "contract_ref"),
            dateField("合同截止日（不含，可空）", "contract_ends_before", "", false),
        ], [["onboard", "确认入职"]], (_, body, key) => ctx.request(`${path}/onboard`, {
            ...body, version: offer.version, contract_ends_before: body.contract_ends_before || undefined,
        }, key), (result) => ctx.navigate("directory", result.employment_id, "入职已确认，任职档案及合同已建立。"));
        contents.push(panel("办理入职", intake));
    }
    return contents;
}

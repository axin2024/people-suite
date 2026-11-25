import { button, facts, field, node, panel, reference, table } from "./workspace-ui.js";

const phases = { goals: "制定目标", evaluation: "自评与主管评分", calibration: "校准", finalized: "已定稿" };
const nextPhase = { goals: "开启评价", evaluation: "开启校准", calibration: "定稿并发布" };
const revisionKinds = { manager: "主管评分", calibration: "校准", amendment: "定稿后修订" };
const date = (label, name, value) => field(label, name, { type: "date", value });
const memo = (label, name, maxLength, value) => field(label, name, { multiline: true, maxLength, value, wide: true });
const number = (label, name, bounds) => field(label, name, { type: "number", ...bounds });
const isHr = (company) => company.roles.includes("hr");

export const performancePages = [{
    id: "performance",
    label: "绩效周期",
    allow: (company) => company.archive || company.roles.some((role) => ["hr", "manager", "employee"].includes(role)),
    load: performance,
}];

async function performance(ctx, route = {}) {
    const root = ctx.base + "/performance/cycles";
    if (route.participant) return participant(ctx, route, root + "/" + route.cycle + "/participants/" + route.participant);
    if (route.cycle) return period(ctx, route, root + "/" + route.cycle);

    const results = [await ctx.list("可查看的绩效周期", root, "cycles",
        ["周期编号", "名称", "开始日", "截止日（不含）", "阶段", "操作"],
        (row) => [row.reference, row.title, row.starts_on, row.ends_before, phases[row.state],
            button("查看周期", () => ctx.go({ cycle: row.id }))], "当前没有可查看的绩效周期。")];
    if (isHr(ctx.company)) {
        const create = ctx.form([
            reference("周期编号", "reference"), field("周期名称", "title", { maxLength: 160 }),
            date("周期开始日", "starts_on"), date("周期截止日（不含）", "ends_before"),
        ], [["create", "创建绩效周期"]], (_, body, key) => ctx.request(root, body, key),
        (response) => ctx.go({ cycle: response.cycle_id }, "绩效周期已创建。"));
        results.push(panel("新增绩效周期", create));
    }
    return results;
}

function cycleFacts(cycle) {
    return facts([["周期编号", cycle.reference], ["开始日", cycle.starts_on], ["截止日（不含）", cycle.ends_before],
        ["阶段", phases[cycle.state]], ["周期版本", cycle.version], ["定稿时间", cycle.finalized_at]]);
}

async function period(ctx, route, path) {
    const initial = await ctx.request(path);
    const { cycle } = initial;
    const roster = await ctx.list("参加人", path, "participants",
        ["工号", "员工", "任职编号", "归属日", "目标确认", "操作"],
        (item) => [item.employee_code, item.employee_name, item.employment_id, item.scope_on,
            item.goal_confirmed_at ? "已确认" : "待确认", button("查看绩效记录", () => ctx.go({ ...route, participant: item.id }))],
        "当前没有可查看的参加人。", { first: initial, accept: ({ cycle: current }) => current.version === cycle.version });
    if (ctx.current()) ctx.tools(button("返回绩效周期", () => ctx.go()));

    const results = [panel(cycle.title, cycleFacts(cycle)), roster];
    if (!isHr(ctx.company) || cycle.state === "finalized") return results;
    if (cycle.state === "goals") {
        const enroll = ctx.form([
            number("任职编号", "employment_id", { min: 1 }), date("绩效归属日", "scope_on", cycle.starts_on),
        ], [["enroll", "加入参加人"]], (_, body, key) => ctx.request(path + "/participants", body, key),
        (response) => ctx.go({ ...route, participant: response.participant_id }, "参加人已加入。"));
        results.push(panel("加入参加人", node("p", "按归属日记录生效任职、关联员工账号及直属主管。", "muted"), enroll));
    }
    const blockers = node("div");
    const advance = ctx.form([reference("阶段决定编号")], [["advance", nextPhase[cycle.state]]],
        (_, body, key) => {
            blockers.replaceChildren();
            return ctx.request(path + "/advance", { ...body, version: cycle.version }, key);
        },
        (response) => ctx.go(route, "周期已进入「" + phases[response.state] + "」。"),
        (error) => {
            if (error.code === "performance_cycle_incomplete") {
                blockers.replaceChildren(facts([["参加人数", error.details.participants], ["阶段待办", error.details.pending]]));
            }
        });
    results.push(panel("推进阶段", advance, blockers));
    return results;
}

async function participant(ctx, route, path) {
    const { cycle, participant: person, revisions, actions } = await ctx.request(path);
    if (ctx.current()) ctx.tools(button("返回本周期", () => ctx.go({ cycle: route.cycle })));
    const weight = (value) => (value / 100).toFixed(2) + "%";
    const confidential = isHr(ctx.company);
    const rationaleColumn = confidential ? ["校准或修订依据"] : [];
    const results = [
        panel(person.employee_name, facts([["工号", person.employee_code], ["任职编号", person.employment_id],
            ["周期", cycle.title], ["阶段", phases[cycle.state]], ["归属日", person.scope_on], ["参加人版本", person.version],
            ["目标确认时间", person.goal_confirmed_at]])),
        panel("绩效目标", table(["目标代码", "名称", "达成标准", "权重"],
            person.goals.map((item) => [item.code, item.title, item.target, weight(item.weight_bps)]), "暂无目标。")),
        panel("员工自评", node("p", person.self_assessment ?? "暂无自评。", person.self_assessment ? "detail-text" : "muted")),
        panel("评价与修订", table(["版本", "类型", "分数", "目标评分", "反馈", ...rationaleColumn, "决定编号", "登记时间"],
            revisions.map((item) => [item.revision, revisionKinds[item.kind], item.score,
                item.goal_scores?.map((goal) => goal.code + " " + goal.score).join("、"),
                item.feedback, ...(confidential ? [item.rationale] : []), item.decision_ref, item.recorded_at]), "当前没有可查看的评价修订。")),
    ];
    const submit = (action, body, key) => ctx.request(path + "/" + action, { ...body, version: person.version }, key);
    const updated = (message) => () => ctx.go(route, message);
    if (actions.includes("goals")) results.push(goalEditor(ctx, person.goals, submit, updated("绩效目标已保存。")));
    if (actions.includes("confirm-goals")) {
        results.push(panel("确认并冻结目标", ctx.form([reference("目标确认编号")], [["confirm-goals", "确认目标"]],
            submit, updated("目标已确认。"))));
    }
    if (actions.includes("self-assessment")) {
        results.push(panel("提交自评", ctx.form([memo("自评内容", "assessment", 2000, person.self_assessment)],
            [["self-assessment", "保存自评"]], submit, updated("员工自评已保存。"))));
    }
    if (actions.includes("evaluation")) results.push(managerEvaluation(ctx, person.goals, submit, route));
    const revisionAction = actions.find((action) => action === "amendment" || action === "calibration");
    if (revisionAction) results.push(hrRevision(ctx, revisionAction, revisions.at(-1), submit, route));
    return results;
}

function goalEditor(ctx, goals, submit, onSaved) {
    const editor = node("div", null, "wide");
    const add = button("新增一项目标", () => append({}));
    const rows = [];
    let nextIndex = 0;
    function updateControls() {
        add.disabled = rows.length >= 12;
        rows.forEach((row) => { row.remove.disabled = rows.length <= 1; });
    }
    function append(goal) {
        const index = ++nextIndex;
        const group = node("fieldset", null, "performance-goal");
        const layout = node("div", null, "form-grid goal-fields");
        const remove = button("移除此目标", () => {
            rows.splice(rows.findIndex((row) => row.index === index), 1);
            group.remove();
            updateControls();
        });
        const code = reference("目标代码", "code_" + index);
        code.querySelector("input").value = goal.code ?? "";
        layout.append(code, field("目标名称", "title_" + index, { maxLength: 160, value: goal.title }),
            number("权重（万分比）", "weight_" + index, { min: 1, max: 10000, value: goal.weight_bps }),
            memo("达成标准", "target_" + index, 400, goal.target), remove);
        group.append(node("legend", "目标 " + index), layout);
        editor.append(group);
        rows.push({ index, remove });
        updateControls();
    }
    (goals.length ? goals : [{}]).forEach(append);
    const controls = node("div", null, "actions");
    controls.append(add);
    const form = ctx.form([editor, controls], [["goals", "保存目标"]], (action, body, key) => {
        const list = rows.map(({ index }) => ({
            code: body["code_" + index], title: body["title_" + index],
            target: body["target_" + index], weight_bps: body["weight_" + index],
        }));
        return submit(action, { goals: list }, key);
    }, onSaved);
    return panel("编辑绩效目标", node("p", "填写 1 至 12 项，权重合计 10000。6000 表示 60%。", "muted"), form);
}

function managerEvaluation(ctx, goals, submit, route) {
    const form = ctx.form([
        ...goals.map((goal, index) => number(goal.code + " · " + goal.title + "评分", "score_" + index, { min: 0, max: 100 })),
        reference("评分决定编号"), memo("主管反馈", "feedback", 2000),
    ], [["evaluation", "提交主管评分"]], (action, body, key) => submit(action, {
        decision_ref: body.decision_ref, feedback: body.feedback,
        goal_scores: goals.map((goal, index) => ({ code: goal.code, score: body["score_" + index] })),
    }, key), (response) => ctx.go(route, "主管评分已登记，加权总分 " + response.score + "。"));
    return panel("主管评分", form);
}

function hrRevision(ctx, action, latest, submit, route) {
    const label = revisionKinds[action];
    const form = ctx.form([
        number(label + "分数", "score", { min: 0, max: 100, value: latest?.score }),
        reference(label + "决定编号"), memo(label + "反馈", "feedback", 2000),
        memo(label + "依据", "rationale", 1200),
    ], [[action, "登记" + label]], submit,
    (response) => ctx.go(route, "已登记" + label + "，版本 " + response.revision + "。"));
    return panel(label, form);
}

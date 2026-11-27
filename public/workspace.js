import { attachment, badge, button, empty, facts, field, money, node, panel, reference, statuses, table, today } from "./workspace-ui.js";
import { attendancePages } from "./attendance-workspace.js";
import { employmentPages } from "./employment-workspace.js";
import { payrollPages } from "./payroll-workspace.js";
import { performancePages } from "./performance-workspace.js";
import { talentPages } from "./talent-workspace.js";
import { workforcePages } from "./workforce-workspace.js";
import { recruitmentPages } from "./recruitment-workspace.js";

const $ = (id) => document.getElementById(id);
const view = $("view");
const toolbar = $("section-tools");
const state = { csrf: "", principal: null, company: null, page: "", revision: 0 };

const requestKinds = { employment_certificate: "任职证明", name_correction: "姓名更正", other: "其他人事服务" };
const roles = { hr: "人事", recruiter: "招聘专员", manager: "直属主管", payroll_maker: "薪资制单", payroll_reviewer: "薪资复核", employee: "员工" };
const taskNames = { HANDOVER: "业务交接", ACCESS: "权限与资源清点", EQUIPMENT: "设备归还确认", PAYROLL: "薪资结算确认" };
const blockerKinds = { task: "交接清单", employee_request: "员工申请", leave: "请假", shift: "排班", payslip_inquiry: "工资核对" };
const errors = {
    workforce_intake_closed: "此法人尚未启用人才规划，或正在停止受理新请求。",
    offer_or_opening_changed: "岗位或录用修订已变更，请重新预检。",
    move_revision_changed: "调任单已有新进展，请刷新详情。",
    move_budget_month_unfunded: "拟生效月尚未获得该岗位的预算额度。",
    source_assignment_changed: "原任职已变更，请重新核对调任。",
    employee_fte_exhausted: "生效后同时任职的全职当量超过上限。",
    move_contract_conflict: "合同日期、编号或当前确认状态与本次调任冲突。",
    receiving_contract_required: "请先由接收法人 HR 确认目标合同。",
    move_ready_to_apply: "此调任可以正常生效，请使用办理生效操作。",
    required_evidence_missing: "缺少生效日有效且已授权的必需技能凭据。",
    invalid_credentials: "集团代码、邮箱或密码有误。",
    offboarding_new_work_frozen: "离职交接期间暂停新增这类事项。",
    offboarding_clearance_incomplete: "仍有待处理事项，当前无法办结。",
    offboarding_cutoff_not_reached: "尚未到达离职生效日。",
    offboarding_conflict: "离职单已被更新，请刷新后核对最新版本。",
    employee_profile_not_found: "当前法人暂无与本账号关联的员工档案。",
    employee_request_requires_active_employment: "此任职当前无法提交新的服务申请。",
    employee_request_conflict: "申请状态已更新，请刷新详情后重试。",
    payroll_run_conflict: "账期版本已变化，请刷新并核对后重试。",
    payroll_delegation_conflict: "代办安排已变更或过期，请刷新后核对。",
    payroll_delegate_must_differ: "请选择另一名独立复核人。",
    payroll_delegate_required: "只有当前指定的代办人可以使用这项委托。",
    payroll_delegation_grantor_required: "只有委托人可以撤销该项安排。",
    payroll_reviewer_not_found: "该账号当前没有本法人的薪资复核权限。",
    payroll_trial_blocked: "账期存在阻断人员，或没有可送审的工资单。",
    payroll_trial_maker_required: "请由完成本次试算的制单人送审。",
    payroll_reviewer_must_differ: "本次试算需要由另一位薪资复核人处理。",
    payroll_period_incompatible_with_rule: "此规则只适用于有 30 个自然日的账期。",
    attendance_watermark_not_reached: "考勤水位尚未达到该编号，请刷新账期。",
    payroll_closure_mismatch: "封账摘要与当前数据不符，无法发布。",
    original_payroll_line_not_found: "未找到已封账的原工资分段，请核对工资单及行号。",
    payroll_adjustment_baseline_changed: "已有更新的补差记录，请填写最新一笔的补差编号。",
    payroll_adjustment_requires_later_period: "补差必须登记到原工资单之后的账期。",
    payroll_adjustment_decision_conflict: "此决定编号已用于不同的补差内容。",
    payroll_adjustments_require_new_trial: "已登记新的补差，请重新试算后送审。",
    payslip_inquiry_conflict: "该核对申请已改变，请刷新详情。",
    department_not_effective: "所选部门在生效日尚未启用。",
    position_not_found: "当前账号没有该岗位的操作权限。",
    position_revision_conflict: "岗位版本已更新，或生效日早于当前版本。请刷新后核对。",
    position_still_occupied: "拟撤岗日期后仍有任职或录用占用，请先处理明细。",
    position_inactive: "该岗位在所选日期不可用，请核对岗位的生效历史。",
    recruiter_not_found: "此账号在当前法人没有招聘专员角色。",
    requisition_not_open: "招聘申请尚未开放。",
    candidate_profile_mismatch: "候选人编号与姓名不一致，请核对已有身份。",
    state_conflict: "当前状态或版本已变化，请刷新后核对。",
    offer_state_conflict: "录用状态或版本已变化，请刷新后核对。",
    offer_decision_conflict: "此决定编号已被使用。",
    budget_not_found: "所选生效日没有有效编制预算。",
    budget_period_overlap: "编制预算与已有预算期间重叠。",
    empty_budget_period: "预算截止日须晚于开始日。",
    headcount_exhausted: "生效区间内编制不足，请核对岗位编制。",
    employee_already_assigned: "此员工已有相交的任职区间，请先核对任职记录。",
    assignment_interval_conflict: "调动日期与当前任职区间冲突，或档案版本已变化。请核对下方区间。",
    contract_revision_conflict: "合同版本已变化，或生效日与现有记录冲突。请刷新并核对合同版本。",
    contract_renewal_not_contiguous: "续签须从上一份合同的截止日开始。",
    same_position: "请选择与当前岗位不同的目标岗位。",
    same_company: "请选择其他法人作为接收方。",
    legal_transfer_requires_receiver: "该申请需要由接收法人的另一位 HR 处理，或已接收。",
    duplicate_business_key: "此业务编号已被使用，请核对记录。",
    invalid_instant: "日期时间须包含秒和时区偏移，例如 2026-04-15T22:00:00+08:00。",
    shift_must_start_on_workday: "班次开始时间与所选工作日不一致。",
    shift_overlap: "该员工已有时间重叠的班次。",
    attendance_version_conflict: "班次已更新，请刷新并核对最新版本。",
    attendance_anomaly_unresolved: "仍有未解决的打卡异常，暂时无法确认出勤。",
    attendance_period_incomplete: "实排班次与预计数不一致，或仍有班次待核对。",
    punch_event_conflict: "该设备的事件编号已有不同内容，请核对原始记录。",
    invalid_attendance_batch_json: "导入行须为 JSON 数组。",
    invalid_attendance_retry_selection: "请选择 1 至 25 个不同的失败行。",
    duplicate_attendance_import_row_ref: "一个批次内的行标识必须唯一。",
    leave_balance_insufficient: "可用假期分钟不足。",
    leave_state_conflict: "请假状态已更新，请刷新并核对。",
    leave_self_approval: "请由另一位有权限的人员审批。",
    approved_leave_cancellation_requires_review: "已批准的请假需由 HR 或直属主管销假。",
    invalid_performance_period: "绩效周期截止日须晚于开始日。",
    performance_scope_date_outside_cycle: "绩效归属日须在周期内。",
    performance_requires_employee_and_manager: "该日任职需有关联员工账号和有效直属主管。",
    performance_cycle_incomplete: "当前阶段仍有待办或尚未加入参加人。",
    performance_cycle_conflict: "周期版本已更新，请刷新核对阶段。",
    performance_cycle_phase_conflict: "当前绩效阶段已变化，请刷新页面。",
    performance_participant_conflict: "绩效记录已更新，请刷新后再编辑。",
    performance_goals_already_confirmed: "目标已确认，当前无法编辑。",
    performance_goals_required: "请先登记至少一项目标。",
    invalid_performance_goal_list: "请输入 1 至 12 项绩效目标。",
    duplicate_performance_goal: "每项目标代码应唯一。",
    performance_goal_weights_must_total_10000: "绩效目标权重合计须为 10000。",
    performance_goal_scores_must_match: "主管评分须覆盖已确认的全部目标。",
    performance_evaluation_already_submitted: "主管评分已提交，员工自评已冻结。",
    performance_independent_calibrator_required: "此记录需要由与参加人及评分人不同的 HR 处理。",
    performance_revision_conflict: "已登记本阶段评价，请刷新核对修订记录。",
    invalid_csrf_token: "操作会话已过期，请刷新页面后重试。",
    login_required: "登录已失效，请重新登录。",
    inactive_session: "当前账号会话已失效，请重新登录。",
    company_not_found: "当前账号没有该法人的操作权限。",
    request_failed: "服务暂时无法处理请求，请稍后重试。",
};

function notice(text, isError = false) {
    $("notice").classList.toggle("error", isError);
    $("notice").textContent = text;
    $("notice").hidden = !text;
}

class ApiError extends Error {
    constructor(response, data) {
        super(errors[data.error] || (response.status === 404 ? "记录不可见或已不存在。" : "操作未完成，请核对数据后重试。"));
        this.status = response.status;
        this.code = data.error;
        this.details = data.details;
    }
}

async function api(path, body, key) {
    const headers = { Accept: "application/json" };
    const options = { headers };
    if (body !== undefined) {
        options.method = "POST";
        options.body = JSON.stringify(body);
        headers["Content-Type"] = "application/json";
        headers["X-CSRF-Token"] = state.csrf;
        if (key) headers["Idempotency-Key"] = key;
    }
    const response = await fetch(path, options);
    const data = await response.json();
    if (!response.ok) throw new ApiError(response, data);
    if (data.csrf_token) state.csrf = data.csrf_token;
    return data;
}

function operation(ctx, fields, buttons, submit, onSuccess, onError) {
    const form = node("form", null, "form-grid");
    const actions = node("div", null, "actions");
    const feedback = node("p", null, "alert wide");
    feedback.hidden = true;
    feedback.setAttribute("role", "alert");
    for (const [value, text, secondary] of buttons) {
        const control = node("button", text, secondary ? "subtle danger" : "");
        control.type = "submit";
        control.value = value;
        actions.append(control);
    }
    form.append(...fields, feedback, actions);
    let lastRequest;
    let lastKey;
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const body = Object.fromEntries(new FormData(form));
        const action = event.submitter?.value ?? buttons[0][0];
        const signature = body.password === undefined ? JSON.stringify([action, body]) : null;
        if (signature !== lastRequest) lastKey = newRequestKey();
        lastRequest = signature;
        const controls = Array.from(form.elements).filter((control) => !control.disabled);
        controls.forEach((control) => { control.disabled = true; });
        feedback.hidden = true;
        try {
            const result = await submit(action, body, lastKey);
            if (ctx.current()) await onSuccess(result, action);
        } catch (error) {
            if (ctx.current() && !sessionExpired(error)) {
                feedback.textContent = error instanceof ApiError ? error.message
                    : errors[error.code] || "操作未完成，请检查输入或连接后重试。";
                feedback.hidden = false;
                if (onError) onError(error);
            }
        } finally {
            controls.forEach((control) => { control.disabled = false; });
        }
    });
    return form;
}

function newRequestKey() {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function sessionExpired(error) {
    if (error.status !== 401 || !state.principal) return false;
    state.csrf = "";
    showLogin(error.message);
    return true;
}

async function paged(ctx, title, path, property, headers, cells, emptyText, options = {}) {
    const results = [];
    const container = panel(title);
    const records = node("div");
    container.append(records);
    const more = button("加载更多", async () => {
        more.disabled = true;
        try {
            await load();
        } catch (error) {
            if (ctx.current() && !sessionExpired(error)) notice(error.message, true);
        } finally {
            more.disabled = false;
        }
    });
    more.hidden = true;
    const footer = node("div", null, "actions pagination");
    footer.append(more);
    container.append(footer);
    async function load(initial) {
        const params = new URLSearchParams();
        if (results.length) params.set("after", results.at(-1).id);
        const separator = path.includes("?") ? "&" : "?";
        const response = initial ?? await api(params.size ? `${path}${separator}${params}` : path);
        if (!ctx.current()) return;
        if (options.accept && !options.accept(response)) throw new Error("数据版本已变化，请刷新页面后再加载。");
        results.push(...response[property]);
        records.replaceChildren(table(headers, results.map(cells), emptyText));
        more.hidden = response[property].length < 100;
        footer.hidden = more.hidden;
    }
    await load(options.first);
    return container;
}

const pages = [
    ...employmentPages,
    ...recruitmentPages,
    ...workforcePages,
    ...talentPages,
    ...payrollPages,
    { id: "offboarding", label: "离职交接", allow: (c) => c.archive || c.roles.some((role) => ["hr", "manager", "employee", "payroll_reviewer"].includes(role)), load: departures },
    { id: "employee-review", label: "员工申请审核", allow: (c) => c.roles.includes("hr"), load: (ctx, id) => requests(ctx, id, true) },
    { id: "profile", label: "我的档案", allow: (c) => c.archive || c.roles.includes("employee"), load: profile },
    { id: "payslips", label: "我的工资单", allow: (c) => c.archive || c.roles.includes("employee"), load: payslips },
    { id: "requests", label: "我的申请", allow: (c) => c.archive || c.roles.includes("employee"), load: requests },
    ...attendancePages,
    ...performancePages,
    { id: "administration", label: "集团设置", global: true, load: administration },
];

function availablePages() {
    return pages.filter((page) => page.global
        ? state.principal.group_admin
        : state.company && page.allow(state.company));
}

function showLogin(message = "") {
    state.principal = null;
    state.company = null;
    state.page = "";
    state.revision++;
    view.replaceChildren();
    toolbar.replaceChildren();
    notice("");
    $("company").replaceChildren();
    $("principal").textContent = "";
    $("group-name").textContent = "";
    $("sections").replaceChildren();
    $("login-error").textContent = message;
    $("login-error").hidden = !message;
    $("session-tools").hidden = true;
    $("workspace").hidden = true;
    $("entrance").hidden = false;
}

async function openWorkspace(message = "") {
    const selected = $("company").value;
    const principal = await api("/api/workspace");
    state.principal = principal;
    $("principal").textContent = principal.display_name;
    $("group-name").textContent = principal.group_name;
    const select = $("company");
    select.replaceChildren(...principal.companies.map((company) => new Option(company.name, company.id)));
    if (principal.companies.some((company) => String(company.id) === selected)) select.value = selected;
    select.disabled = !principal.companies.length;
    $("session-tools").hidden = false;
    $("entrance").hidden = true;
    $("workspace").hidden = false;
    selectCompany(message);
}

function selectCompany(message = "") {
    state.company = state.principal.companies.find((company) => String(company.id) === $("company").value);
    const available = availablePages();
    $("sections").replaceChildren(...available.map((page) => {
        const link = button(page.label, () => open(page.id));
        link.dataset.page = page.id;
        return link;
    }));
    if (available.length) {
        open(available.find((page) => page.id === state.page)?.id ?? available[0].id, undefined, message);
    } else {
        ++state.revision;
        state.page = "";
        toolbar.replaceChildren();
        notice("");
        $("section-title").textContent = "人力资源工作台";
        view.replaceChildren(panel(null, empty("当前账号暂无已授权的工作台模块。")));
    }
}

async function open(id, record, message) {
    const company = state.company;
    const page = availablePages().find((item) => item.id === id);
    if (!page) return;
    const revision = ++state.revision;
    const ctx = {
        accountId: state.principal.account_id,
        company,
        base: company ? `/api/companies/${company.id}` : null,
        request: api,
        notice,
        current: () => state.revision === revision,
        go: (recordId, feedback) => open(id, recordId, feedback),
        navigate: open,
        tools: (...controls) => toolbar.prepend(...controls),
        form: (...args) => operation(ctx, ...args),
        list: (...args) => paged(ctx, ...args),
    };
    state.page = id;
    for (const link of $("sections").children) {
        if (link.dataset.page === id) link.setAttribute("aria-current", "page");
        else link.removeAttribute("aria-current");
    }
    $("section-title").textContent = page.label;
    toolbar.replaceChildren(button("刷新", () => open(id, record)));
    notice(message);
    view.replaceChildren(empty("正在加载…"));
    try {
        const content = await page.load(ctx, record);
        if (ctx.current()) view.replaceChildren(...content);
    } catch (error) {
        if (ctx.current() && !sessionExpired(error)) {
            view.replaceChildren(panel(null, empty(error instanceof ApiError ? error.message : "加载失败，请检查连接后刷新。")));
        }
    }
}

function hr(ctx) {
    return ctx.company.roles.includes("hr");
}

async function administration(ctx) {
    const companies = state.principal.companies;
    const addCompany = operation(ctx, [
        reference("法人代码", "code"), field("法人全称", "name", { maxLength: 160 }),
        field("业务时区", "timezone", { value: "Asia/Shanghai", maxLength: 64 }),
        field("记账币种", "currency", { value: "CNY", maxLength: 3, pattern: "[A-Z]{3}" }),
    ], [["add", "创建法人"]], (_, body) => api("/api/companies", body),
    () => openWorkspace("法人已创建。"));
    const addAccount = operation(ctx, [
        field("显示名称", "display_name", { maxLength: 160 }),
        field("邮箱", "email", { type: "email", maxLength: 254 }),
        field("初始密码", "password", { type: "password", maxLength: 72, hint: "至少 12 字节" }),
    ], [["add", "创建账号"]], (_, body) => api("/api/accounts", body),
    (result) => {
        const password = addAccount.elements.namedItem("password");
        password.value = "";
        notice(`账号已创建，编号为 ${result.account_id}。`);
    });
    const results = [
        panel("集团法人", table(["编号", "代码", "法人", "时区", "币种"], companies.map((row) => [row.id, row.code, row.name, row.timezone, row.currency]))),
        panel("新增法人", addCompany), panel("新增账号", addAccount),
    ];
    if (companies.length) {
        const grant = operation(ctx, [
            field("法人", "company_id", { choices: companies.map((row) => [row.id, row.name]), value: ctx.company?.id }),
            field("账号编号", "account_id", { type: "number", min: 1 }),
            field("业务角色", "role", { choices: Object.entries(roles) }),
        ], [["grant", "授予法人权限"]], (_, { company_id, ...body }) => api(`/api/companies/${company_id}/roles`, body),
        () => openWorkspace("法人角色已授予。"));
        results.push(panel("法人角色授权", grant));
    }
    return results;
}

function blockerTable(items) {
    return table(["类型", "事项", "负责人"], items.map((row) => [
        blockerKinds[row.kind] ?? row.kind, row.code ? (taskNames[row.code] ?? row.label) : `#${row.id}${row.workday ? " · " + row.workday : ""}`,
        roles[row.owner_role] ?? "—",
    ]), "当前没有阻塞办结的事项。");
}

async function departures(ctx, caseId) {
    const source = ctx.base + "/offboarding";
    if (!caseId) {
        return [await paged(ctx, "可办理或查看的离职单", source, "cases", ["单号", "任职编号", "生效日", "状态", "版本", "操作"],
            (row) => [row.reference, row.employment_id, row.ends_before, badge(row.state), row.version, button("查看离职单", () => ctx.go(row.id))])];
    }
    const path = `${source}/${caseId}`;
    const data = await api(path);
    if (!ctx.current()) return [];
    toolbar.prepend(button("返回离职列表", () => ctx.go()));
    const record = data.case;
    const active = record.state === "in_progress";
    const details = [["单号", record.reference], ["任职编号", record.employment_id], ["生效日", record.ends_before],
        ["状态", statuses[record.state]], ["版本", record.version]];
    if (record.reason) details.push(["离职事由", record.reason]);
    if (record.decision_ref) details.push(["决定编号", record.decision_ref]);
    const tasks = panel("交接清单", table(["事项", "责任角色", "进度", "处理依据"],
        data.tasks.map((task) => [taskNames[task.code] ?? task.label, roles[task.owner_role] ?? task.owner_role,
            task.confirmed_at ? "已确认" : "待确认", task.decision_ref]), "当前没有可查看的交接事项。"));
    const signedIn = state.principal.account_id;
    for (const task of data.tasks) {
        if (!active || task.confirmed_at || record.employee_account_id === signedIn) continue;
        const canConfirm = task.owner_role === "manager"
            ? hr(ctx) || task.owner_account_id === signedIn
            : ctx.company.roles.includes(task.owner_role) && (hr(ctx) || task.evidence === null);
        if (!canConfirm) continue;
        const form = operation(ctx, [reference("处理编号"), field("处理依据", "evidence", { maxLength: 1200 })],
            [["confirm", "确认事项"]], (_, body, key) =>
                api(`${path}/tasks/${encodeURIComponent(task.code)}/confirm`, { ...body, version: record.version }, key),
            () => ctx.go(caseId, "交接事项已确认。"));
        const wrapper = node("section", null, "task-form");
        wrapper.append(node("h3", taskNames[task.code] ?? task.label), form);
        tasks.append(wrapper);
    }
    const result = [panel("离职单", facts(details)), tasks];
    if (hr(ctx) && active) {
        const blockers = node("div");
        blockers.append(blockerTable(data.blockers));
        result.push(panel("待处理事项", blockers));
        const decision = operation(ctx, [reference("决定编号")], [["complete", "办结离职"], ["cancel", "取消离职", true]],
            (action, body, key) => api(`${path}/${action}`, { ...body, version: record.version }, key),
            (_, action) => ctx.go(caseId, action === "complete" ? "离职已办结，该法人业务权限已收回。" : "离职单已取消。"),
            (error) => {
                if (error.code === "offboarding_clearance_incomplete") blockers.replaceChildren(blockerTable(error.details.blockers));
            });
        if (record.employee_account_id !== signedIn) result.push(panel("人事决定", decision));
    }
    return result;
}

async function profile(ctx, date) {
    const day = date ?? today(ctx.company);
    const data = await api(`${ctx.base}/employee/me?on=${encodeURIComponent(day)}`);
    if (!ctx.current()) return [];
    const picker = node("form");
    const choice = field("任职查询日", "on", { type: "date", value: day });
    const search = node("button", "查询");
    picker.append(choice, search);
    picker.className = "actions date-picker";
    picker.addEventListener("submit", (event) => {
        event.preventDefault();
        ctx.go(new FormData(picker).get("on"));
    });
    toolbar.prepend(picker);
    return [
        panel(data.employee.display_name, facts([["工号", data.employee.employee_code], ["查询日", data.as_of]])),
        panel("任职资料", table(["任职编号", "任职起始", "任职截至", "当日部门", "当日岗位"],
            data.employments.map((row) => [row.employment_id, row.starts_on, row.ends_before, row.department_name, row.position_title]))),
    ];
}

async function payslips(ctx, slipId) {
    const source = ctx.base + "/payroll/my-payslips";
    const amount = (minor) => money(ctx.company, minor);
    if (!slipId) return [await paged(ctx, "已发布工资单", source, "payslips", ["账期", "应发", "扣缴", "实付", "操作"],
        (row) => [row.period_start.slice(0, 7), amount(row.gross_minor), amount(row.withholding_minor), amount(row.payable_minor),
            button("查看工资单", () => ctx.go(row.id))])];
    const path = `${source}/${slipId}`;
    const [data, queue] = await Promise.all([api(path), api(path + "/inquiries")]);
    if (!ctx.current()) return [];
    toolbar.prepend(button("返回工资单列表", () => ctx.go()), attachment("下载工资单", path + ".csv"));
    const slip = data.payslip;
    const inquiryPanel = panel("工资核对", table(["编号", "疑问", "答复", "状态", "操作"],
        queue.inquiries.map((item) => [item.reference, item.question, item.answer, badge(item.state),
            item.state === "answered" && ctx.company.roles.includes("employee")
                ? button("确认收到答复", async (event) => {
                    const control = event.currentTarget;
                    control.disabled = true;
                    try {
                        await api(`${ctx.base}/payroll/inquiries/${item.id}/acknowledge`, { version: item.version }, newRequestKey());
                        if (ctx.current()) ctx.go(slipId, "已确认收到答复。");
                    } catch (error) {
                        if (ctx.current() && !sessionExpired(error)) notice(error.message, true);
                    } finally {
                        control.disabled = false;
                    }
                }) : "—",
        ]), "暂无工资核对记录。"));
    if (ctx.company.roles.includes("employee")) {
        const kinds = Object.entries({ base_pay: "基本工资", attendance: "考勤", withholding: "扣缴", adjustment: "补差", other: "其他" });
        const form = operation(ctx, [reference("核对编号", "reference"), field("类别", "category", { choices: kinds }),
            field("请描述疑问", "question", { multiline: true, maxLength: 1600, wide: true })],
        [["open", "提交核对"]], (_, body, key) => api(path + "/inquiries", body, key),
        () => ctx.go(slipId, "工资核对已提交。"));
        inquiryPanel.append(form);
    }
    return [
        panel(`${data.period_start.slice(0, 7)} 工资单`, facts([["应发金额", amount(slip.gross_minor)],
            ["扣缴金额", amount(slip.withholding_minor)], ["实付金额", amount(slip.payable_minor)], ["封账摘要", data.digest]])),
        panel("任职分段", table(["起始日期", "截止日期", "部门编号", "基本工资", "津贴"],
            data.segments.map((item) => [item.starts_on, item.ends_before, item.department_id, amount(item.base_minor), amount(item.allowance_minor)]))),
        panel("补差明细", table(["决定编号", "原工资单", "差额", "实付差额"],
            data.adjustments.map((item) => [item.decision_ref, item.original_payslip_id, amount(item.gross_delta_minor), amount(item.payable_delta_minor)]))),
        inquiryPanel,
    ];
}

async function requests(ctx, requestId, review = false) {
    const base = ctx.base + "/employee";
    if (!requestId) {
        const results = [await paged(ctx, review ? "待审核申请" : "个人申请记录",
            review ? base + "/review-queue?state=pending" : base + "/requests", "requests",
            ["单号", "类型", "状态", "版本", "操作"],
            (row) => [row.reference, requestKinds[row.kind], badge(row.state), row.version, button("查看申请", () => ctx.go(row.id))])];
        if (review || !ctx.company.roles.includes("employee") || !ctx.current()) return results;
        let profileData;
        try {
            profileData = await api(base + "/me");
        } catch (error) {
            if (error.code === "employee_profile_not_found") return results;
            throw error;
        }
        const employments = profileData.employments.filter((row) =>
            row.starts_on <= profileData.as_of && (!row.ends_before || row.ends_before > profileData.as_of));
        if (!employments.length) return results;
        const kind = field("申请事项", "kind", { choices: Object.entries(requestKinds) });
        const name = field("申请更正为", "requested_name", { maxLength: 160, required: false });
        name.hidden = true;
        kind.firstElementChild.addEventListener("change", (event) => {
            name.hidden = event.target.value !== "name_correction";
            name.firstElementChild.required = !name.hidden;
        });
        const form = operation(ctx, [kind, reference("申请编号", "reference"),
            field("关联任职", "employment_id", { choices: employments.map((row) => [row.employment_id, row.position_title ?? row.employment_id]) }),
            name, field("申请说明", "details", { multiline: true, maxLength: 1600, wide: true })],
        [["submit", "提交申请"]], (_, body, key) => api(base + "/requests", body, key),
        (result) => ctx.go(result.request_id, "员工申请已提交。"));
        return [...results, panel("发起员工申请", form)];
    }
    const path = `${base}/requests/${requestId}`;
    const data = await api(path);
    if (!ctx.current()) return [];
    toolbar.prepend(button("返回申请列表", () => ctx.go()));
    const panels = [panel("申请详情", facts([["单号", data.reference], ["类别", requestKinds[data.kind]], ["状态", statuses[data.state]],
        ["版本", data.version], ["申请说明", data.details], ["处理意见", data.response], ["决定编号", data.decision_ref]]))];
    if (data.outcome?.employer) {
        panels.push(panel("任职证明", facts([["员工", data.outcome.employee_name], ["法人", data.outcome.employer],
            ["任职起始", data.outcome.starts_on], ["任职截至", data.outcome.ends_before], ["出具日期", data.outcome.issued_on]])));
    }
    if (data.outcome?.display_name) panels.push(panel("姓名更正结果", facts([["原姓名", data.outcome.previous_name], ["更正后", data.outcome.display_name]])));
    if (data.state !== "pending") return panels;
    const actions = review ? [["approve", "通过"], ["reject", "驳回", true]] : [["withdraw", "撤回申请", true]];
    if (!review && !ctx.company.roles.includes("employee")) return panels;
    const form = operation(ctx, [reference("决定编号"), field("处理意见", "response", { maxLength: 1200 })],
        actions, (action, body, key) => api(`${path}/${action}`, { ...body, version: data.version }, key),
        () => ctx.go(requestId, "申请状态已更新。"));
    return [...panels, panel("处理申请", form)];
}

$("login").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const control = form.querySelector("button");
    control.disabled = true;
    try {
        if (!state.csrf) await api("/api/session");
        await api("/api/login", Object.fromEntries(new FormData(form)));
        form.reset();
        await openWorkspace();
    } catch (error) {
        showLogin(error instanceof ApiError ? error.message : "无法连接服务，请稍后重试。");
    } finally {
        control.disabled = false;
    }
});

$("logout").addEventListener("click", async (event) => {
    const control = event.currentTarget;
    control.disabled = true;
    try {
        await api("/api/logout", {});
        showLogin();
    } catch (error) {
        if (!sessionExpired(error)) notice("暂时无法退出，请重试。", true);
    } finally {
        control.disabled = false;
    }
});
$("company").addEventListener("change", () => selectCompany());

try {
    const session = await api("/api/session");
    if (session.authenticated) await openWorkspace();
} catch (error) {
    showLogin(error instanceof ApiError ? error.message : "无法连接服务，请稍后重试。");
}

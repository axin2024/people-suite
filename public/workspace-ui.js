export const statuses = {
    in_progress: "办理中", completed: "已办结", cancelled: "已取消", pending: "待审核",
    approved: "已通过", rejected: "未通过", withdrawn: "已撤回",
    open: "待答复", answered: "已答复", acknowledged: "已确认",
    draft: "草稿", calculated: "已试算", in_review: "待复核", closed: "已封账", published: "已发布",
    ready: "就绪", blocked: "阻断",
};

export function node(tag, text, className) {
    const item = document.createElement(tag);
    if (text != null) item.textContent = text;
    if (className) item.className = className;
    return item;
}

export function button(text, action, className = "subtle") {
    const element = node("button", text, className);
    element.type = "button";
    element.addEventListener("click", action);
    return element;
}

export function attachment(text, path) {
    const link = node("a", text, "button subtle");
    link.href = path;
    return link;
}

export function panel(title, ...items) {
    const section = node("section", null, "panel");
    if (title) section.append(node("h2", title));
    section.append(...items);
    return section;
}

export function empty(text) {
    return node("p", text, "empty");
}

export function badge(value) {
    return node("span", statuses[value] ?? value, "badge");
}

export function facts(values) {
    const list = node("dl");
    for (const [label, value] of values) {
        const pair = node("div");
        pair.append(node("dt", label), node("dd", value ?? "—"));
        list.append(pair);
    }
    return list;
}

export function table(columns, rows, emptyText = "暂无记录") {
    if (!rows.length) return empty(emptyText);
    const scroll = node("div", null, "table");
    const grid = node("table");
    const heading = grid.createTHead().insertRow();
    for (const name of columns) {
        const field = node("th", name);
        field.scope = "col";
        heading.append(field);
    }
    const body = grid.createTBody();
    for (const row of rows) {
        const item = body.insertRow();
        for (const value of row) {
            const field = item.insertCell();
            if (value instanceof Node) field.append(value);
            else field.textContent = value ?? "—";
        }
    }
    scroll.append(grid);
    return scroll;
}

export function field(label, name, options = {}) {
    const wrapper = node("label", label, options.wide ? "wide" : "");
    const input = node(options.choices ? "select" : options.multiline ? "textarea" : "input");
    if (options.choices) {
        for (const [value, title] of options.choices) input.append(new Option(title, value));
    } else if (!options.multiline) {
        input.type = options.type ?? "text";
    }
    input.name = name;
    input.required = options.required ?? true;
    if (options.value != null) input.value = options.value;
    if (options.maxLength) input.maxLength = options.maxLength;
    if (options.pattern) input.pattern = options.pattern;
    if (options.hint) input.placeholder = options.hint;
    if (options.min != null) input.min = options.min;
    if (options.max != null) input.max = options.max;
    if (options.list) input.setAttribute("list", options.list);
    wrapper.append(input);
    return wrapper;
}

export function reference(label, name = "decision_ref") {
    return field(label, name, { maxLength: 48, pattern: "[A-Za-z0-9][A-Za-z0-9._\\-]{0,47}", hint: "例如 HR-2026-001" });
}

export function today(company) {
    const parts = new Intl.DateTimeFormat("en-CA", {
        timeZone: company.timezone, year: "numeric", month: "2-digit", day: "2-digit",
    }).formatToParts();
    const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
    return `${values.year}-${values.month}-${values.day}`;
}

export function money(company, minor) {
    if (minor == null) return "—";
    const format = new Intl.NumberFormat("zh-CN", { style: "currency", currency: company.currency });
    const scale = 10 ** format.resolvedOptions().maximumFractionDigits;
    return format.format(minor / scale);
}

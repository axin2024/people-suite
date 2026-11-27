import { button, facts, field, money, panel, reference, table, today } from "./workspace-ui.js";

const has = (company, ...roles) => roles.some((role) => company.roles.includes(role));
const number = (label,name,value,min=1,max) => field(label,name,{type:"number",value,min,max});
const states = {submitted:"等待人力复核",reviewed:"等待财务审批",adjustment:"待调整",approved:"预算已锁定"};
const fte = (bp) => (Number(bp)/10000).toLocaleString("zh-CN",{maximumFractionDigits:4});
const note = () => field("办理意见","reason",{wide:true,multiline:true,maxLength:400});
const month = (value) => field("起始月份","start_month",{type:"month",value:value?.slice(0,7)});
const asDate = (body) => ({...body,start_month:body.start_month+"-01"});
const terms = (r={}) => [month(r.start_month),number("连续月数","months",r.months??6,1,12),
    number("全职当量（万分比）","fte_bp",r.fte_bp??10000,1,10000),
    number("每月成本（货币最小单位）","monthly_minor",r.monthly_minor,0),field("职级","grade",{value:r.grade})];

export const workforcePages = [
    {id:"planning",label:"年度编制情景",allow:(c)=>has(c,"hr","payroll_reviewer"),load:planning},
    {id:"position-budget",label:"部门岗位预算",allow:(c)=>has(c,"hr","manager","payroll_reviewer"),load:budgets},
    {id:"monthly-mobility",label:"月度岗位流动",allow:(c)=>has(c,"hr","payroll_reviewer"),load:movementReport},
];

async function movementReport(ctx,route={}) {
    const period=route?.period??today(ctx.company).slice(0,7);
    const result=await ctx.request(ctx.base+"/workforce/movements?period="+encodeURIComponent(period+"-01"));
    const select=ctx.form([field("报告月份","period",{type:"month",value:period})],[["view","查看月份"]],async (_,b)=>b.period,p=>ctx.go({period:p}));
    return [panel("报告月份",select,facts([["数据截至",result.as_of]])),
        panel("实际任职变动",table(["部门","内部到岗","外部入职","原任职结束","内部填补率","内部岗位空缺天数"],result.departments.map(r=>
            [r.department,r.internal_fills,r.external_fills,r.assignments_ended,r.internal_fill_rate==null?"—":(r.internal_fill_rate*100).toFixed(2)+"%",r.vacant_days]))),
        panel("空缺天数明细",table(["岗位","空缺起日","截止日（不含）","本月天数"],result.vacancies.map(r=>[r.title,r.from,r.ends_before,r.vacant_days])))];
}

async function planning(ctx, route={}) {
    const url=ctx.base+"/workforce/scenarios";
    if (route?.id) {
        const plan=await ctx.request(url+"/"+route.id);
        const actual=await ctx.request(url+"/"+route.id+"/variance");
        const other=route.compare ? await ctx.request(url+"/"+route.compare) : null;
        const key=(row)=>row.department_id+"/"+row.period_start;
        const comparison=new Map(other?.months.map((r)=>[key(r),r]));
        const planned=new Map(plan.months.map((r)=>[key(r),r]));
        const rows=[...new Set([...planned.keys(),...comparison.keys()])].sort().map((k)=>{
            const a=planned.get(k), b=comparison.get(k), r=a??b;
            return [r.department_id,r.period_start,fte(a?.monthly_fte_bp??0),money(ctx.company,a?.cost_minor??0),
                ...(other?[fte((a?.monthly_fte_bp??0)-(b?.monthly_fte_bp??0)),money(ctx.company,(a?.cost_minor??0)-(b?.cost_minor??0))]:[])];
        });
        const compare=ctx.form([number("同年度对比情景编号","id",route.compare)],[["compare","比较"]],
            async (_,body)=>{const b=await ctx.request(url+"/"+body.id);if (b.plan_year!==plan.plan_year) throw new Error("请选择同年度情景。");return b;},
            (b)=>ctx.go({id:plan.scenario_id,compare:b.scenario_id}));
        return [panel(plan.name,facts([["情景编号",plan.scenario_id],["年度",plan.plan_year],["修订",plan.revision],["对比情景",other?.name]]),
            compare,button("返回情景目录",()=>ctx.go({}))),panel("月末编制与月度成本",table(["部门编号","月份","全职当量","计划成本",...(other?["当量差","成本差"]:[])],rows)),
            panel("计划与实际任职、封账成本",table(["部门","月份","预算岗位","已任职","空缺","实际减计划（FTE）","已封账成本","成本偏差","工资批次"],
                actual.months.map(r=>[r.department,r.period_start,fte(r.capacity_bp),fte(r.occupied_bp),fte(r.vacant_bp),fte(r.variance_fte_bp),
                    money(ctx.company,r.closed_cost_minor),money(ctx.company,r.variance_cost_minor),r.payroll_run_id])))];
    }
    const result=[await ctx.list("已发布情景",url,"scenarios",["编号","名称","年度","修订",""],r=>
        [r.id,r.name,r.plan_year,r.revision,button("查看与比较",()=>ctx.go({id:r.id}))],"尚未发布年度情景。")];
    if (has(ctx.company,"hr")) {
        const data=ctx.form([reference("情景代码","code"),field("情景名称","name"),number("年度","year",today(ctx.company).slice(0,4),2000,2199),
            number("上一修订（首次为 0）","previous_revision",0,0),
            field("成本基线 CSV（含表头）","csv",{wide:true,multiline:true,hint:"reference,department_id,effective_from,ends_before,fte_bp,monthly_cost_minor"})],
            [["save","发布成本快照"]],(_,body,key)=>ctx.request(url,body,key),r=>ctx.go({id:r.scenario_id},"已保存本次成本与编制。"));
        result.push(panel("导入年度成本基线",data));
    }
    return result;
}

async function budgets(ctx, route={}) {
    const url=ctx.base+"/workforce/budgets";
    if (route?.id) {
        const {request:r,history,reservations}=await ctx.request(url+"/"+route.id);
        const panels=[panel("预算单 #"+r.id,facts([["当前状态",states[r.state]],["部门 / 岗位",r.department_id+" / "+r.position_id],
            ["职级",r.grade],["申请全职当量",fte(r.fte_bp)],["月度成本",money(ctx.company,r.monthly_minor)]]),button("返回列表",()=>ctx.go({})))];
        const operation=(label,fields,actions,make)=>panels.push(panel(label,ctx.form(fields,actions,(action,body,key)=>
            ctx.request(url+"/"+r.id+"/"+action,{...make(body),budget_rev:r.revision},key),()=>ctx.go({id:r.id},"办理结果已保存。"))));
        if (r.state==="submitted"&&has(ctx.company,"hr")) operation("人力复核",[field("核定职级","grade",{value:r.grade}),note()],[["review","确认职级"]],b=>b);
        if (r.state==="reviewed"&&has(ctx.company,"payroll_reviewer")) operation("财务办理",[note()],[["approve","按月锁定额度"],["return","退回调整",true]],b=>b);
        if (r.state==="adjustment"&&has(ctx.company,"manager")) operation("调整测算",[...terms(r),note()],[["revise","提交新测算"]],asDate);
        panels.push(panel("历次测算与决定",table(["修订","状态","职级","月度成本","月份","办理人","意见","额度不足月份"],
            history.map(({actor_id,snapshot:s})=>[s.budget_rev,states[s.state],s.grade,money(ctx.company,s.monthly_minor),s.start_month+" 起 "+s.months+" 个月",actor_id,s.reason,s.shortfall_month]))),
            panel("已预留额度",table(["月份","全职当量","金额"],reservations.map(v=>[v.period_start,fte(v.fte_bp),money(ctx.company,v.amount_minor)]))));
        return panels;
    }
    const data=await ctx.request(url);
    const options=data.departments.map(d=>[d.id,d.name]);
    const department=()=>field("部门","department_id",{choices:options});
    const result=[await ctx.list("岗位预算申请",url,"requests",["编号","部门","职级","起始月","状态",""],r=>
        [r.id,r.department,r.grade,r.start_month,states[r.state],button("办理",()=>ctx.go({id:r.id}))],"暂无可见申请。",{first:data})];
    if (!options.length) return result;
    if (has(ctx.company,"manager")) result.push(panel("申报岗位",ctx.form([department(),number("目标岗位编号","position_id"),...terms()],
        [["submit","提交申请"]],(_,b,key)=>ctx.request(url,asDate(b),key),r=>ctx.go({id:r.request_id}))));
    if (has(ctx.company,"hr")) result.push(panel("部门预算与调任签核权限",ctx.form([department(),number("经理账号编号","manager_id")],
        [["grant","授权"],["revoke","收回",true]],(a,b,key)=>ctx.request(ctx.base+"/workforce/budget-managers",{...b,granted:a==="grant"},key),()=>ctx.go({},"已更新部门权限。"))));
    if (has(ctx.company,"payroll_reviewer")) result.push(panel("月度预算上限",ctx.form([department(),month(),number("连续月数","months",12,1,12),
        number("每月全职当量上限（万分比）","capacity_bp",10000,0),number("每月成本上限（货币最小单位）","cost_cap_minor",undefined,0),
        number("各月上一修订","previous_revision",0,0)],[["set","设置额度"]],(_,b,key)=>ctx.request(ctx.base+"/workforce/budget-limits",asDate(b),key),()=>ctx.go({},"月度额度已保存。"))));
    return result;
}

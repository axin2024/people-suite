import { button, empty, facts, field, money, panel, table } from "./workspace-ui.js";

const has=(c,...roles)=>roles.some(role=>c.roles.includes(role));
const vis={private:"仅自己与指定评审",company:"本法人",group:"集团内部"};
const visField=(value="private")=>field("可见范围","visibility",{choices:Object.entries(vis),value});
const num=(label,name)=>field(label,name,{type:"number",min:1});
const notes=(label,name)=>field(label,name,{multiline:true,wide:true});
const labels={submitted:"待验证",verified:"已验证",rejected:"未验证",applied:"已投递",reviewed:"已评审",reserved:"待双签",
    scheduled:"待生效",transferred:"已调任",withdrawn:"已撤回",open:"接受投递",filled:"已到岗",closed:"已结束"};

export const talentPages=[
    {id:"skills",label:"技能与证据",allow:c=>has(c,"employee","hr"),load:skills},
    {id:"internal-jobs",label:"内部人才市场",allow:c=>has(c,"employee","manager","hr"),load:market},
    {id:"internal-moves",label:"内部调任",allow:c=>has(c,"employee","manager","hr"),load:moves},
];

const moveStates={awaiting_signatures:"待双方签核",scheduled:"待生效",effective:"已生效",withdrawn:"已撤回"};
const contractChecks={covered:"原合同覆盖生效日",source_contract_conflict:"原合同未覆盖生效日",receiving_confirmation_required:"须由接收法人确认合同"};

async function moves(ctx,route={}) {
    const url=ctx.base+"/talent";
    if (route?.move) return moveDetails(ctx,url+"/moves/"+route.move);
    if (route?.offer) {
        const preview=await ctx.request(url+"/offers/"+route.offer+"?effective_date="+encodeURIComponent(route.day));
        const reserve=ctx.form([],[["reserve","预留岗位并发起双签"]],(_,body,key)=>ctx.request("/internal-moves?company_id="+ctx.company.id,
            {application_id:preview.application_id,from_position:preview.from_position,to_position:preview.to_position,
                effective_date:preview.effective_date,offer_rev:preview.offer_rev},key),r=>ctx.go({move:r.move_id},"岗位已预留。"));
        const ready=preview.funded_fte_bp>0 && preview.target_available_fte>=preview.funded_fte_bp/10000 && !preview.pending_move &&
            !preview.missing_required.length && preview.contract_check!=="source_contract_conflict";
        return [panel("调任预检",facts([["员工",preview.employee],["原岗位",preview.from_position],["目标岗位",preview.to_position],
            ["薪资归属生效日",preview.effective_date],["目标岗剩余 FTE",preview.target_available_fte],["当月已批 FTE",preview.funded_fte_bp/10000],
            ["当前交接负责人",preview.current_handover_owner??"尚未指定"],["已有待办调任",preview.pending_move?"有":"无"],["合同覆盖",contractChecks[preview.contract_check]],
            ["缺少的必需技能",preview.missing_required.join("、")||"无"]]),ready?reserve:empty("请处理上述缺口后重新预检。"),button("返回",()=>ctx.go({})))];
    }
    const content=[await ctx.list("调任记录",url+"/moves","items",["调任单","岗位","生效日","预留 FTE","状态",""],r=>
        [r.id,r.title,r.effective_date,r.reserved_fte/10000,moveStates[r.state],button("查看调任",()=>ctx.go({move:r.id}))])];
    if (has(ctx.company,"hr")) content.push(await ctx.list("已完成评审的候选人",url+"/offers","items",["员工","目标岗位","录用修订","拟生效日"],r=>
        [r.display_name,r.title,r.offer_rev,ctx.form([field("生效日期","day",{type:"date"})],[["check","检查调任条件"]],
            async (_,b)=>b.day,day=>ctx.go({offer:r.id,day}))]));
    return content;
}

async function moveDetails(ctx,path) {
    const {move,contract,handover,signatures,actions}=await ctx.request(path);
    const content=[panel("调任单 #"+move.id,facts([["状态",moveStates[move.state]],["生效日",move.effective_date],
        ["原部门 / 岗位",move.source_department+" / "+move.from_position],["接收部门 / 岗位",move.target_department+" / "+move.to_position],
        ["预留 FTE",move.reserved_fte/10000],["原任职记录",move.source_assignment_id],["生效任职记录",move.result?.assignment_id]]),
        button("返回调任列表",()=>ctx.go({}))),panel("双方签核",table(["部门","签核人","说明","签核时间（UTC）"],signatures.map(s=>
            [s.side==="source_manager"?"原部门":"接收部门",s.actor_id,s.notes,s.signed_at]),"尚未签核。"))];
    if (contract) {
        const fields=[["合同编号",contract.reference],["生效日",move.effective_date],["终止日（不含）",contract.ends_before||"无固定期限"]];
        if (contract.monthly_minor!=null) fields.push(["月薪",money(ctx.company,contract.monthly_minor)]);
        content.push(panel("接收法人合同",facts(fields)));
    }
    if (handover) content.push(panel("原部门交接",facts([["负责人",handover.owner_id],["交接清单",handover.checklist],
        ["完成时间（UTC）",handover.completed_at||"待完成"]])));
    const options={
        "source-sign":["签核并分配交接",[num("交接负责人账号","owner_id"),notes("交接清单","checklist"),notes("原部门签核意见","notes")]],
        "target-sign":["接收部门签核",[notes("接收意见","notes")]],
        contract:["确认接收合同",[field("合同编号","reference"),num("月薪（货币最小单位）","monthly_minor"),field("终止日（不含，可选）","ends_before",{type:"date",required:false})]],
        withdraw:["撤回调任",[notes("撤回原因","reason")]],
        "release-blocked":["撤销无法生效的调任",[notes("阻断核查与撤销原因","reason")]],
        "handover-complete":["确认交接完成",[]],
        apply:["办理到期生效",[]]
    };
    for (const action of actions) {
        const [label,fields]=options[action];
        content.push(panel(label,ctx.form(fields,[[action,label,action==="withdraw"]],(_,b,k)=>{
            if (!b.ends_before) delete b.ends_before;
            return ctx.request(path+"/"+action,{...b,revision:move.revision},k);
        },()=>ctx.go({move:move.id},"调任记录已更新。"))));
    }
    return content;
}

async function skills(ctx,route={}) {
    const path=ctx.base+"/talent/skills";
    const result=[];
    if (has(ctx.company,"employee")) {
        const mine=await ctx.list("我的技能与作品",path+"/mine","items",["技能","作品 / 凭据","状态","有效期","可见范围"],r=>
            [r.skill,r.title+" · "+r.evidence_ref,labels[r.state],r.verified_until ? r.verified_until+"（剩余 "+r.remaining_days+" 天）":"—",
                ctx.form([visField(r.visibility)],[["save","保存可见范围"]],(_,b,k)=>ctx.request(path+"/"+r.id+"/visibility",{...b,revision:r.revision},k),()=>ctx.go({},"可见范围已更新。"))]);
        result.push(mine,panel("登记证据",ctx.form([field("技能代码","skill",{value:route?.skill}),field("作品或证书名称","title"),
            field("作品地址或证据编号","evidence_ref"),num("独立评审人账号编号","reviewer_id"),visField()],[["submit","提交验证"]],
            (_,b,k)=>ctx.request(path,b,k),()=>ctx.go({},"已提交给指定评审人。"))));
    }
    if (has(ctx.company,"hr")) {
        result.push(await ctx.list("分配给我的验证",path+"/review","items",["技能","作品","凭据","验证决定"],r=>
            [r.skill,r.title,r.evidence_ref,ctx.form([field("有效至（通过时必填）","verified_until",{type:"date",required:false}),notes("核验说明","notes")],
                [["verified","通过"],["rejected","退回",true]],(action,b,k)=>ctx.request(path+"/"+r.id+"/review",{...b,revision:r.revision,decision:action},k),()=>ctx.go({},"核验已记录。"))]));
        result.push(panel("按已验证技能查询",ctx.form([field("技能代码","skill",{value:route?.query})],[["search","查询"]],
            async (_,b)=>b.skill,q=>ctx.go({query:q}))));
        if (route?.query) result.push(await ctx.list("员工授权可见的证据",path+"/search?skill="+encodeURIComponent(route.query),"items",
            ["员工","技能","作品","有效至"],r=>[r.display_name,r.skill,r.title,r.verified_until]));
    }
    return result;
}

function reasons(ctx,rows,applicant) {
    return table(["技能","要求","已验证凭据"],rows.map(r=>[r.skill,r.required?"必需":"加分",
        r.evidence ? r.evidence.title+" · "+r.evidence.evidence_ref+"（至 "+r.evidence.verified_until+"）" :
            applicant ? button("补充证据",()=>ctx.navigate("skills",{skill:r.skill})) : "暂无有效授权凭据"]));
}

async function market(ctx,route={}) {
    const url=ctx.base+"/talent";
    if (route?.opening) {
        const data=await ctx.request(url+"/market/"+route.opening);
        return [panel(data.opening.title,facts([["职位说明",data.opening.description],["截止日期",data.opening.closes_on],
            ["缺少的必需技能",data.missing_required.join("、")||"无"]]),reasons(ctx,data.match_reasons,true),
            ctx.form([notes("申请说明","motivation")],[["apply","投递申请"]],(_,b,k)=>ctx.request(url+"/market/"+route.opening+"/apply",b,k),
                ()=>ctx.go({},"已投递，可在我的竞聘记录查看进度。")),button("返回",()=>ctx.go({})))];
    }
    if (route?.panel) {
        const path=url+"/panel/"+route.panel;
        const report=await ctx.request(path);
        const cards=await ctx.list("已投递候选人",path,"items",["员工","动机","阶段","当前授权的证据","评审"],r=>{
            const canScore=report.can_score&&r.state==="applied"&&!r.scores.some(s=>s.reviewer_id===ctx.accountId);
            return [r.display_name,r.motivation,labels[r.state],reasons(ctx,r.match_reasons,false),canScore ?
                ctx.form([field("评分（1–100）","score",{type:"number",min:1,max:100}),notes("面试记录","notes")],[["score","提交独立评分"]],
                    (_,b,k)=>ctx.request(url+"/applications/"+r.id+"/score",b,k),()=>ctx.go(route,"评分已提交。")) :
                table(["评委","得分","记录"],r.scores.map(s=>[s.reviewer_id,s.score,s.notes]))];
        },"尚无投递记录。",{first:report});
        return [panel(report.opening.title,button("返回岗位列表",()=>ctx.go({}))),cards];
    }
    const parts=[];
    if (has(ctx.company,"employee")) {
        parts.push(await ctx.list("可竞聘岗位",url+"/market","items",["职位","法人","部门","截止日期",""],r=>
            [r.title,r.company,r.department,r.closes_on,button("查看匹配依据",()=>ctx.go({opening:r.id}))]));
        parts.push(await ctx.list("我的竞聘记录",url+"/applications","items",["申请编号","岗位","状态","修订","操作"],r=>
            [r.id,r.title,labels[r.state],r.revision,["applied","reviewed"].includes(r.state)?
                ctx.form([notes("撤回原因","reason")],[["withdraw","撤回本次竞聘",true]],(_,b,k)=>ctx.request(url+"/applications/"+r.id+"/withdraw",{...b,revision:r.revision},k),
                    ()=>ctx.go({},"竞聘申请已撤回。")):"—"]));
    }
    if (has(ctx.company,"manager","hr")) {
        parts.push(await ctx.list("我的面试与岗位",url+"/panel","items",["职位","部门","状态",""],r=>
            [r.title,r.department,labels[r.state],button("查看已投递人员",()=>ctx.go({panel:r.id}))]));
    }
    if (has(ctx.company,"manager")) {
        const split=s=>s.trim().split(/[\s,，]+/u).filter(Boolean);
        parts.push(panel("从已批预算发布空岗",ctx.form([num("已批准的预算单编号","budget_id"),field("岗位名称","title"),notes("岗位说明","description"),
            field("投递截止日","closes_on",{type:"date"}),field("必需技能代码（逗号分隔）","required"),
            field("加分技能代码","optional",{required:false}),field("独立评委账号编号（至少两位，逗号分隔）","panel")],[["publish","发布岗位"]],
            (_,b,k)=>ctx.request(url+"/openings",{budget_id:b.budget_id,title:b.title,description:b.description,closes_on:b.closes_on,
                skills:[...split(b.required).map(skill=>({skill,required:true})),...split(b.optional).map(skill=>({skill,required:false}))],panel:split(b.panel)},k),
            r=>ctx.go({panel:r.opening_id},"岗位已发布。"))));
    }
    return parts.length?parts:[empty("暂无可见岗位。")];
}

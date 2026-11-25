# 集团人力资源管理系统 HCM｜MySQL 数据与集成

## 持久化边界

MySQL 8.4 InnoDB 是本项目业务状态与授权关系的权威存储。租户身份进入业务唯一键与关联检查。媒体、文件、备份或模型大对象允许使用文件或对象存储，库内保存版本化引用、长度、摘要及所有者。派生缓存可从持久记录重新构建。

## 逻辑实体与事实来源

| 业务域 | 核心实体与必要字段 | 写入时必须维持的规则 |
|---|---|---|
| 组织与编制 | org_unit(company,parent,effective), position(org,capacity), slot_hold(position,person) | 调入和招录共用名额，已占编制与预算期一致；组织调整保留旧任职关系。 |
| 招聘与录用 | candidate(tenant,identity_ref), application(candidate,position), offer(application,rev) | 候选人可应聘多岗，招聘团队只见分配岗位；接受录用并校验名额后转入职。 |
| 任职与合同 | employment(person,company,start,end), assignment(employment,dept,from_date), contract_rev(emp,rev) | 有效任职区间按日切分，主岗不重叠；合同是独立版本；跨法人调动记录转出与接收。 |
| 排班与假勤 | shift(emp,workday,start,end), punch(source,event), leave_ledger(emp,kind,hours) | 跨午夜班次归属班表工作日；审批锁定请假余额，销假或拒批恢复可用额度。 |
| 薪资计算 | pay_rule(company,rev), payroll_run(company,period,rev), payslip_line(run,emp,item,minor) | 使用任职、假勤和薪资快照，薪酬员可解释每一项来源；当地扣缴参数由部署方配置。 |
| 封账与补差 | pay_close(run,digest), retro(original_line,decision,delta), inquiry(emp,slip,state) | 封账后工资单不可直接重写；历史追溯产生本期补差项，保留原算、重算及差额。 |
| 绩效与成本 | review_cycle(org,period), appraisal(emp,cycle,rev), cost_split(pay_line,dept,minor) | 绩效记录按周期和可见人收集，定稿后保留修订；按生效任职段汇总费用。 |
| 员工服务与离职 | self_request(emp,kind), exit_case(emp,last_day), clearance(case_id,owner,state) | 员工只查本人资料，离职办理冻结新增申请并结清待办，人事复核后撤销业务身份。 |

## 事务、索引与对账

- **身份与约束**　同一员工主岗有效区间不能重叠，员工＋工资期＋版本确定工资单。
- **业务索引**　按法人、期间、员工批查工资，按部门与生效区间追溯任职和成本。
- **数据生命周期**　工资、身份和绩效分别授权，下载保留审计，样例只用虚构个人数据。

任职生效区间按员工串行校验，薪资封账冻结规则和输入；补差按原工资行与决定编号唯一，考勤回执按设备与事件唯一。

列表使用租户、业务状态、更新时间和稳定主键分页。外部事件保留来源、外部号、内容摘要、处理结果和业务对象引用。同键不同内容产生显式冲突；网络超时后按原键查单。跨存储发布应先确认文件持久，再发布其数据库引用；回收须验证没有有效引用。

## 集成契约

导入班表与合成打卡，导出工资单、成本分摊和员工核对结果；演示工资文件逐角色授权。

提交调岗须含组织、员工、生效日、原任职版本；返回分段区间和受影响的排班、费用期间。

试算输入期间、工资规则与考勤水位，返回员工逐项来源、缺数、舍入及总额；复核人与制单人分离。

## 数据恢复与核验

在隔离环境恢复 MySQL 和引用清单，校验样例的源对象、派生关系及最终结果。恢复后应能回答以下问题。原工资单 9000、原成本 4500＋4500 保留，下期单列补差 300，归属原上半月部门并关联决定编号。

[MySQL 事务模型](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-model.html)

[产品](01-产品总览-prd.md) · [功能](02-业务与交互-prd.md) · [MySQL](03-MySQL与集成-prd.md) · [验收](04-验收与运营-prd.md) · [开源](05-开源与发布-prd.md) · [状态与操作](06-状态与操作契约-prd.md) · [公开案例](07-开源项目说明与使用案例-prd.md)

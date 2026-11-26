# People Suite 需求索引

下面按 [业务与交互 PRD](requirements/02-业务与交互-prd.md) 的 24 个编号列出实现与回归入口。16 条编号验收和容量、升级、恢复的运行记录见 [业务验收](acceptance.md)。

| 编号 | 项目能力与实现 | 可复现证据 |
| --- | --- | --- |
| F01 | 法人及按日期维护的部门树 [组织服务](../src/Organization.php) | [HTTP 组织场景](../tests/http_smoke.py) |
| F02 | 生效日岗位历史和撤岗阻断 [组织服务](../src/Organization.php) | AC-02 |
| F03 | 岗位预算、占用区间及独立事务争用 [组织服务](../src/Organization.php) | AC-01、AC-04 |
| F04 | 岗位授权、招聘申请和候选人去重 [招聘服务](../src/Recruitment.php) | AC-03 |
| F05 | 同一候选人跨岗位的独立面试评价 [招聘服务](../src/Recruitment.php) | AC-03 |
| F06 | 录用签核、接受、撤销及占编变更 [招聘服务](../src/Recruitment.php) | AC-01、AC-04 |
| F07 | 从已接受录用建立入职、主岗和合同 [任职服务](../src/Employment.php) | [HTTP 入职场景](../tests/http_smoke.py) |
| F08 | 合同独立版本、续签的衔接日校验 [任职服务](../src/Employment.php) | [HTTP 续签场景](../tests/http_smoke.py) |
| F09 | 法人内调岗的任职分段和影响清单、跨法人双方接收记录 [任职服务](../src/Employment.php) | AC-05、AC-06、[HTTP 跨法人场景](../tests/http_smoke.py) |
| F10 | 本地工作日与跨午夜班表、原批次逐行处理 [考勤服务](../src/Attendance.php) · [导入服务](../src/AttendanceImports.php) | AC-07、[HTTP 导入场景](../tests/http_smoke.py) |
| F11 | 打卡异常、决定及带水位的考勤确认 [考勤服务](../src/Attendance.php) · [考勤确认](../src/AttendanceCertifications.php) | AC-07、AC-10 |
| F12 | 年假额度预占、审批、释放与销假 [假期服务](../src/Leave.php) | AC-08 |
| F13 | 法人配置的规则版本、计薪项目和舍入 [薪资服务](../src/Payroll.php) · [计算逻辑](../src/PayrollCalculator.php) | AC-09、[容量验证](payroll-capacity.md) |
| F14 | 任职、合同和考勤快照下的分段金额 [计算逻辑](../src/PayrollCalculator.php) | AC-09 |
| F15 | 试算送审、独立复核和版本限定代办 [薪资服务](../src/Payroll.php) | AC-10、[HTTP 代办场景](../tests/http_smoke.py) |
| F16 | 封账摘要、薪资发布和不可变的原单 [薪资服务](../src/Payroll.php) | AC-10、AC-11 |
| F17 | 员工领单、提出疑问、复核答复及结果导出 [工资核对](../src/PayrollInquiries.php) · [导出](../src/PayrollExports.php) | [HTTP 工资核对场景](../tests/http_smoke.py) |
| F18 | 原工资行复算、补差幂等及原成本归属 [追溯补差](../src/PayrollAdjustments.php) | AC-11、AC-12 |
| F19 | 周期、人员范围、目标权重和自评 [绩效服务](../src/Performance.php) | [HTTP 绩效场景](../tests/http_smoke.py) |
| F20 | 主管评分、独立校准、定稿和修订 [绩效服务](../src/Performance.php) | [HTTP 绩效场景](../tests/http_smoke.py) |
| F21 | 按任职生效段归集成本和集团按币种对账 [成本报表](../src/PayrollCosts.php) | AC-13、AC-14 |
| F22 | 本人档案、员工申请及已发布记录 [员工自助](../src/EmployeeServices.php) | AC-15、[HTTP 自助场景](../tests/http_smoke.py) |
| F23 | 离职清单、待办处理和设备归还阻断 [离职服务](../src/Offboarding.php) | AC-16 |
| F24 | 冻结新增业务、收回角色、按原授权回读归档 [离职服务](../src/Offboarding.php) · [访问控制](../src/Access.php) | AC-15、AC-16 |

# HTTP API

接口使用 JSON。先请求 `GET /api/session` 得到 CSRF 令牌和会话 Cookie；登录和之后的写操作都在 `X-CSRF-Token` 中回传令牌。登录后使用响应提供的新令牌。写入表格中带「键」的操作还需传 `Idempotency-Key`，长度最多 48，允许字母、数字、点、下划线和连字符。

相同操作者在同一法人对同一操作重送相同键及参数时取得首次提交的回执。相同键与不同有效参数返回 `409 request_key_conflict`。写冲突可带原键重试。越权读取和变更返回 `404`，登录失败返回 `401`。

## 会话和组织

| 方法与路径 | 请求字段与说明 |
| --- | --- |
| GET `/healthz` | 数据库迁移版本及摘要已就绪时返回 200。 |
| POST `/api/login` | `group`、`email`、`password`。 |
| POST `/api/logout` | 空对象。 |
| GET `/api/companies` | 当前账户可见的法人。 |
| GET `/api/workspace` | 当前账号和集团显示信息、可见法人、法人内角色及历史单据权限。 |
| POST `/api/companies` | 集团管理员传入 `code`、`name`、`timezone`、`currency`。 |
| POST `/api/accounts` | 集团管理员传入 `email`、`display_name`、`password`。 |
| POST `/api/companies/{company}/roles` | 集团管理员传入 `account_id`、`role`。 |
| GET `/api/companies/{company}/departments?on=2026-04-16` | HR 按指定日期查询部门树。 |
| GET `/api/companies/{company}/positions?on=2026-04-16` | 按日期查询岗位及生效版本。HR 可见本法人岗位，招聘专员仅见本人获授权的岗位，支持 `after=ID`。 |
| POST `/api/companies/{company}/departments` | 键。HR 提交 `code`、`name`、`effective_from`、可选 `parent_id`。 |
| POST `/api/companies/{company}/positions` | 键。HR 提交 `code`、`department_id`、`title`、`effective_from`。 |

## 岗位与招聘

下面路径以 `/api/companies/{company}/positions/{position}` 开头。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| GET `/revisions` | 查询岗位历史。 |
| POST `/revisions` | 键。HR 提交 `revision_id`、`department_id`、`title`、`effective_from`、`active`。撤岗时返回尚未释放的占用。 |
| GET `/headcount?on=2026-04-16` | HR 查询当天有效预算、已占用及可用数量，包含沿用上期席位的任职。 |
| POST `/budgets` | 键。HR 提交 `starts_on`、`ends_before`、`capacity`。 |
| POST `/recruiters` | HR 传入已有招聘角色的 `account_id`，授权当前岗位。 |
| GET `/requisitions` | 查询本岗位招聘申请，支持 `after=ID`。 |
| POST `/requisitions` | 键。提交 `proposed_start`、`justification`。 |
| GET `/requisitions/{id}` | 查询申请的版本和候选人，候选人支持 `after=ID`。 |
| POST `/requisitions/{id}/open` | 键。HR 审批，传入当前 `version`。 |
| POST `/requisitions/{id}/apply` | 键。提交 `identity_ref`、`display_name`。可使用相同候选身份登记不同岗位申请。 |
| POST `/applications/{id}/interview` | 键。提交 `version`、`rating`、`notes`。 |
| POST `/applications/{id}/offer` | 键。提交 `version`、`effective_on`、整数 `monthly_minor`，金额使用法人币种最小记账单位。 |
| POST `/offers/{id}/sign` | 键。HR 传入 `version`、`decision_ref`。 |
| POST `/offers/{id}/accept` | 键。HR 传入 `version`、`decision_ref`，接受录用时原子占用编制。 |
| POST `/offers/{id}/revoke` | 键。HR 传入 `version`、`decision_ref`，释放先前占用。 |
| POST `/offers/{id}/onboard` | 键。HR 提交已接受的录用 `version`、`employee_code`、`contract_ref`、可选 `contract_ends_before`。返回同一员工的任职、岗位和合同版本。 |
| GET `/applications/{id}` | 查询当前岗位的申请、评估、录用及决定记录。 |

各操作成功回执携带对象 ID 或版本。具体接口流程可直接运行 `tests/http_smoke.py`。

## 任职与调动

下面路径以 `/api/companies/{company}` 开头。HR 只能查看已获授权法人的历史和处理事项。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| GET `/employments` | HR 查看当前法人的任职目录，支持 `after=ID`。 |
| GET `/employments/{id}` | 查询人事档案、带岗位和部门名称的任职时间线及合同版本。可加 `?on=2026-04-16` 获取该日任职段。 |
| POST `/employments/{id}/contracts` | 键。提交当前合同 `version`、`contract_ref`、`effective_from`、`monthly_minor`、可选 `ends_before`。续签新增版本。 |
| POST `/employments/{id}/transfers` | 键。提交 `assignment_version`、目标 `position_id`、`effective_from`。返回任职分段及需复核的假勤和成本期间。 |
| GET `/employments/{id}/transfer-impact` | HR 提供调岗形成的 `assignment_id`，以及 `kind=shifts` 或 `kind=cost_periods`。只查该任职段内相关记录，使用 `after=ID` 翻页。 |
| POST `/employments/{id}/legal-transfers` | 键。来源法人 HR 提交 `assignment_version`、`target_company_id`、`target_position_id`、`effective_from`。 |
| GET `/employments/{id}/legal-transfers` | 来源法人 HR 查看转出单、接收决定和源版本失效标记，支持 `after=ID`。 |
| GET `/legal-transfers` | 接收法人 HR 查看待接收调动。传 `state=accepted` 查看已接收调动，支持 `after=ID`。 |
| GET `/legal-transfers/{id}` | 接收法人 HR 查看调动单、源版本失效标记及接收任职编号。 |
| POST `/legal-transfers/{id}/accept` | 键。由另一位接收法人 HR 提交 `decision_ref`、接收合同 `contract_ref`、`monthly_minor`、可选 `ends_before`。 |

调岗时，上一段在切换日开始前结束，下一段从切换日开始。状态过期或日期冲突返回 `assignment_interval_conflict`，可读取当前版本和区间。跨法人接收将转出、目标编制、目标合同和两方审计记录写入同一事务。主岗后续有调动或终止时，旧申请在历史中以 `stale=1` 标明，并退出待接收队列。

法人内调岗的 `impact` 回执保留提交时的首批班次和账期，每类最多 100 行。从历史查询生成当前影响名单，取消的班次会移出。成本名单只返回法人账期、日期和状态，已封账账期通过追溯补差处理。

## 排班、打卡与考勤

路径以 `/api/companies/{company}` 开头。HR 可写班表和导入打卡。主管仅能访问工作日授权区间内的直属记录，员工可查看本人记录。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| POST `/employments/{id}/account` | 键。HR 传 `account_id`，将已有员工角色的账户与人员关联。 |
| POST `/employments/{id}/manager` | 键。HR 传主管 `account_id` 和 `effective_from`，创建带生效区间的汇报关系。 |
| GET `/attendance/workforce?workday=2026-04-15` | 列出该工作日内可查的任职、待核对班次和待审批请假数量。支持 `after=ID`。 |
| GET `/employments/{id}/shifts?workday=2026-04-15` | 查指定工作日班表。 |
| POST `/employments/{id}/shifts` | 键。传 `workday`、`starts_at`、`ends_at`。 |
| GET `/attendance/shifts/{shift}` | 同一快照内读取班次、当前版本、异常类型与打卡时间和设备事件编号。 |
| POST `/attendance/shifts/{shift}/punches` | 键。传 `source`、`event_ref`、`direction`（`in` 或 `out`）、`punched_at`。设备与事件的相同内容重放返回原回执。 |
| POST `/attendance/shifts/{shift}/resolution` | 键。传 `version`、`state`（`worked`、`absent`、`excused` 或 `cancelled`）、`decision_ref`、`explanation`。HR 可传 `worked_minutes` 核定异常工时。 |
| GET `/attendance/watermark` | HR 和薪酬角色可查当前法人考勤水位。 |
| GET `/employments/{id}/attendance-certifications` | HR 查看该任职的确认日期、汇总分钟、水位和决定编号。支持 `after=ID`。 |
| POST `/employments/{id}/attendance-certifications` | 键。HR 传 `starts_on`、`ends_before`、`expected_shifts`、`decision_ref` 和 `notes`。预期班次与排班一致且都已核对时，生成该任职的考勤确认。 |

时间戳使用带时区的 RFC 3339 秒级字符串，例如 `2026-04-15T22:00:00+08:00`。工作日取班次开始时在法人时区的日期。班次可跨午夜，时长介于 1 分钟和 24 小时之间。回读时间戳以 Unix 秒表示。审批后补录打卡会新增异常版本，保留此前的版本和核对记录。

### 批量导入

- `GET /attendance/imports` 查询当前 HR 创建的批次，支持 `after=ID`。批次及其行的读写均限创建者。
- `POST /attendance/imports` 带请求键，提交 `kind`（`roster` 或 `punches`）和最多 100 个 `rows`。每行带唯一 `row_ref`，及对应班表或打卡字段。班表行带 `employment_id`，打卡行带 `shift_id`。
- `POST /attendance/imports/{id}/run` 每次最多处理 25 行。可传 `limit`、`after_row` 和 `retry_failed`。断开后回到原批次继续，成功行保留初次回执。
- `POST /attendance/imports/{id}/retry` 带请求键，提交 `line_nos`，包含 1 至 25 个不同的原批次行号。回执按行列出重试结果，待处理、已成功或不存在的行会返回拒绝原因。用相同请求键重复提交同一选集时返回原回执。
- `GET /attendance/imports/{id}` 返回待处理、成功、失败的数量，以及每行的尝试次数和结果。

### 假期余额

路径以 `/api/companies/{company}/employments/{id}/leave` 开头，额度使用整数分钟。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| GET `?workday=2026-04-20` | 查该日请假。HR 和本人同时可查年度余额。 |
| POST `/grants` | 键。HR 传 `kind`、`year`、`minutes` 和 `decision_ref`。 |
| POST 基础路径 | 键。HR 或本人传 `kind`、`workday`、`minutes`、`reason` 和 `reference`，预占额度。 |
| GET `/{request}` | 查申请和账本记录。 |
| POST `/{request}/approve`、`/deny`、`/cancel` | 键。传 `version`、`decision_ref`。通过转作已用，拒批或销假返还余额。本人可撤回待批申请，已批准的申请由 HR 或主管销假。 |

## 薪资试算

路径以 `/api/companies/{company}/payroll` 开头。制单人创建规则和试算，两类薪酬角色均可查看所属法人的结果。金额以币种的最小记账单位传入，4,500 元即 450000 分。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| GET `/rules` | 查本法人计薪规则版本，支持 `after=ID`。 |
| POST `/rules` | 键。传 `rule_ref`、`title`、`proration_basis`。可配置 `monthly_allowance_minor`、`absence_hourly_minor`、`withholding_bps`、`withholding_exempt_minor`，默认均为 0。 |
| GET `/runs` | 查本法人账期与最近试算的应计和阻断人数，支持 `after=ID`。 |
| POST `/runs` | 键。传月份首日 `period_start`，创建一个法人的完整自然月账期。 |
| POST `/runs/{id}/trial` | 键。提交当前账期 `version`、`rule_id`、`attendance_watermark`。每次试算留下独立快照。 |
| GET `/runs/{id}` | 账期与最新试算结果。工资单可传 `?after=ID` 分页。 |
| GET `/payslips/{id}` | 读取任意保留的试算单，含已引用的合同、任职、规则、考勤确认及每段金额。 |

`actual_days` 使用当月自然日数分摊，`fixed_30` 用于 30 天账期。工资和固定津贴按任职与合同生效日拆段，在分位对合计四舍五入，尾差按小数余数分配。缺少考勤确认、选定水位下已有后续班次版本、任职重叠或合同缺失时，员工试算单标记阻断并记录原因。扣缴配置是一组可核对的示例参数，部署方按所在地区核准。

### 复核、封账与发布

四个写操作均带请求键，提交当前账期的 `version`、业务 `decision_ref` 和 `notes`。

| 方法与后缀 | 状态约束 |
| --- | --- |
| POST `/runs/{id}/submit` | 制单人送审自己的试算。试算应包含至少一位员工，所有工资单均已就绪，且已纳入当期全部补差。 |
| POST `/runs/{id}/return` | 独立复核人退回，保留试算与决定记录。 |
| POST `/runs/{id}/close` | 独立复核人对当前试算封账，记录规则、试算单、分段金额和补差的 SHA-256 摘要。 |
| GET `/runs/{id}/verify-closure` | 对封账内容重算摘要，返回 `valid`。 |
| POST `/runs/{id}/publish` | 独立复核人发布。系统重新核对封账摘要。 |
| GET `/my-payslips` | 本人已发布工资单，可用 `?after=ID` 分页。 |
| GET `/my-payslips/{id}` | 本人指定已发布工资单与封账摘要。 |

待审账期的复核人可使用 `GET /runs/{id}/delegation-candidates?after=ID` 查询同法人独立复核人，排除制单人与自己。带请求键提交 `POST /runs/{id}/delegate`，字段为 `version`、`delegate_id`、`hours`（1 至 168）、`notes` 以及更换时的 `previous_delegation_id`。委托人可用 `POST /runs/{id}/revoke-delegation`，提交当前版本、`previous_delegation_id` 和 `notes`。

指定人员代办退回或封账时传 `delegation_id`。复核时校验当前账期、试算和双方的法人权限，委托过期或替换后需要重新读取。审核记录保留实际操作人、委托人及其代办编号。发布仍由独立复核人直接执行。

### 追溯补差

`POST /runs/{id}/adjustments` 由制单人操作，目标是可编辑的后续账期。传 `original_payslip_id`、`original_line_no`、`decision_ref`、`corrected_monthly_minor`、`notes` 和幂等键。再次修订同一行时，传最新回执的 `previous_adjustment_id`。回执包含原行金额、前次重算、此次重算、原工资单总额及本次差额。同一历史行及决定编号重复提交会返回同一笔补差，字段变化会返回冲突。

补差沿用原行的分摊天数、部门和扣缴规则。下一次试算纳入差额，原法人已经没有当期任职的员工会收到单独的补差单。工资单的 `net_minor` 保留当期正常净额，`payable_minor` 包含补差，`adjustments` 记录来源和金额。

`GET /runs/{id}/adjustments` 返回账期已登记的补差，支持 `after=ID`。可在试算前核对原行、重算金额和决定编号。

### 员工工资核对

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| POST `/my-payslips/{id}/inquiries` | 员工对本人已发布工资单发起核对。传 `reference`、`category`、`question` 和幂等键。 |
| GET `/my-payslips/{id}/inquiries` | 员工读取本人提出的疑问、答复和确认时间。支持 `?after=ID`。 |
| GET `/inquiries` | 薪酬复核人读取本法人待办及历史结果。支持 `?state=open`、`answered`、`acknowledged` 和 `after=ID`。 |
| GET `/inquiries/{id}` | 薪酬复核人查看本法人单条核对记录及最新版本。 |
| POST `/inquiries/{id}/answer` | 由独立薪酬复核人答复，传 `version`、`decision_ref`、`answer` 和幂等键。 |
| POST `/inquiries/{id}/acknowledge` | 本人确认收到答复，传 `version` 和幂等键。 |

核对类别为 `base_pay`、`attendance`、`withholding`、`adjustment` 或 `other`。疑问和答复仅写入核对记录，已发布工资单及封账摘要可以独立回读。

### 部门成本

`GET /runs/{id}/department-costs` 返回该账期最近一次试算的部门分摊、差额、状态和封账摘要。原工资成本按任职分段归属，补差按原工资行部门归属。未完成的试算同时返回阻断人数。

`GET /api/payroll/department-costs?period_start=2026-04-01` 汇总各法人已封账或已发布的当月成本，按币种分别给出合计。两种查看方式均向制单与复核角色开放，返回范围以法人授权为准。

### 文件下载

以下 `GET` 路径使用对应查询接口的角色和对象权限，返回 UTF-8 CSV 附件。工资单分列正常工资和追溯补差。带分页的下载支持与查询相同的 `after` 游标。

生成附件时记录发起人、法人、对象和导出范围。集团成本附件按纳入报表的法人分别登记。审计元数据仅含对象标识、导出范围和计数等信息。

| 路径 | 访问范围 |
| --- | --- |
| `/payslips/{id}.csv` | 制单或复核角色，所属法人。 |
| `/my-payslips/{id}.csv` | 员工已发布的本人工资单。 |
| `/runs/{id}/department-costs.csv` | 制单或复核角色，所属法人。 |
| `/api/payroll/department-costs.csv?period_start=2026-04-01` | 具备薪酬权限的法人已封账数据，按币种合计。 |
| `/inquiries.csv?state=acknowledged` | 薪酬复核人，本法人核对结果。 |
| `/my-payslips/{id}/inquiries.csv` | 员工针对本人已发布工资单发起的核对记录。 |

## 绩效周期

路径以 `/api/companies/{company}/performance/cycles` 开头。周期依次经历 `goals`、`evaluation`、`calibration` 与 `finalized`。HR 负责建期、入组、校准和定稿。入组按所选生效日记录任职和直属主管，主管交接后的权限随新周期重新确定。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| GET `/` | 返回授权周期，支持 `after=ID`。 |
| POST `/` | 键。HR 传 `reference`、`title`、`starts_on`、`ends_before`。 |
| GET `/{cycle}` | 查询周期及授权参加人，支持 `after=ID`。 |
| POST `/{cycle}/participants` | 键。HR 传 `employment_id`、`scope_on`，录入期间内有任职、员工账号和主管的参加人。 |
| POST `/{cycle}/advance` | 键。HR 传周期 `version`、`decision_ref`，推进下一阶段。当前阶段仍有待办时返回数量。 |
| GET `/{cycle}/participants/{id}` | 查询个人目标、自评、可见的评价修订和当前用户可执行的 `actions`。员工定稿后看到已发布结果和反馈。 |
| POST `/{cycle}/participants/{id}/goals` | 键。本人或直属主管传参加人 `version`、`goals`。每项目标包含 `code`、`title`、`target`、`weight_bps`，总权重为 10000。 |
| POST `/{cycle}/participants/{id}/confirm-goals` | 键。直属主管传 `version`、`decision_ref` 确认并冻结目标。 |
| POST `/{cycle}/participants/{id}/self-assessment` | 键。员工在主管提交评价前传 `version`、`assessment`。 |
| POST `/{cycle}/participants/{id}/evaluation` | 键。直属主管传 `version`、`decision_ref`、`feedback` 和覆盖全部目标的 `goal_scores` 列表，列表元素为 `code` 与 `score`。 |
| POST `/{cycle}/participants/{id}/calibration` | 键。独立 HR 传 `version`、`decision_ref`、`score`、`feedback`、`rationale`。校准说明仅向 HR 可见。 |
| POST `/{cycle}/participants/{id}/amendment` | 键。定稿后由独立 HR 以相同字段追加修订，保留此前发布结果。 |

评分为 0 至 100。主管评分按已确认目标权重汇总，四舍五入至整数。参加人本人和原评分主管须回避对该记录的 HR 校准。

## 员工自助

路径以 `/api/companies/{company}/employee` 开头。员工可以查询本人登记的任职资料和申请。HR 在所属法人审核申请并保留决定。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| GET `/me` | 查看本人档案及任职。可传 `on=YYYY-MM-DD` 查询任职生效日，默认采用法人当地日期。 |
| GET `/requests` | 查询本人申请和结果，支持 `after=ID`。 |
| POST `/requests` | 键。传 `employment_id`、`reference`、`kind`、`details`。`kind=name_correction` 另传 `requested_name`。 |
| GET `/requests/{id}` | 查询本人或 HR 有权审核的申请。 |
| GET `/review-queue` | HR 查询待办或历史记录，支持 `state=pending`、`approved`、`rejected`、`withdrawn` 和 `after=ID`。 |
| POST `/requests/{id}/approve`、`/reject` | 键。由独立 HR 传 `version`、`decision_ref`、`response` 结案。 |
| POST `/requests/{id}/withdraw` | 键。本人传 `version`、`decision_ref`、`response` 撤回待审核申请。 |

`employment_certificate` 审核通过后生成任职信息快照。`name_correction` 审核通过后更新当前员工档案，历史工资单及已登记的绩效记录继续保留当时姓名。`other` 用于不更改档案的自助事项。申请须关联当前有效任职，结束任职的旧申请仍可查询。

## 离职交接

路径以 `/api/companies/{company}/offboarding` 开头。HR 按当前任职版本发起离职，`ends_before` 是法人当地日期下第一个无任职关系的自然日。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| GET 根路径 | 列出当前角色可见的离职单，支持 `after=ID`。 |
| POST 根路径 | 键。HR 传 `employment_id`、`assignment_version`、`ends_before`、`reference`、`reason`，可附带由 `code` 和 `label` 组成的 `equipment` 清单。 |
| GET `/{case}` | 查询离职状态及可见的清单。HR 同时查看阻塞办结的事项。 |
| POST `/{case}/tasks/{code}/confirm` | 键。责任人传 `version`、`decision_ref`、`evidence`。 |
| POST `/{case}/complete`、`/cancel` | 键。由独立 HR 传 `version`、`decision_ref`。 |

发起后暂停新增员工申请、请假和工资核对，同时禁止从离职生效日起新增排班。直属主管负责业务交接，薪资复核人确认结算事项，HR 确认权限与设备。设备、员工申请、假勤或工资核对仍有待办时，办结请求返回 `offboarding_clearance_incomplete` 及责任清单。

达到生效日且全部清单确认后，系统在同一事务结束任职、汇报线和编制占用，收回该法人业务角色。员工可继续登录领取该任职的已发布个人工资单、既有服务申请结果和已定稿绩效。离职当月的薪资按任职有效期计算。

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
| POST `/api/companies` | 集团管理员传入 `code`、`name`、`timezone`、`currency`。 |
| POST `/api/accounts` | 集团管理员传入 `email`、`display_name`、`password`。 |
| POST `/api/companies/{company}/roles` | 集团管理员传入 `account_id`、`role`。 |
| GET `/api/companies/{company}/departments?on=2026-04-16` | HR 按指定日期查询部门树。 |
| POST `/api/companies/{company}/departments` | 键。HR 提交 `code`、`name`、`effective_from`、可选 `parent_id`。 |
| POST `/api/companies/{company}/positions` | 键。HR 提交 `code`、`department_id`、`title`、`effective_from`。 |

## 岗位与招聘

下面路径以 `/api/companies/{company}/positions/{position}` 开头。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| GET `/revisions` | 查询岗位历史。 |
| POST `/revisions` | 键。HR 提交 `revision_id`、`department_id`、`title`、`effective_from`、`active`。撤岗时返回尚未释放的占用。 |
| POST `/budgets` | 键。HR 提交 `starts_on`、`ends_before`、`capacity`。 |
| POST `/recruiters` | HR 传入已有招聘角色的 `account_id`，授权当前岗位。 |
| POST `/requisitions` | 键。提交 `proposed_start`、`justification`。 |
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
| GET `/employments/{id}` | 查询人事档案、任职时间线和合同版本。可加 `?on=2026-04-16` 获取该日任职段。 |
| POST `/employments/{id}/contracts` | 键。提交当前合同 `version`、`contract_ref`、`effective_from`、`monthly_minor`、可选 `ends_before`。续签新增版本。 |
| POST `/employments/{id}/transfers` | 键。提交 `assignment_version`、目标 `position_id`、`effective_from`。返回任职分段及需复核的假勤和成本期间。 |
| POST `/employments/{id}/legal-transfers` | 键。来源法人 HR 提交 `assignment_version`、`target_company_id`、`target_position_id`、`effective_from`。 |
| GET `/legal-transfers` | 接收法人 HR 查看待接收调动，可通过 `?after=ID` 分页。 |
| POST `/legal-transfers/{id}/accept` | 键。由另一位接收法人 HR 提交 `decision_ref`、接收合同 `contract_ref`、`monthly_minor`、可选 `ends_before`。 |

调岗时，上一段在切换日开始前结束，下一段从切换日开始。状态过期或日期冲突返回 `assignment_interval_conflict`，可读取当前版本和区间。跨法人接收将转出、目标编制、目标合同和两方审计记录写入同一事务。

## 排班、打卡与考勤

路径以 `/api/companies/{company}` 开头。HR 可写班表和导入打卡。主管仅能访问工作日授权区间内的直属记录，员工可查看本人记录。

| 方法与后缀 | 请求字段与说明 |
| --- | --- |
| POST `/employments/{id}/account` | 键。HR 传 `account_id`，将已有员工角色的账户与人员关联。 |
| POST `/employments/{id}/manager` | 键。HR 传主管 `account_id` 和 `effective_from`，创建带生效区间的汇报关系。 |
| GET `/employments/{id}/shifts?workday=2026-04-15` | 查指定工作日班表。 |
| POST `/employments/{id}/shifts` | 键。传 `workday`、`starts_at`、`ends_at`。 |
| GET `/attendance/shifts/{shift}` | 查班次、当前版本、异常类型和打卡引用。 |
| POST `/attendance/shifts/{shift}/punches` | 键。传 `source`、`event_ref`、`direction`（`in` 或 `out`）、`punched_at`。设备与事件的相同内容重放返回原回执。 |
| POST `/attendance/shifts/{shift}/resolution` | 键。传 `version`、`state`（`worked`、`absent`、`excused` 或 `cancelled`）、`decision_ref`、`explanation`。HR 可传 `worked_minutes` 核定异常工时。 |
| GET `/attendance/watermark` | HR 和薪酬角色可查当前法人考勤水位。 |
| POST `/employments/{id}/attendance-certifications` | 键。HR 传 `starts_on`、`ends_before`、`expected_shifts`、`decision_ref` 和 `notes`。预期班次与排班一致且都已核对时，生成该任职的考勤确认。 |

时间戳使用带时区的 RFC 3339 秒级字符串，例如 `2026-04-15T22:00:00+08:00`。工作日取班次开始时在法人时区的日期。班次可跨午夜，时长介于 1 分钟和 24 小时之间。回读时间戳以 Unix 秒表示。审批后补录打卡会新增异常版本，保留此前的版本和核对记录。

### 批量导入

- `POST /attendance/imports` 带请求键，提交 `kind`（`roster` 或 `punches`）和最多 100 个 `rows`。每行带唯一 `row_ref`，及对应班表或打卡字段。班表行带 `employment_id`，打卡行带 `shift_id`。
- `POST /attendance/imports/{id}/run` 每次最多处理 25 行。可传 `limit`、`after_row` 和 `retry_failed`。断开后回到原批次继续，成功行保留初次回执。
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
| POST `/rules` | 键。传 `rule_ref`、`title`、`proration_basis`。可配置 `monthly_allowance_minor`、`absence_hourly_minor`、`withholding_bps`、`withholding_exempt_minor`，默认均为 0。 |
| POST `/runs` | 键。传月份首日 `period_start`，创建一个法人的完整自然月账期。 |
| POST `/runs/{id}/trial` | 键。提交当前账期 `version`、`rule_id`、`attendance_watermark`。每次试算留下独立快照。 |
| GET `/runs/{id}` | 账期与最新试算结果。工资单可传 `?after=ID` 分页。 |
| GET `/payslips/{id}` | 读取任意保留的试算单，含已引用的合同、任职、规则、考勤确认及每段金额。 |

`actual_days` 使用当月自然日数分摊，`fixed_30` 用于 30 天账期。工资和固定津贴按任职与合同生效日拆段，在分位对合计四舍五入，尾差按小数余数分配。缺少考勤确认、选定水位下已有后续班次版本、任职重叠或合同缺失时，员工试算单标记阻断并记录原因。扣缴配置是一组可核对的示例参数，部署方按所在地区核准。

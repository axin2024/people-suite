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
| GET `/applications/{id}` | 查询当前岗位的申请、评估、录用及决定记录。 |

各操作成功回执携带对象 ID 或版本。具体接口流程可直接运行 `tests/http_smoke.py`。

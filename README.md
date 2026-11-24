# People Suite · 集团人力资源管理系统

People Suite 面向有多个法人的集团，提供独立部署的组织、招聘与人事管理。业务状态保存在 MySQL，法人、岗位与角色共同决定每次访问的范围。

当前开发版本已提供组织、招聘、入职、合同续签、法人内外调动、排班、请假、假勤批量导入、分段薪资、复核封账、员工领单、追溯补差、工资核对、成本报表、绩效评价、员工自助和离职交接 API。

## 本地运行

准备 PHP 8.2 或更高版本，开启 `pdo_mysql` 和 `mbstring`。在 MySQL 8.4 创建专用 UTF-8 数据库和应用账号。按 [配置参考](docs/configuration.md) 设置环境变量。

```sh
php bin/hcm.php migrate
php bin/hcm.php health
php bin/hcm.php create-group north-demo "北岸集团" admin@example.test "演示管理员"
```

创建集团时，从标准输入读取初始密码，至少 12 字节。集团管理员可建法人和账户，再将 HR 或招聘权限分配到法人。招聘人员还需要具体岗位授权。

本地回环演示可将 `HCM_COOKIE_SECURE` 设为 `0`，再运行：

```sh
php -S 127.0.0.1:8080 -t public public/index.php
```

打开 `http://127.0.0.1:8080/` 进入工作台。集团管理员可创建法人和授予角色，HR 可查任职档案、审核员工申请和办理离职，员工可查看本人资料、申请和已发布账单。接口调用方式见 [HTTP API](docs/http-api.md)。健康探针为 `GET /healthz`。

薪资制单人可在工作台创建计薪规则和账期，查看考勤阻断、分段工资并登记追溯补差。独立复核人可退回、封账、验证封账摘要、发布工资单和答复员工。部门及集团成本可按授权范围查看和导出。

## 验证

在仅供测试的 `hcm_test_*` 数据库设置相同的应用环境变量，运行 `php tests/recruitment.php`。脚本使用合成身份，以独立 MySQL 连接验证编制及最后一天假期的并发请求，以及录用撤销后重新分配、请求重放和跨法人隔离。

将服务指向同一独立测试库后，可用 Python 3 运行 `python tests/http_smoke.py http://127.0.0.1:8080`，从真实 HTTP 接口验证入职调岗、跨午夜考勤、请假、导入续跑和 9000 元两部门分摊。

运行 `php -d memory_limit=256M tests/payroll_capacity.php 1000` 可复现一个法人 1,000 人的月度试算、封账与部门成本核对。参见[容量验收记录](docs/payroll-capacity.md)。

## 许可

项目采用 [Apache License 2.0](LICENSE)。

# People Suite · 集团人力资源管理系统

People Suite 面向有多个法人的集团，提供独立部署的组织、招聘与人事管理。业务状态保存在 MySQL，法人、岗位与角色共同决定每次访问的范围。

当前开发版本已提供组织、有效期岗位、编制和招聘 API。完整公开发行还需贯通任职与合同、假勤、薪资、封账补差、绩效、员工服务和离职。

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

健康探针为 `GET /healthz`。登录、创建法人及招聘审批入口见 [HTTP API](docs/http-api.md)。

## 验证

在仅供测试的 `hcm_test_*` 数据库设置相同的应用环境变量，运行 `php tests/recruitment.php`。脚本使用合成身份，验证两条 MySQL 连接争用同一份编制、录用撤销后重新分配、请求重放及跨法人隔离。

将服务指向同一独立测试库后，可用 Python 3 运行 `python tests/http_smoke.py http://127.0.0.1:8080`，从真实 HTTP 接口跑一次组织和招聘流程。

## 许可

项目采用 [Apache License 2.0](LICENSE)。

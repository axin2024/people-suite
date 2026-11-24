# 配置参考

| 变量 | 含义 |
| --- | --- |
| `HCM_DSN` | PDO MySQL 连接地址，必须指定 `charset=utf8mb4`。 |
| `HCM_DB_USER` | People Suite 专用数据库账户。 |
| `HCM_DB_PASSWORD` | 数据库账户密码，通过部署环境提供。 |
| `HCM_COOKIE_SECURE` | 默认启用 HTTPS Cookie。仅本地回环 HTTP 测试时设为 `0`。 |

先用具备本数据库建表权限的账号运行 `php bin/hcm.php migrate`，再启动应用并调用 `GET /healthz`。日常运行账号需要业务表的读取和写入权，及迁移记录表的读取权。避免把数据库服务暴露到公网。

HTTP 入口为 `public/index.php`，部署时站点根目录必须指向 `public`，并将 API 路由交给该入口。外部流量经 HTTPS 代理，代理负责登录请求速率限制。当前会话配置使用 PHP 文件会话，多个 PHP 节点需有共享会话处理器。

数据库会话时间采用 UTC。组织生效日期和编制预算采用左闭右开的日期区间。例如 4 月 16 日生效时，旧版本截止于 4 月 16 日开始之前。

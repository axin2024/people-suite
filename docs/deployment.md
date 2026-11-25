# 自托管部署

应用由 PHP 8.2 或更高版本运行，数据库使用 MySQL 8.4 InnoDB。容器路径使用 Apache 与 PHP 8.4，业务数据保存在 MySQL 命名卷。空库启动时先等待数据库就绪，再迁移，迁移完成后才启用 HTTP 服务。[Docker Compose 启动顺序](https://docs.docker.com/compose/how-tos/startup-order/)

## Docker Compose

安装 Docker Engine 和 Compose v2。把仓库的 .env.example 复制为 .env，分别设置 HCM_DB_PASSWORD 与 HCM_MYSQL_ROOT_PASSWORD。两项均为新生成的部署密码。首次本地回环演示可将 HCM_COOKIE_SECURE 设为 0。运行演示时，在首次启动前把 HCM_DB_NAME 设为 hcm_demo_north。

```sh
docker compose up --build --wait
docker compose exec web php bin/hcm.php health
```

默认监听本机 8080 端口。需要更换端口时设置 HCM_HTTP_PORT。浏览器打开本机首页，首次用于正式业务时，先以容器 CLI 创建集团和初始管理员。

```sh
docker compose exec web php bin/hcm.php create-group example-group "示例集团" admin@example.test "集团管理员"
```

命令从标准输入读取管理员密码。本地演示可以在同一容器中执行 php bin/hcm.php demo-seed 或 demo-finish，步骤见[独立演示](demo.md)。

默认 Compose 使用同一个专用数据库账户启动迁移和应用，便于独立部署。需要分离权限时，使用具备建表和变更权限的账户在维护窗口运行迁移，再把 Web 实例切换成仅具备目标库读取及业务写入权限的账户。变更现有卷的密码时，先在 MySQL 更改账号，再更新应用配置。停机可以运行 docker compose stop，保留数据库卷。

## 已有 PHP 和 MySQL

创建 utf8mb4 数据库及专用账号，设置环境变量并运行迁移和健康检查。把 Web 根目录指向 public，将 /api 路径和 /healthz 交给 index.php，禁止目录列表。可参考仓库内的 Apache 配置。[Apache 前端控制器文档](https://httpd.apache.org/docs/2.4/mod/mod_dir.html#fallbackresource)

公网访问经 HTTPS 反向代理。设置 HCM_COOKIE_SECURE=1，并在代理配置登录限速。应用实例使用 PHP 文件会话。部署多个实例前须准备共享会话存储，切换或清理会话会让使用者重新登录。详细字段见[配置参考](configuration.md)。

## 日常检查

GET /healthz 成功时返回 HTTP 200 和 {"ready":true}。当数据库不可达或迁移不匹配时，HTTP 探针会返回服务不可用状态。常规记录包括已应用的迁移、导入失败行、待复核账期、封账摘要及工资下载审计。可以从工作台刷新并按回执编号追查，监控应保留这些对象的时间和关联编号。

升级前先在隔离库复验备份。操作顺序见[备份、恢复与升级](backup-and-restore.md)。

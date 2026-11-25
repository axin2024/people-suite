# 运行依赖与材料来源

项目源码及随仓库发布的合成样例使用 [Apache-2.0](../LICENSE)，发行包保留 [NOTICE](../NOTICE)。运行时镜像、数据库服务与测试工具由部署和 CI 环境获取。

| 依赖 | 用途和分发边界 | 上游 |
| --- | --- | --- |
| PHP 8.2 或更高，PDO、pdo_mysql、mbstring | 服务、迁移和演示 CLI。容器使用 PHP 8.4。应用无 Composer 第三方包。 | [PHP 源码与许可](https://github.com/php/php-src/tree/PHP-8.4) |
| MySQL 8.4 | InnoDB 业务库。应用源码仓库不包含 MySQL 二进制。 | [MySQL Community Server](https://dev.mysql.com/downloads/mysql/) |
| Apache HTTP Server | Compose 中的 HTTP 服务。 | [Apache HTTP Server](https://httpd.apache.org/) |
| Python 3 | HTTP 集成测试，仅使用标准库。 | [Python 许可](https://docs.python.org/3/license.html) |
| GitHub Actions PHP 配置 | 远端集成测试中启用 PHP 扩展。 | [setup-php](https://github.com/shivammathur/setup-php) |

| 材料 | 来源 |
| --- | --- |
| 工作台 | 仓库源码中的 HTML、CSS 和原生 JavaScript。浏览器用系统字体。 |
| 演示身份、班表和工资 | 项目自编的合成样例，使用 example.test 邮箱。 |
| PRD、手册和测试 | 项目文档及合成输入。外部参考仅以链接引用。 |

交付源码不包含字体文件、个人数据、模型权重或第三方媒体。使用发行镜像时，各镜像中的系统组件遵循上游许可。

# 备份、恢复与升级

People Suite 把业务事实、历史版本、导入结果和审计记录保存到 MySQL。备份还应记录应用 Git 版本、数据库实例版本和部署配置来源。配置里的连接密码应单独保管。

## 导出

使用 MySQL 8.4 客户端。数据库名称和连接地址以实际部署为准。此例在本机备份 people_suite。

```sh
mysqldump --host=127.0.0.1 --user=backup_user --password \
  --single-transaction --no-tablespaces --set-gtid-purged=OFF \
  --default-character-set=utf8mb4 --result-file=people-suite.sql people_suite
```

保留整个导出文件，记录文件摘要、导出时间和对应 Git 标签。在导出结束前暂停迁移和其他 DDL。工资单、绩效和身份字段随数据库一起导出，备份文件应按原数据的访问范围保存。

## 恢复到独立数据库

先创建独立空库和恢复用账号，字符集为 utf8mb4。用与备份相同的应用版本连接恢复库。示例中的恢复库为 hcm_demo_recovery。

```sh
mysql --host=127.0.0.1 --user=restore_user --password \
  --default-character-set=utf8mb4 hcm_demo_recovery < people-suite.sql
```

PowerShell 下可在 mysql 交互客户端运行 SOURCE，传入 SQL 文件所在路径。恢复期间让应用继续指向原库或保持停止，恢复环境须有独立的连接配置。

应用设置好指向恢复库的 HCM_DSN、HCM_DB_USER 和 HCM_DB_PASSWORD 后，运行 php bin/hcm.php health。使用演示数据时，再运行 php bin/hcm.php demo-check 并输入演示口令，比较恢复前后的两个封账摘要、工资单编号、4 月两部门分段和 5 月唯一的补差行。对实际数据可按相同方法抽核工资单和授权范围，间隔至少覆盖一个已封账月份及对应追溯调整。

## 升级

1. 在独立环境恢复当前版本备份，保留原版本下得到的健康回执、历史工资单和封账摘要。
2. 部署待升级代码，运行 php bin/hcm.php migrate，然后运行 php bin/hcm.php health。迁移文件含历史摘要，已安装的文件保持内容一致。
3. 回查升级前的历史数据，确认已封账结果和摘要一致，再试跑新账期与导入任务。
4. 正式升级安排停止写入窗口。记录数据库备份位置和应用版本，按相同顺序迁移和验证。需要回退时，使用升级前应用与备份恢复到独立库，验证后再切换。升级窗口内已进入新库的写入须在切换前对账。

MySQL DDL 可以在迁移中间提交。遇到迁移失败时，记录最后一条报错并核对已执行语句，在隔离库验证修复。完整回退使用升级前的备份。

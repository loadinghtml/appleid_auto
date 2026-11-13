-- 已有 v2 数据库执行一次；新安装请使用 db/db.sql，无须重复执行此脚本。
-- 原有分享页保持开启；API 白名单为空，默认拒绝所有来源，需在编辑页面配置。
ALTER TABLE `share`
  ADD COLUMN `enable` tinyint(1) NOT NULL DEFAULT '1',
  ADD COLUMN `api_allowed_origins` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

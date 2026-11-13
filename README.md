<h1 align="center">Apple ID 自动化管理</h1>
<p align="center">
    <a href="https://github.com/pplulee/appleid_auto/issues" style="text-decoration:none">
        <img src="https://img.shields.io/github/issues/pplulee/appleid_auto.svg" alt="GitHub issues"/>
    </a>
    <a href="https://github.com/pplulee/appleid_auto/stargazers" style="text-decoration:none" >
        <img src="https://img.shields.io/github/stars/pplulee/appleid_auto.svg" alt="GitHub stars"/>
    </a>
    <a href="https://github.com/pplulee/appleid_auto/network" style="text-decoration:none" >
        <img src="https://img.shields.io/github/forks/pplulee/appleid_auto.svg" alt="GitHub forks"/>
    </a>
    <a href="https://github.com/pplulee/apple_auto/blob/main/LICENSE" style="text-decoration:none" >
        <img src="https://img.shields.io/github/license/pplulee/appleid_auto" alt="GitHub license"/>
    </a>
</p>
<h3 align="center">中文文档 | <a href="README_en.md">English</a> </h3>
<h3 align="center">请仔细阅读本文档以及使用说明，再使用。</h3>
<h3 align="center">使用本项目可能需要一定的基础知识。</h3>

---

## 本项目已停止维护，若要继续使用，可更换至[AppleAutoPro](https://appleauto.pro/)

# 基本简介

“以全新方式管理你的 Apple ID” —— 这是一款基于密保问题的自动化 Apple ID 检测&解锁程序。

前端用于管理账号，支持添加多个账号，并提供展示账号页面；

支持创建包含多个账号的分享页面，并可以为分享页面设置密码。

后端定时检测账号是否被锁定，若被锁定或开启二步验证则自动解锁，修改密码并向API回报密码。

登录Apple ID并自动删除Apple ID中的设备。

启用代理池和Selenium集群，提高解锁成功率，防止风控。

# 项目特点

- 多用户使用，权限控制
- 多账号管理
- 账号分享页，支持设置密码、有效期、自定义HTML内容
- 自动解锁与关闭二步验证
- 自动/定时修改密码
- 自动删除Apple ID中的设备
- 代理池与Selenium集群，提高解锁成功率
- 允许手动触发解锁
- ……

# 分享页开关与分享API域名白名单

用户中心和管理员面板的分享页编辑页面提供「启用分享页」开关。关闭后，普通分享页、分享API以及该分享页的手动解锁入口均不可用；重新勾选并保存即可恢复。过期的分享页仍会被拒绝。

每个分享页可单独配置分享API域名白名单，每行填写一个完整来源，例如：

```text
https://example.com
https://app.example.com:8443
```

协议、域名和端口必须精确匹配；不支持通配符、路径、账号信息，子域名需单独添加。默认端口会自动归一化。白名单留空、请求未带 `Origin` 或来源不匹配时，分享API返回 HTTP 403。白名单只限制分享API，不限制普通分享页访问；原有分享密码仍需提供。

分享API支持 GET、POST 和 OPTIONS 跨域预检，仅对白名单内的来源返回对应的 CORS 许可，不支持携带 Cookie 的跨域调用。浏览器跨域调用示例：

```javascript
// 在已加入白名单的网站页面中运行；分享页无密码时省略密码路径段。
const response = await fetch('https://share.example.com/shareapi/分享代码/分享密码', {
  credentials: 'omit'
});
if (!response.ok) throw new Error('分享API访问被拒绝');
const data = await response.json();
```

浏览器自动设置 `Origin`，页面代码无需手动设置。同源 GET 请求可能不发送 `Origin`，此时也会被拒绝。域名白名单用于浏览器来源限制，不能替代身份认证；脚本或服务器程序可以伪造 `Origin`，不要将此功能当作调用方身份保证。有关浏览器行为参见 [MDN Origin](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Origin) 和 [MDN CORS](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CORS)。

## 已有数据库升级

先备份数据库，在维护窗口对现有 v2 数据库执行一次 `db/update/share_access_controls.sql`，然后部署新代码。新安装使用更新后的 `db/db.sql`，不要再重复执行升级脚本。升级会保留已有分享页的开启状态；已有分享API的白名单为空，升级后须逐个配置允许来源才能恢复调用。无需更新后端管理程序。

若反向代理已有全局 CORS 配置，应移除分享API上的 `Access-Control-Allow-Origin: *` 等覆盖规则，让应用按白名单返回响应头；同时避免对分享页和分享API开启代理缓存。已被访问者获取的账号资料无法通过停用分享页撤回。

## 回归验证

安装 Composer 依赖后运行 `php tests/share_access.php`（需要 `pdo_sqlite`）。测试只使用内存数据库，覆盖白名单校验、CORS 预检、分享启停、密码与有效期、账号归属及真实路由，不连接应用数据库。

# 问题反馈&交流

开发者水平和能力有限，程序可能存在诸多bug，欢迎提出 Issue 或 Pull Request ，也欢迎各位大佬加入项目，贡献代码！ \
Telegram群：[@appleunblocker](https://t.me/appleunblocker)

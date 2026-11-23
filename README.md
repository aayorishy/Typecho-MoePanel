# MoePanel 1.0.0

> 面向 Typecho 的 KOOK 一体化可视化控制台。

- 名称：MoePanel
- 版本：1.0.0
- 作者：ayorishy
- 仓库：<https://github.com/aayorishy/Typecho-MoePanel>

## 安装

1. 备份现有插件目录。
2. 将压缩包内的 `MoePanel` 放到：

   ```text
   usr/plugins/MoePanel
   ```

3. 在 Typecho 插件页面启用 MoePanel。
4. 如果服务器仍然保留旧 `StudioPanel` 激活记录，将 `StudioPanel` 兼容目录一并放到 `usr/plugins/StudioPanel`，刷新插件页迁移后再删除。
5. 重启 KOOK Bot：

   ```bash
   php usr/plugins/MoePanel/kook-bot.php
   ```

## 服务器要求

- Typecho 1.3+
- PHP 8.1+
- cURL
- ZipArchive（自动更新）
- 无需 GD / FreeType：欢迎消息使用纯文字 KMarkdown
- 插件目录写权限（自动更新）

## 本次修复

### 角色扮演

- DeepSeek 设置改为专用接口保存/读取
- 修复弹窗修改后未真正保存到服务器的问题
- 支持 `(met)机器人ID(met)`、mention 数组、`@机器人昵称`
- AI 入口与角色/欢迎事件分离，不再被其他模块异常阻断
- 消息发送 JSON 失败自动改用表单
- 上下文按「服务器 + 用户」连续保存
- 可选 AI 频道自动回复，无需 @
- 新增启动、事件、AI 入口日志

### 欢迎设置

- 纯文字 KMarkdown 欢迎，不再生成图片或卡片
- 欢迎文字模板独立编辑
- 支持 `{user}`、`{site}`、`{count}` 变量
- 新成员加入时直接发送文字消息
- 测试按钮发送纯文字欢迎
- 欢迎日志

### 身份组领取

- 按钮点击者从 `extra.body.user_id` 读取
- 服务器 ID 兼容 `target_id` / `extra.guild_id`
- role_id 使用整数并通过 JSON 提交
- 领取、移除都记录 KOOK 返回
- 日志：`/tmp/moepanel-role-claim.log`

### 后台

- Mac 风格主题
- 概览布局
- 个人资料页
- 文章编辑器标题字距、专注模式、全屏、KOOK 图片上传
- 移除通用卡片
- 主题选择、更新检测、关于插件

## 日志

```text
/tmp/moepanel-ai-chat.log
/tmp/moepanel-events.log
/tmp/moepanel-welcome.log
/tmp/moepanel-role-claim.log
```

## 排查顺序

1. 确认 Bot 进程命令是 `usr/plugins/MoePanel/kook-bot.php`
2. 查看启动日志是否有 `Bot 启动 MoePanel 1.0.0`
3. 身份组查看 `moepanel-role-claim.log`
4. 欢迎查看 `moepanel-welcome.log`
5. 角色扮演查看 `moepanel-ai-chat.log`
6. 欢迎消息不再依赖 GD，无需检查图片扩展

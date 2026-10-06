# 更新日志

> **权威版本是站内的 [assets/docs/更新日志.md](assets/docs/更新日志.md)** —— 它面向用户，只写「对用户可见的变化」，随站点一起发布。本文件只做一个索引，避免两处内容打架。

## 版本索引

| 版本 | 日期 | 要点 |
|---|---|---|
| v3.18.0 | 2026-10-06 | 全站对象编号：新增 app/oid.php（14 位编码 = YYYYMMDD + 2 位类型码 + 4 位随机；类型码 comments=01 / discipline_reports=02 / messages=03 / ai_messages=04 / works=05 / feedback=06 / announcements=07；oid_generate 生成前查重，各类型对应唯一表 →「表内不重复」即「全站不重复」）。迁移 v23 给 7 张业务表加 oid 列 + uk_oid 唯一索引，并按各行创建日期回填历史。写入点接入：helpers.php 的 db_insert_norm（评论/反馈共用）、api/lobby.php（世界对话）、api/ai.php（ai_store_row，AI 对话）、app/discipline.php（违纪通报）、app/feed_client.php（作品）、api/admin.php（公告）、app/peer_sync.php（互通导入时本站自铸编号，不沿用对端）。读取层下发 oid，前台（评论/世界对话/AI 对话/通报/作品/反馈）与面板（作品/通报/回收站列表）经 core.js 的 oidTag() 展示。新增 tools/verify_oid.php（20 项，桩件驱动）。**网页异常监测（Web APM）**：新增 assets/js/monitor.js（独立 ES5 SDK，兼容低版本内核；采集 JS 错误 / 未处理 Promise / 资源失败 / 接口 XHR+fetch 耗时 / 页面性能(PV+timing+FCP+LCP) / 环境，批量上报 sendBeacon▸XHR，5s 同类去重，上报前脱敏）+ api/monitor.php（collect 免登录静默 failsafe；stats/events/settings/alerts/purge 仅总管理员+面板验证）+ app/monitor.php（mon_clean 脱敏 / mon_window / mon_apdex / mon_alert 1 小时去重）+ 迁移 v24（web_events 明细 / web_alerts 告警，非编号表）+ 前端独立页面 pages/monitor.js（app.js 路由 monitor + 抽屉「异常监测」入口，与 panel 同属 mine 页；data-mon 固定蓝白/蓝黑两套配色、不引用站点主题变量；build.py ORDER 登记）+ index.php 注入 __MON_CFG__ 与 monitor.js + app.css 样式。新增 _t/e2e_monitor.php（20 项，真库）。APP_VERSION 3.18.0 |
| v3.17.1 | 2026-10-06 | 管理员可给用户改名：api/admin.php 新增 user_rename（仅主管理员；strip_invisible(nfc_normalize()) + norm_username 校验长度 2–64 / 保留名 / 重名唯一；role=subadmin 时同步 sub_admins）。app/discipline.php 的 discipline_item() 增补 username_now（该 user_id 的当前用户名）。前端 core.js 新增 namePair() 并把 userName() 加第 4 参 nowName、prompt_() 加 defaultValue 参数；violation.js 通报详情与列表、panel.js 通报列表改用「原名 + 现名」；panel.js 用户列表每行加「改名」按钮（prompt_ 预填当前名）。APP_VERSION 3.17.1 |
| v3.16.10 | 2026-10-04 | 修复互通导入的致命误判：app/peer_sync.php 的 sync_apply 原用 `(int)$v === 0` 判「引用是否为 0」，而 gid 是十六进制字符串，以 0 开头时 `(int)` 得 0 → 被当成「本来就是 0」直接落库 → work_votes 两条外键都写 0，撞 uk_work_user(work_id,user_id) 的唯一键 1062 → 整轮 apply 抛异常 → 排在 work_votes 之后的 feedback / discipline_reports / banned_ips 全都没同步（用户看到的现象是「违纪通报和反馈没同步」）。改法：新增纯函数 sync_ref_gid()（null=本来就是 0；非空串=待解析 gid；''=形态不合法），把「解析 gid」与「是否为 0」彻底分开；并给每行写入套 try/catch，失败计入 failed 后继续，单行不再拖垮整轮；gid 表缺 _gid 也改为跳过而非插入。failed / skipped 经 api/peer.php 回传，写进 peers.last_status（有跳过时显示「ok（跳过 N 行）」），面板 toast 同步展示。新增 tools/verify_peer_sync.php（29 项，不连库）：gid 以 0 开头的回归 + 内容表覆盖 + 源码闸门。APP_VERSION 3.16.10 |
| v3.16.9 | 2026-10-04 | 「关于」页 renderAbout 在「参考与借鉴」之后、「鸣谢」之前补回「规划和制作」卡片（两条 credit-row：规划 = 银色子弹-silver、glm-5.3-flash；制作 = deepseek-v4.1-flash、glm-5.3-flash）。APP_VERSION 3.16.9 |
| v3.16.8 | 2026-10-04 | ①修复「AI 重审通过后消息仍发不出」：api/recheck.php 原写 `$uid = (int)require_member()`，身份数组被强转为 1，凭证签发给 uid=1，而 comments.php / lobby.php 按 `actor_uid($id)` 查键 → 永远取不到 → 再审仍被拦。改为 `actor_uid(require_member())`，并在 tools/verify_textguard.php 加第 6 节回归闸门（凭证按 uid 绑定、不跨用户、不跨内容 + 源码不得再出现 `(int)require_member`），17 项 → 23 项；②榜单分页 rank.js：`.rank-more` 新增「加载更多」按钮（#rankMore，paintFoot 控制显隐/置灰），自动加载改 IntersectionObserver（rootMargin 700px，默认视口根）替代单纯滚动比对，滚动兜底阈值 360 → 800；③从详情返回自动定位：rankRow 记 `data-cid` 并写模块级 pendingFocus(+时间戳，10 分钟内有效)，container.__onResume → locateFocus() 未加载到时按需续拉（最多 12 页），focusRow() 平滑滚到中间并加 .rank-flash（1.6s 一次性高亮，prefers-reduced-motion 下关闭）。APP_VERSION 3.16.8 |
| v3.16.7 | 2026-10-04 | 控制面板「社区凭证」新增「扫码填写」：panel.js 的 tokScanBtn + tokQR 面板，调 auth.kimi.com 的 CreateLoginQRCode / GetLoginQRCodeStatus（免鉴权、CORS *），二维码内容与官网一致 `https://www.kimi.com/wechat/mp/auth?id=<code>&device_id=<webId>`，微信 / Kimi App 通用；轮询走 Web Worker 心跳（startHeartbeat 式 Blob Worker + visibilitychange 补拉 + 时间戳过期判定），SUCCESS 时把 accessToken 填入 #tokInput，由用户点「仅保存凭证」落库。新增 tokqr* 系列函数与 .tok-row/.qr-box/.qr-hint 样式。APP_VERSION 3.16.7 |
| v3.16.6 | 2026-10-04 | ①评分方式默认改为「只更新信息」：app/scoring.php 的 score_auto_enabled() 默认值 '1'→'0'；②副管理员不可再切换：api/admin.php 的 $SUB_ALLOWED 去掉 'score_mode_set'，该动作改为 require_admin()+require_panel()，panel.js 的 bindScore() 对副管理员置只读；③同步更新全部公开文档（功能说明 / AI 使用说明 / 隐私政策 / 用户协议 / 入榜规则 / 评分标准 / 社区公约）。APP_VERSION 3.16.6 |
| v3.16.5 | 2026-10-04 | 移除问答缓存：api/ai.php 的 search_ai / send_sync / send 三处 ai_cache_get·ai_cache_put·ai_cache_only 调用全删（并去掉只为缓存键服务的 COUNT 查询与 $usedTools 标记），app/openrouter.php 删除 ai_ctx_empty / ai_cache_get / ai_cache_put / ai_cache_only 四个函数。原因：会话无上下文时命中 ai_answer_cache 会把旧答案原样回放，即用户看到的「AI 复读」。表 ai_answer_cache 保留但不再读写。APP_VERSION 3.16.5 |
| v3.16.4 | 2026-10-04 | 修复「被封账号登录只报错、进不了全屏封禁界面」：①app/auth.php 登录不再因 users.is_banned=1 直接 fail(403)（改为允许登录 + 到期顺带自动解封）；②current_user_row() 去掉 `AND is_banned = 0`（否则身份载荷 401「账号不可用」，封禁信息取不到）；③app.js syncBanLock() 的登录页豁免改为仅在 role==='guest' 时生效。封禁拦截仍由 bootstrap.php 的 discipline_visitor_blocked() 统一负责。APP_VERSION 3.16.4 |
| v3.16.3 | 2026-10-04 | ①「关于」页 renderAbout 新增「鸣谢」卡片（反馈贡献者名单）；②官方 AI 去客服腔：lobby_ai.php 重写 lobby_ai_system()（老玩家性格 + 防复读铁律 + 禁用语 + 不硬编），lobby_ai_messages() 由「历史压成一段文本」改为真·多轮（system 最前 + AI 回复当 assistant + 本次提问去重）。APP_VERSION 3.16.3 |
| v3.16.2 | 2026-10-04 | ①纠正审核误判：jev.php 的 intent 维度改 3 选项（攻击 / 正常 / 纯技术讨论）+ jev_inject_flag()（技术讨论概率达标即不判），提示词注入由「抬档 7.0 拦截」改为「只标注不拦截」（jev_lift_inject / jev_inject_level 移除），讽刺标注阈值 0.85→0.95（修复 @官方AI 2333 被误标）；②chat_media.php 新增 CHAT_MEDIA_GRACE=1800s，刚上传未发送的图不再被 chat_media_gc 当孤儿删除（修复发送后显示「图片已清理」）。APP_VERSION 3.16.2 |
| v3.16.1 | 2026-10-04 | 修复 lobby.js atRange() 取错捕获组（正则只有 1 个捕获组却读 m[2]）导致世界对话输入 @ 时抛 TypeError、@ 提及面板无法弹出（v3.14.0 引入）。APP_VERSION 3.16.1 |
| v3.16.0 | 2026-10-04 | 审核与封禁增强：①jev.php 新增 intent 维度判提示词注入（jev_lift_inject，命中抬到 7.0 档，阈值 moderation.jev_inject_level / jev_inject_prob）；②moderation.php 二次判断 moderation_context_rows / moderation_context_text（拼接最近短消息）+ moderation_recall_context（拆字骂人连坐撤回，verdict.via=context）+ 刷屏检测 moderation_flood（api/lobby.php 接入）；③封禁反篡改：前端 core.js armBanDefense() 三层（MutationObserver + 1.5s 心跳 + 4s 互校验）+ banWarn 提醒 + 同会话只报一次，后端迁移 v22（discipline_reports.tamper_count / tamper_at）+ discipline_tamper()（前三次放过、其后 +0.05 天）+ api/discipline.php beat 动作（上报字段改为平常名字以避针对性屏蔽）；④api/lobby.php 新增 del（单条消息删除：本人或管理员，官方 AI 消息仅管理员）+ lobby.js 消息旁「删除」按钮；⑤图片外链经 link_guard_check，命中 fail(422) 走审核通知条（lobby.js rejectNote 支持图片）。APP_VERSION 3.16.0 |
| v3.15.0 | 2026-10-04 | 违纪通报增强：discipline_item() 统一输出（含 alive 状态）/ discipline_latest() / discipline_prune(10)（超限删已解封、按 id 升序、同步清 banned_ips）；api/discipline.php 新增 latest、get 补 alive；start.php 下发 ban / disc；前端 core.js 新增 showBanLock / hideBanLock / showDiscPopup / maybeDiscPopup / discStatusText / discDaysText（封禁改全屏遮罩、403 钩子即时上锁、通报弹窗按 id 缓存已看），violation.js 显示封禁天数与解封状态、补充说明改强调块、删总讨论区，app.js 回前台刷新状态；APP_VERSION 3.15.0 |
| v3.14.0 | 2026-10-04 | 世界对话新增「@官方AI」：输入 @ 弹候选 + 输入框旁 @ 按钮；命中后以 glm-4-flash 非流式生成回复，作为 user_id=0、msg_type='ai' 的 AI 消息落库（chat_out 按 msg_type 特判身份，不改表结构）；用量记在发起人名下（ai_usage.user_id=发起人）；@ 高亮复用 .reply-to 样式；新增 app/lobby_ai.php，api/lobby.php 的 send 挂载 |
| v3.13.0 | 2026-10-03 | 新增站点互通（多站互为镜像）：app/peer_crypto.php（Curve25519 + gzip + AEAD，私钥 64 位十六进制，公钥公开）、app/peer_sync.php（声明式表清单 + sync_ids 侧表 gid + 双向补齐 + 墓碑）、api/peer.php（hello / fetch / apply）、api/admin.php 面板动作、面板「站点互通」区块；迁移 v21 仅新增 peers / sync_ids / sync_tombs 三张空表。排除实时性与密钥类数据 |
| v3.12.0 | 2026-10-03 | 去服务端代理：删除 api/img.php，img_src() 改为原样返回，前端不再回退代理（改隐藏裂图），api/admin.php 的 userscript 由服务端中转改 302 跳转；外发收敛：新增 net_budget_allow() 出站预算，fetch_work_html 限 150 次/小时，sync_from_feeds 加最小间隔（feed.min_interval=300s，面板手动刷新传 force 旁路）。适配共享主机防「代理 / 抓取」类滥用封号 |
| v3.11.1 | 2026-10-03 | 与 v3.11.0 代码相同，重新标识修复后的构建。v3.11.0 的修复（补齐 image_audit / jev 两处遗漏的调用点还原，提交 b22c708）此前已合入但构建号未变；本版为与早期构建区分而单独发布 |
| v3.11.0 | 2026-10-03 | 新增本地链接初筛：规律数字域名（重复/回文/连续，限 .com/.cc）+ AdGuard 公开规则集（17 万条，crc32 分片 128 桶索引）；图片与文本发送前拦截；面板可一键更新规则。同时移除出站代理模块 |
| v3.10.0 | 2026-10-03 | 新增 security.outbound_proxy（默认空=直连，供使用者自填固定出口，不做轮换）；出站调用统一经 net_curl_init；审核链路识别 429 并做通道冷却（避免白等超时）；备用视觉模型判定口径改为「拿不准就放行」 |
| v3.9.1 | 2026-10-03 | 修复违纪通报封禁时长恒显示「永久」：通报列表未返回 ban_days / ban_until，且两处读取出参用 (int) 截断小数天 |
| v3.9.0 | 2026-10-02 | 登录限速改为「来源 + 账号」双维度；来源地址默认只信连接地址（转发头不再采信，反代情形需显式配置）；调试/排障入口统一加口令（未配置即不可访问）且安装完成后自动关闭 |
| v3.8.0 | 2026-10-02 | 重阳节主题（农历九月初九，本地换算不依赖第三方）；聊天图片可点开看大图；修复副管理员面板「趋势统计」报错 |
| v3.7.0 | 2026-10-02 | 四个节日主题（含万圣夜强制暗色）；不严重内容改「标注」且管理员可取消；被封可登录但前端锁定+后端全禁；封禁支持小数天；对话图片动态清理；删号级联清 IP 封禁 |
| v3.6.0 | 2026-10-02 | 审核改为 1–10 档违规程度分级：达线才拦，中间档放行并标注「可能有恶意」 |
| v3.5.1 | 2026-10-02 | 修复违纪通报列表页评论区报「参数错误」 |
| v3.5.0 | 2026-10-02 | 登录/注册人机验证（双通道自动切换）；图片上传审核（感知→判断→热备）；文本审核改用轻量分类模型（失败回落原通道） |
| v3.4.0 | 2026-10-02 | 违纪界显示封禁原因/天数/解封时间；被通报用户带标记；通报可二次设置；累计 10 次永久删号；讨论区改为目录级 |
| v3.3.0 | 2026-10-02 | 违纪通报：一键通报用户（多条理由 + 封禁天数 + 封 IP + 清理内容）、302 违纪界面与评论区 |
| v3.2.4 | 2026-10-01 | 修正审核尺度：寒暄不再误拦、AI 重审改为疑罪从无、结论识别支持中文 |
| v3.2.3 | 2026-10-01 | 单份备份上限 256MB → 1GB（按备份文件实际大小判断） |
| v3.2.2 | 2026-10-01 | 自动跳转页（加载动画 + 延时跳转）也能识别；识别范围补上 MiniMax 作品空间 |
| v3.2.1 | 2026-10-01 | 介绍卡型页面也能识别；按钮计数不再受页面嵌套影响 |
| v3.2.0 | 2026-10-01 | 智能链接识别转为正式功能，默认开启 |
| v3.1.0 | 2026-10-01 | AI 重审（三档复核）；作品真实地址识别重写；表情与模型名不再误判 |
| v3.0.1 | 2026-09-30 | 字体与图片加载瘦身：页面请求数大幅下降，减少主机限流提示 |
| v3.0.0 | 2026-09-30 | 国庆专版主题「盛世红金」（9/30–10/8 限时呈现） |
| v2.23.1 | 2026-09-29 | 修复榜单翻页 / 加载更多时的脚本报错 |
| v2.23.0 | 2026-09-29 | 首屏样式随页面下发；品牌字体延后载入并长缓存 |
| v2.22.0 | 2026-09-29 | 首屏请求数 5 → 1；榜单查询降耗约 96%；修复翻页名次错位 |
| v2.21.0 | 2026-09-28 | 目录新增「渗透测试」入口，直达本仓库 |
| v2.20.1 | 2026-09-28 | 修复 AI 助手偶发报错、回复中断 |
| v2.20.0 | 2026-09-27 | 智能链接识别（BETA）；AI 用量排行对副管理员开放 |
| v2.19.0 | 2026-09-27 | 评分方式开关；两处排行支持翻页 |
| v2.18.1 | 2026-09-27 | 关于页管理者名单改为实时读取 |
| v2.18.0 | 2026-09-27 | 数据库备份（仅主管理员） |

完整历史（含每条变更的说明）见站内更新日志。

---

## 版本号约定

`主版本.次版本.修订号`：

- **修订号**：修 bug，不改行为契约；
- **次版本**：加功能，向后兼容；
- **主版本**：破坏性变更。

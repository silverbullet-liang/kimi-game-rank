/**
 * 前端集中常量
 * ------------------------------------------------------------
 * 与后端 app/monitor.php 的 mon_defaults() 保持同一口径：
 * 这里的值只是「接口未返回时的兜底」，调整默认值请优先改后端，两边不要各写一套。
 */

/** 监测模块默认值（对应 monitor.* 系列设置） */
export const MON_DEFAULT = {
  sample: 30,          // 服务端采集采样率（%）
  slowMs: 200,         // 慢查询阈值（ms）
  slowAlertMs: 3000,   // 单请求耗时告警阈值（ms）
  apdexT: 1200,        // Apdex 基线（ms）
  keepDays: 7,         // 明细保留天数
  keepMax: 90,         // 保留天数上限（与后端 mon_keep_range 一致）
};

/** 列表分页与超时 */
export const UI_PAGE = {
  comments: 5,         // 评论首屏根评论数
  repliesFold: 2,      // 楼中楼默认展开条数
  apiTimeout: 20000,   // 默认请求超时（ms）
};

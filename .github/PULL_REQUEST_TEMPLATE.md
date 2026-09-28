## 这个 PR 做了什么


## 为什么


## 怎么验证的

```bash
python3 tools/phpcheck.py
python3 tools/jscheck.py
python3 tools/repo_check.py
```

## 自查

- [ ] 改动 `assets/js/src/` 后已跑 `python3 tools/build.py` 重新打包
- [ ] **没有** 夹带密钥、数据库连接或审核词库
- [ ] 三条自检全部通过
- [ ] 界面改动已附截图

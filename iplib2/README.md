# APNIC 中国地址范围

仅手动更新。平时的页面请求只查询本地库，不会联网或更新数据，也没有定时任务。

仓库不包含 APNIC 原始数据、生成的 `cn-ranges.json` 和编译后的程序。首次使用需要在后台点击更新，或运行下面的脚本生成地址库；没有地址库时，查询返回 false。

## 后台更新

顶部工具栏或「外观」菜单中打开「L-Siren 运维仪表板」，在「基本设置」下点击「更新 IP 库」。结果直接显示在按钮下方，不跳转页面。只有拥有 `manage_options` 权限的管理员可以更新，请求使用 WordPress nonce 校验。

下方的「测试 IP」可以查询当前本地库，支持 IPv4、IPv6、方括号 IPv6 和 IPv4-mapped IPv6。这里只查 IP 范围，不套用浏览器语言条件；不联网，也不会更新数据。查询接口同样要求管理员权限和 nonce。

后台由 `fetch.php` 独立解析数据，不调用 shell、C++ 或 Python，也不需要服务器安装编译器。下载、解析失败时保留旧库。更新完成后显示 APNIC 数据日期、IPv4/IPv6 合并后的网段数量和本地更新时间。

「下载代理」只用于本次操作，不保存到数据库，也不修改 WordPress 的全局代理设置。可填写 `http://host:port`、`https://host:port`、`socks5://host:port` 或 `socks5h://host:port`，支持带用户名和密码的代理。代理地址中的 `127.0.0.1` 指网站服务器。使用代理需要 PHP cURL 扩展；留空不指定代理，仍遵循服务器已有的全局设置。

## SSH 更新

从任何目录运行以下命令，即可下载 APNIC 数据、编译 C++ 处理器并更新查询文件：

```bash
bash /usr/local/lighthouse/softwares/wordpress/wp-content/themes/H-Siren/iplib2/get_latest.sh
```

SSH 更新依赖 Bash、curl、flock 和支持 C++17 的编译器（默认 `c++`，可通过 `CXX` 指定），PHP 端需要 PHP 8.1 或以上。下载只允许 HTTPS，失败会退出。

后台和 SSH 使用同一个 `cn-ranges.json.lock` 互斥锁，从下载开始持锁；解析时检查 APNIC 记录总数、地址范围及 CN IPv4/IPv6 数据是否齐全。成功后用同一文件系统内的 rename 原子替换 `cn-ranges.json`，查询无需等待更新。请保留 PHP 运行用户对目录和锁文件的写入权限；生成文件的模式为 `0664`，可继承目录里为 root、www 设置的 ACL。

如果自己保留了一份 APNIC 原始数据，也可以离线重建，不下载新数据：

```bash
bash /usr/local/lighthouse/softwares/wordpress/wp-content/themes/H-Siren/iplib2/get_latest.sh \
  --source /path/to/delegated-apnic-latest
```

`--output /path/to/test.json` 可指定独立输出，便于测试；父目录须已存在。下载的原始数据和编译出的程序只存放于临时目录，用完会清理。

`fetch.cpp` 可单独编译，接口为 `./fetch SOURCE OUTPUT`，默认读取当前目录的 `d.txt`，输出 `cn-ranges.json`。需要原子发布时应通过 `get_latest.sh` 运行。

查询文件格式为版本号、APNIC 来源序列号，以及 IPv4/IPv6 两组有序、不重叠的闭区间。区间端点为网络字节序、固定宽度的小写十六进制字符串（IPv4 为 8 位，IPv6 为 32 位）。IPv4 保留完整 32 位，IPv6 不使用可能溢出的 128 位整数。重叠和相邻区间会合并。JSON 的括号、引号均由程序生成，不需要手工处理。

PHP 统一使用 `inc/ip-location.php`：一个请求内只加载一次数据库，并进行二分查找；若 WordPress 已启用持久对象缓存则复用已解析数据，原子替换后自动使用新缓存键。支持普通 IPv6、方括号 IPv6 和 IPv4-mapped IPv6。

`is_china()` 保留原先的浏览器语言条件：`Accept-Language` 前四个字符不区分大小写等于 `zh-c` 才继续查 IP。缺少语言头时返回 false。只读取 `REMOTE_ADDR`，不使用来访者可填写的地理位置或转发请求头。若以后接入反向代理，应在 Web 服务器层正确配置可信代理及真实来源 IP。

目录里保留 `fetch.php`、`fetch.cpp`、`get_latest.sh` 和这份说明。两份处理器遵循同一份 JSON 格式。运行后会生成 `cn-ranges.json` 和零字节的 `cn-ranges.json.lock`；前者用于查询，后者用于防止同时更新，都不提交到仓库。锁文件不要在更新过程中删除。

APNIC 的 CN 表示资源登记时的国家/地区，不能保证使用者的实际位置；见 [APNIC 格式说明](https://www.apnic.net/about-apnic/corporate-documents/documents/resource-guidelines/rir-statistics-exchange-format/)。这套判断用于内容展示，不能作为身份认证或权限检查。

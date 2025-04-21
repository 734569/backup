<?php
session_start();

// 处理退出登录请求
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['logout'])) {
    // 销毁会话
    session_destroy();
    // 返回 JSON 响应
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => '退出成功']);
    exit;
}

// API功能处理（带参数访问直接执行）
if (isset($_GET['bfurl']) && isset($_GET['number'])) {
    // 从GET参数获取备份配置
    $backupUrl = $_GET['bfurl'];
    $backupCount = intval($_GET['number']);

    // 验证备份URL格式
    if (!filter_var($backupUrl, FILTER_VALIDATE_URL)) {
        header('Content-Type: application/json');
        die(json_encode(['错误','无效的URL格式！'], JSON_UNESCAPED_UNICODE));
    }

    // 清理URL并设置超时
    $cleanedBackupUrl = rtrim($backupUrl, '/');
    $timeout = 300;
    $context = stream_context_create(['http' => ['timeout' => $timeout]]);

    // 请求备份文件
    $serverAUrl = $cleanedBackupUrl . '/backup.php';
    $backupFile = @file_get_contents($serverAUrl, false, $context);

    if ($backupFile === false || strpos($backupFile, '无法创建备份文件') !== false) {
        header('Content-Type: application/json');
        die(json_encode(['错误', '请先上传握手文件！'], JSON_UNESCAPED_UNICODE));
    }

    // 处理备份文件存储
    $backupDir = __DIR__;
    if (!is_writable($backupDir)) {
        header('Content-Type: application/json');
        die(json_encode(['错误', '备份目录不可写'], JSON_UNESCAPED_UNICODE));
    }

    // 下载备份文件
    $serverABackupUrl = $cleanedBackupUrl . '/' . $backupFile;
    $backupFilePath = $backupDir . '/' . $backupFile;
    $fileContent = @file_get_contents($serverABackupUrl, false, $context);

    if (@file_put_contents($backupFilePath, $fileContent) === false) {
        header('Content-Type: application/json');
        die(json_encode(['错误', '文件下载失败'], JSON_UNESCAPED_UNICODE));
    }

    // 清理旧备份
    $backupFiles = glob($backupDir . '/backup_*.zip');
    usort($backupFiles, fn($a, $b) => filemtime($b) - filemtime($a));

    if (count($backupFiles) > $backupCount) {
        $filesToDelete = array_slice($backupFiles, $backupCount);
        foreach ($filesToDelete as $file) {
            if (!unlink($file)) {
                error_log("删除失败: $file");
            }
        }
    }

    // 请求删除远程备份
    $deleteUrl = $cleanedBackupUrl . '/backup.php?file=' . urlencode($backupFile);
    $ch = curl_init($deleteUrl);
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_RETURNTRANSFER => true
    ]);

    if (curl_exec($ch) === false) {
        error_log("删除请求失败: " . curl_error($ch));
    }
    curl_close($ch);

    header('Content-Type: application/json');
    die(json_encode(['执行成功', '备份完成'], JSON_UNESCAPED_UNICODE));
}
$Password = '123456';
$backupSuccess = false;

// 设置 file_get_contents 的超时时间（秒）
$timeout = 300;
$context = stream_context_create([
    'http' => [
        'timeout' => $timeout
    ]
]);

// 处理登录
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['password'])) {
    $inputPassword = $_POST['password'];
    if ($inputPassword === $Password) {
        $_SESSION['authenticated'] = true;
    } else {
        $errorMessage = "密码错误，请重试。";
    }
}

// 处理备份设置
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bfurl']) && isset($_POST['number']) && isset($_SESSION['authenticated'])) {
    $backupUrl = $_POST['bfurl'];
    $backupCount = intval($_POST['number']);

    // 验证备份 URL 格式
    if (!filter_var($backupUrl, FILTER_VALIDATE_URL)) {
        $errorMessage = "请输入有效的 URL，需包含 http 或 https。";
    } else {
        // 移除 URL 末尾的 /，确保后续拼接正确
        $cleanedBackupUrl = rtrim($backupUrl, '/');

        // 服务器 A 的备份脚本 URL
        $serverAUrl = $cleanedBackupUrl . '/backup.php';

        // 发起请求获取备份文件
        $backupFile = @file_get_contents($serverAUrl, false, $context);
        if ($backupFile === false) {
            $error = error_get_last();
            $errorMessage = "请检查 backup.php 文件是否已上传至 $cleanedBackupUrl 根目录";
            error_log("无法从 $serverAUrl 获取备份文件");
        } else {
            if (!strpos($backupFile, '无法创建备份文件')) {
                // 检查备份目录是否可写
                $backupDir = __DIR__;
                if (!is_writable($backupDir)) {
                    $errorMessage = "备份目录不可写: ". $backupDir;
                    error_log("备份目录不可写: ". $backupDir);
                } else {
                    // 下载备份文件
                    $serverABackupUrl = $cleanedBackupUrl . '/' . $backupFile;
                    $backupFilePath = $backupDir . '/' . $backupFile;
                    $downloaded = @file_put_contents($backupFilePath, @file_get_contents($serverABackupUrl, false, $context));
                    if ($downloaded === false) {
                        $error = error_get_last();
                        $errorMessage = "下载备份文件失败";
                        error_log("未能下载备份文件");
                    } else {
                        // 获取备份目录下所有的备份文件
                        $backupFiles = glob($backupDir . '/backup_*.zip');
                        usort($backupFiles, function ($a, $b) {
                            return filemtime($b) - filemtime($a);
                        });

                        // 只保留最近的指定数量份备份文件
                        if (count($backupFiles) > $backupCount) {
                            $filesToDelete = array_slice($backupFiles, $backupCount);
                            foreach ($filesToDelete as $file) {
                                if (!unlink($file)) {
                                    $error = error_get_last();
                                    error_log("多余备份文件删除失败");
                                }
                            }
                        }

                        // 向服务器 A 发送删除请求
                        $serverBUrl = $cleanedBackupUrl . '/backup.php';
                        $deleteUrl = $serverBUrl . '?file=' . urlencode($backupFile);
                        $ch = curl_init($deleteUrl);
                        // 设置 curl 超时时间
                        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        if ($response === false) {
                            $error = curl_error($ch);
                            $errorMessage = "向服务器发送删除请求失败";
                            error_log("未能发送删除请求");
                        } else {
                            $backupSuccess = true;
                        }
                        curl_close($ch);
                    }
                }
            } else {
                $errorMessage = "备份失败";
            }
        }
    }

    // 处理完成后返回JSON
    header('Content-Type: application/json');
    if ($backupSuccess) {
        echo json_encode(['status' => 'success', 'message' => '备份成功']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $errorMessage]);
    }
    exit;
}

// 处理文件删除请求
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_file']) && isset($_SESSION['authenticated'])) {
    $fileToDelete = __DIR__ . '/' . $_POST['delete_file'];
    if (file_exists($fileToDelete) && unlink($fileToDelete)) {
        echo json_encode(['status' => 'success', 'message' => '文件删除成功']);
    } else {
        echo json_encode(['status' => 'error', 'message' => '文件删除失败']);
    }
    exit;
}

// 获取本地备份文件列表
function getLocalBackupFiles() {
    $backupDir = __DIR__;
    $backupFiles = glob($backupDir . '/backup_*.zip');
    $filesInfo = [];
    foreach ($backupFiles as $file) {
        $filesInfo[] = [
            'name' => basename($file),
            'time' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }
    usort($filesInfo, function ($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });
    return $filesInfo;
}

// 获取当前文件的 URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$port = $_SERVER['SERVER_PORT'];
$uri = $_SERVER['REQUEST_URI'];

// 判断是否需要添加端口号
if (($protocol === 'http' && $port != 80) || ($protocol === 'https' && $port != 443)) {
    $host .= ':' . $port;
}

$currentUrl = $protocol . '://' . $host . $uri;

// 示例参数
$exampleBackupUrl = 'https://example.com';
$exampleBackupCount = 3;

// 构建带参数的 URL
$currentUrlWithParams = $currentUrl . (strpos($currentUrl, '?') === false? '?' : '&') . 'bfurl='. $exampleBackupUrl. '&number='. $exampleBackupCount;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>备份管理系统</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://fastly.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
       .gradient-bg {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        }
        /* 为表格添加悬停效果 */
        table tbody tr:hover {
            background-color: #f3f4f6;
        }
        /* 优化表格在移动端的显示 */
        @media (max-width: 640px) {
            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            table thead {
                display: none;
            }
            table tbody tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid #e5e7eb;
                border-radius: 0.375rem;
            }
            table tbody td {
                display: block;
                padding: 0.5rem 1rem;
                text-align: left;
            }
            table tbody td:before {
                content: attr(data-label);
                float: left;
                font-weight: bold;
                text-transform: uppercase;
            }
        }
       .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
       .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 800px;
            border-radius: 0.375rem;
        }
       .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
       .close:hover,
       .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body class="gradient-bg">
    <!-- 导航栏 -->

    <div class="flex-grow flex items-center justify-center p-4">
        <?php if (!isset($_SESSION['authenticated'])): ?>
            <!-- 登录界面 -->
            <div class="w-full max-w-md bg-white rounded-xl shadow-2xl p-8 transition-all duration-300 hover:shadow-3xl">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold text-gray-800 mb-2">🔒 系统登录</h1>
                    <p class="text-gray-500">请输入管理员密码访问控制面板</p>
                </div>
                <form id="loginForm" method="post" class="space-y-6">
                    <div>
                        <input type="password" name="password" 
                               class="w-full px-4 py-3 rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                               placeholder="请输入密码" required>
                    </div>
                    <button type="submit" 
                            class="w-full bg-blue-500 hover:bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg transition-colors duration-200 transform hover:scale-[1.02]">
                        🚪 登录系统
                    </button>
                </form>
            </div>
        <?php else: ?>
            <!-- 备份界面 -->
            <div class="w-full max-w-2xl bg-white rounded-xl shadow-2xl p-8">
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800 mb-2">📦 备份管理</h1>
                        <p class="text-gray-500">PHP备份系统配置面板</p>
                    </div>
                    <button id="logoutButton" class="bg-red-500 hover:bg-red-600 text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-200 transform hover:scale-[1.02]">
                        退出登录
                    </button>
                </div>
                <form id="backupForm" method="post" class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">🌐 输入备份网站</label>
                        <div class="flex items-center">
                            <input type="url" id="bfurl" name="bfurl" 
                                   class="flex-1 px-4 py-3 rounded-l-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                   placeholder="https://example.com" required
                                   pattern="https?://.+">
                            <button type="button" id="helpButton" class="bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-r-lg">
                                使用说明
                            </button>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">请输入完整的 http 或 https 协议地址</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">🗃️ 本地保留份数</label>
                        <input type="number" id="number" name="number" 
                               class="w-full px-4 py-3 rounded-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                               min="1" max="30" value="3" required>
                        <p class="mt-1 text-sm text-gray-500">建议保留 3 - 5 份备份以保证存储空间</p>
                    </div>

                    <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-4 rounded-lg transition-colors duration-200 transform hover:scale-[1.02]">
                        🚀 立即执行备份
                    </button>
                </form>
                <!-- 下载按钮 -->
<button onclick="downloadBackupFile()" class="block w-full bg-yellow-500 hover:bg-yellow-600 text-white font-semibold py-3 px-4 rounded-lg transition-colors duration-200 transform hover:scale-[1.02] text-center mt-4">
    📥 下载握手文件
</button>

<script>
function downloadBackupFile() {
    // 创建一个隐藏的iframe来触发下载
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = 'backup.php?download=1';
    document.body.appendChild(iframe);
    
    // 添加一个定时器，稍后移除iframe
    setTimeout(() => {
        document.body.removeChild(iframe);
    }, 1000);
}
</script>
                <!-- 显示当前文件的 URL 并添加复制按钮 -->
                <br>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">替换https://example.com为要备份的网址 宝塔计划任务定时访问 URL 以执行定期备份</label>
                    <div class="flex items-center">
                        <input type="text" id="current-url" value="<?php echo $currentUrlWithParams; ?>" readonly
                            class="flex-1 px-4 py-3 rounded-l-lg border border-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                        <button onclick="copyUrl()"
                            class="bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-r-lg">复制 URL</button>
                    </div>
                </div>

                <script>
                    // 获取输入框和URL显示框
                    const bfurlInput = document.getElementById('bfurl');
                    const urlInput = document.getElementById('current-url');
                    const exampleUrl = '<?php echo $exampleBackupUrl; ?>';
                    const baseUrl = '<?php echo $currentUrl; ?>';
                    const exampleCount = '<?php echo $exampleBackupCount; ?>';

                    // 更新URL显示框的函数
                    function updateUrl() {
                        const inputUrl = bfurlInput.value;
                        const newUrl = baseUrl + (baseUrl.includes('?')? '&' : '?') + 'bfurl=' + inputUrl + '&number=' + exampleCount;
                        urlInput.value = newUrl;
                    }

                    // 监听输入框的变化事件
                    bfurlInput.addEventListener('input', updateUrl);

                    // 复制 URL 函数
                    function copyUrl() {
                        const urlInput = document.getElementById('current-url');
                        urlInput.select();
                        urlInput.setSelectionRange(0, 99999);

                        try {
                            if (navigator.clipboard) {
                                navigator.clipboard.writeText(urlInput.value)
                                   .then(() => {
                                        Swal.fire({
                                            icon:'success',
                                            title: '复制成功',
                                            text: '请添加宝塔计划任务定时URL访问即可实现备份！',
                                            confirmButtonColor: '#10B981'
                                        });
                                    })
                                   .catch(err => {
                                        fallbackCopy(urlInput);
                                    });
                            } else {
                                fallbackCopy(urlInput);
                            }
                        } catch (err) {
                            fallbackCopy(urlInput);
                        }
                    }

                    // 老式复制方法作为备选
                    function fallbackCopy(input) {
                        try {
                            const successful = document.execCommand('copy');
                            if (successful) {
                                Swal.fire({
                                    icon:'success',
                                    title: '复制成功',
                                    text: '请添加宝塔计划任务定时URL访问即可实现备份！',
                                    confirmButtonColor: '#10B981'
                                });
                            } else {
                                throw new Error('复制失败');
                            }
                        } catch (err) {
                            Swal.fire({
                                icon: 'error',
                                title: '复制失败',
                                text: '请手动选择并复制 URL',
                                confirmButtonColor: '#EF4444'
                            });
                        }
                    }
                </script>
                <div class="mt-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">本地备份文件</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto">
                            <thead>
                                <tr>
                                    <th class="px-4 py-2 border bg-gray-100">文件名</th>
                                    <th class="px-4 py-2 border bg-gray-100">备份时间</th>
                                    <th class="px-4 py-2 border bg-gray-100">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $backupFiles = getLocalBackupFiles();
                                foreach ($backupFiles as $file) {
                                    echo '<tr>';
                                    echo '<td data-label="文件名" class="px-4 py-2 border">'. $file['name'] .'</td>';
                                    echo '<td data-label="备份时间" class="px-4 py-2 border">'. $file['time'] .'</td>';
                                    echo '<td data-label="操作" class="px-4 py-2 border">';
                                    echo '<a href="'. $file['name'] .'" download class="bg-blue-500 hover:bg-blue-600 text-white py-1 px-2 rounded mr-2">下载</a>';
                                    echo '<button onclick="deleteFile(\''. $file['name'] .'\')" class="bg-red-500 hover:bg-red-600 text-white py-1 px-2 rounded">删除</button>';
                                    echo '</td>';
                                    echo '</tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 使用说明模态框 -->
    <div id="helpModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>使用说明</h2>
            <p>注意：本工具为开源单php文件，仅可备份网站源码，可能涉及到读写、删除数据的功能！</p>
            <p>适用于2台服务器之间进行网站备份操作！即A服务器运行本系统将B服务器站点数据备份到A服务器</p>
            <p>1.先将 backup.php 握手文件，上传至你要执行备份的网站根目录下，通过握手文件进行相应操作。</p>
            <p>2.然后输入完整的带有 http 或 https 协议的网址。例如：https://example.com。请确保输入的网址是有效的，否则系统将无法正常进行备份。</p>
            <p>3.利用宝塔计划任务定时访问功能可以实现将目标网站定期备份，保留指定备份版本数量功能！</p>
            <p>--定时备份需访问 <?php echo $currentUrlWithParams; ?></p>
            <p>--number=3 为当前服务器保存的备份文件数量！</p>
            <p>4.请自行保管当前php文件及其握手文件。[基于安全考虑可以修改文件名和密码]</p>
            <p>5.因使用本系统造成的数据丢失、安全风险由你自行承担！</p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 处理登录表单
            <?php if (isset($errorMessage)): ?>
                Swal.fire({
                    icon: 'error',
                    title: '登录失败',
                    text: '<?= addslashes($errorMessage) ?>',
                    confirmButtonColor: '#3B82F6'
                });
            <?php endif; ?>

            // AJAX表单处理
            const handleFormSubmit = (formId, successCallback) => {
                const form = document.getElementById(formId);
                if (form) {
                    form.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const formData = new FormData(form);
                        const submitBtn = form.querySelector('button[type="submit"]');

                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '⏳ 处理中...';

                        try {
                            const response = await fetch('', {
                                method: 'POST',
                                body: formData
                            });

                            if (formId === 'backupForm' || formId === 'cronForm') {
                                const result = await response.json();
                                if (result.status ==='success') {
                                    Swal.fire({
                                        icon:'success',
                                        title: '操作成功',
                                        text: result.message,
                                        confirmButtonColor: '#10B981'
                                    }).then(() => {
                            window.location.reload();
                        });
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: '操作失败',
                                        text: result.message,
                                        confirmButtonColor: '#EF4444'
                                    });
                                }
                            } else {
                                // 登录表单处理
                                if (response.ok) {
                                    window.location.reload();
                                }
                            }
                        } catch (error) {
                            Swal.fire({
                                icon: 'error',
                                title: '网络错误',
                                text: '请求发送失败，请检查网络连接',
                                confirmButtonColor: '#3B82F6'
                            });
                        } finally {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = formId === 'loginForm'? '🚪 登录系统' : (formId === 'backupForm'? '🚀 立即执行备份' : '⏲️ 设置定时任务');
                        }
                    });
                }
            }

            handleFormSubmit('loginForm');
            handleFormSubmit('backupForm');
            handleFormSubmit('cronForm');

            // 删除文件函数
            async function deleteFile(fileName) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'delete_file=' + encodeURIComponent(fileName)
                    });
                    const result = await response.json();
                    if (result.status ==='success') {
                        Swal.fire({
                            icon:'success',
                            title: '操作成功',
                            text: result.message,
                            confirmButtonColor: '#10B981'
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: '操作失败',
                            text: result.message,
                            confirmButtonColor: '#EF4444'
                        });
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: '网络错误',
                        text: '请求发送失败，请检查网络连接',
                        confirmButtonColor: '#3B82F6'
                    });
                }
            }
            window.deleteFile = deleteFile;

            const logoutButton = document.getElementById('logoutButton');
            if (logoutButton) {
                logoutButton.addEventListener('click', async () => {
                    try {
                        const response = await fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'logout=true'
                        });
                        const result = await response.json();
                        if (result.status ==='success') {
                            Swal.fire({
                                icon:'success',
                                title: '退出成功',
                                text: result.message,
                                confirmButtonColor: '#10B981'
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: '退出失败',
                                text: result.message,
                                confirmButtonColor: '#EF4444'
                            });
                        }
                    } catch (error) {
                        Swal.fire({
                            icon: 'error',
                            title: '网络错误',
                            text: '请求发送失败，请检查网络连接',
                            confirmButtonColor: '#3B82F6'
                        });
                    }
                });
            }

            const helpButton = document.getElementById('helpButton');
            const helpModal = document.getElementById('helpModal');
            const closeBtn = document.getElementsByClassName('close')[0];

            if (helpButton) {
                helpButton.addEventListener('click', () => {
                    helpModal.style.display = 'block';
                });
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    helpModal.style.display = 'none';
                });
            }

            window.onclick = function(event) {
                if (event.target == helpModal) {
                    helpModal.style.display = 'none';
                }
            }
        });
    </script>
</body>
</html>    
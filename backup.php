<?php
// 处理下载请求
if (isset($_GET['download'])) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="backup.php"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize(__FILE__));
    readfile(__FILE__);
    exit;
}
if (isset($_GET['file'])) {
    $backupFile = $_GET['file'];
    // 清理文件名中的不可见字符
    $backupFile = preg_replace('/[\x00-\x1F\x7F]/u', '', $backupFile);
    $backupDir = __DIR__;
    $backupFilePath = $backupDir . '/' . $backupFile;
    if (file_exists($backupFilePath)) {
        if (unlink($backupFilePath)) {
            echo "备份的压缩包已删除！";
        } else {
            echo "备份数据删除失败！";
        }
    } else {
        echo "数据不存在！";
    }
} else {
    // 获取 open_basedir 允许的路径
    $openBasedir = ini_get('open_basedir');
    $allowedPaths = explode(PATH_SEPARATOR, $openBasedir);

    // 假设我们使用第一个允许的路径作为根目录
    $rootDir = $allowedPaths[0];
    // 清理根目录路径中的不可见字符
    $rootDir = preg_replace('/[\x00-\x1F\x7F]/u', '', $rootDir);

    // 备份文件路径
    $backupFile = 'backup_' . date('YmdHis') . '.zip';
    // 清理备份文件名中的不可见字符
    $backupFile = preg_replace('/[\x00-\x1F\x7F]/u', '', $backupFile);

    // 创建 ZIP 存档
    $zip = new ZipArchive();
    if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        // 递归添加文件到 ZIP 存档
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            $filePath = $file->getRealPath();
            // 清理文件路径中的不可见字符
            $filePath = preg_replace('/[\x00-\x1F\x7F]/u', '', $filePath);
            // 检查文件路径是否在允许的路径范围内
            $isAllowed = false;
            foreach ($allowedPaths as $allowedPath) {
                if (strpos($filePath, $allowedPath) === 0) {
                    $isAllowed = true;
                    break;
                }
            }
            if ($isAllowed &&!$file->isDir() && $name!== $backupFile) {
                $relativePath = substr($filePath, strlen($rootDir) + 1);
                // 清理相对路径中的不可见字符
                $relativePath = preg_replace('/[\x00-\x1F\x7F]/u', '', $relativePath);
                $zip->addFile($filePath, $relativePath);
            }
        }

        // 关闭 ZIP 存档
        $zip->close();

        // 输出备份文件路径
        echo $backupFile;
    } else {
        echo '无法创建备份文件';
    }
}
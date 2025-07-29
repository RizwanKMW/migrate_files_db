<?php
// Suppress errors on the page for a cleaner UI, but we will log them.
error_reporting(0);
ini_set('display_errors', 0);

// Increase execution time for large operations.
set_time_limit(600);
ini_set('memory_limit', '512M');

// --- CONFIGURATION ---
$backup_folder_name = 'backups'; // The name of the folder to store/exclude backups.

// --- SCRIPT LOGIC ---
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$error_message = '';
$success_message = '';

// Path to the backup folder.
$backup_directory_path = __DIR__ . '/' . $backup_folder_name . '/';

// Function to log errors
if (!function_exists('log_error')) {
    function log_error($error) {
        $log_file = __DIR__ . '/migration_error.log';
        $log_entry = date('Y-m-d H:i:s') . ' - ' . $error . PHP_EOL;
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
}

// Check for required extensions
if (!class_exists('ZipArchive')) {
    $error_message = 'PHP ZipArchive extension is not installed or enabled. This script cannot continue.';
    $action = null; // Halt any action
}
if (!function_exists('exec')) {
    $error_message = 'PHP `exec` function is disabled. This script requires it for database operations.';
    $action = null; // Halt any action
}


// --- HANDLE BACKUP ACTION ---
if ($action === 'backup') {
    $db_host = $_POST['db_host'] ?? 'localhost';
    $db_user = $_POST['db_user'] ?? '';
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = $_POST['db_name'] ?? '';
    $backup_filename_base = !empty($_POST['backup_filename']) ? basename($_POST['backup_filename'], '.zip') : $db_name . '-' . date('Y-m-d-H-i-s');
    
    // 1. VALIDATION
    $conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        $error_message = "Database connection failed: " . $conn->connect_error;
        log_error($error_message);
    } else {
        // Create backup directory if it doesn't exist and is writable
        if (!is_dir($backup_directory_path) && !@mkdir($backup_directory_path, 0755, true)) {
             $error_message = "Error: Unable to create backup directory at '{$backup_directory_path}'. Please check permissions.";
             log_error($error_message);
        } elseif (!is_writable($backup_directory_path)) {
            $error_message = "Error: The backup directory '{$backup_directory_path}' is not writable.";
            log_error($error_message);
        } else {
            // 2. PROCEED WITH BACKUP
            try {
                $sql_file_path = $backup_directory_path . $backup_filename_base . '.sql';
                $zip_file_path = $backup_directory_path . $backup_filename_base . '.zip';

                // Dump database
                $dump_command = sprintf(
                    "mysqldump --host=%s --user=%s --password=%s %s --result-file=%s 2>&1",
                    escapeshellarg($db_host),
                    escapeshellarg($db_user),
                    escapeshellarg($db_pass),
                    escapeshellarg($db_name),
                    escapeshellarg($sql_file_path)
                );
                exec($dump_command, $output, $return_var);
                if ($return_var !== 0) {
                    throw new Exception("Database dump failed. Error: " . implode("\n", $output));
                }

                // Zip files
                $zip = new ZipArchive();
                if ($zip->open($zip_file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                    throw new Exception("Cannot create zip file at '{$zip_file_path}'.");
                }
                
                $zip->addFile($sql_file_path, basename($sql_file_path));
                
                $source_directory = __DIR__;
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source_directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

                foreach ($files as $file) {
                    $filePath = $file->getRealPath();
                    // Exclude the backup directory itself and this very script
                    if (strpos($filePath, realpath($backup_directory_path)) === 0 || $filePath === __FILE__) {
                        continue;
                    }
                    
                    $relativePath = substr($filePath, strlen($source_directory) + 1);
                    if ($file->isDir()) {
                        $zip->addEmptyDir($relativePath);
                    } else {
                        $zip->addFile($filePath, $relativePath);
                    }
                }
                
                $zip->close();
                unlink($sql_file_path); // Clean up temp SQL file
                $success_message = "Backup successful! Your file is: <strong>" . htmlspecialchars($backup_folder_name . '/' . basename($zip_file_path)) . "</strong>";

            } catch (Exception $e) {
                $error_message = $e->getMessage();
                log_error($e->getMessage());
                if (file_exists($sql_file_path)) unlink($sql_file_path); // Cleanup on failure
            }
        }
    }
}


// --- HANDLE RESTORE ACTION ---
if ($action === 'restore') {
    $backup_url = $_POST['backup_url'] ?? '';
    $dest_path = $_POST['destination_path'] ?? __DIR__;
    $db_host = $_POST['db_host'] ?? '';
    $db_user = $_POST['db_user'] ?? '';
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = $_POST['db_name'] ?? '';

    // 1. VALIDATION
    if (empty($backup_url) || !filter_var($backup_url, FILTER_VALIDATE_URL)) {
        $error_message = "Invalid or empty Source Backup URL.";
    } elseif (!is_dir($dest_path) || !is_writable($dest_path)) {
        $error_message = "Destination path '{$dest_path}' does not exist or is not writable.";
        log_error($error_message);
    } else {
        $conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) {
            $error_message = "Database connection failed for restore: " . $conn->connect_error;
            log_error($error_message);
        } else {
            // 2. PROCEED WITH RESTORE
            try {
                // Download the file
                $local_zip_file = $dest_path . '/temp_backup_' . time() . '.zip';
                $remote_file_contents = @file_get_contents($backup_url);
                if ($remote_file_contents === false) {
                    throw new Exception("Could not download file from URL. Check if the URL is correct and publicly accessible.");
                }
                file_put_contents($local_zip_file, $remote_file_contents);

                // Unzip the file
                $zip = new ZipArchive;
                if ($zip->open($local_zip_file) !== TRUE) {
                    throw new Exception("Failed to open the downloaded zip file.");
                }
                $zip->extractTo($dest_path);
                
                // Find the SQL file
                $sql_file_to_import = '';
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if (pathinfo($filename, PATHINFO_EXTENSION) == 'sql') {
                        $sql_file_to_import = $dest_path . '/' . $filename;
                        break;
                    }
                }
                $zip->close();
                
                if (empty($sql_file_to_import) || !file_exists($sql_file_to_import)) {
                    throw new Exception("No .sql file found in the zip archive.");
                }

                // Import the database
                $import_command = sprintf(
                    "mysql --host=%s --user=%s --password=%s %s < %s 2>&1",
                    escapeshellarg($db_host),
                    escapeshellarg($db_user),
                    escapeshellarg($db_pass),
                    escapeshellarg($db_name),
                    escapeshellarg($sql_file_to_import)
                );
                exec($import_command, $output, $return_var);
                if ($return_var !== 0) {
                    throw new Exception("Database import failed. Error: " . implode("\n", $output));
                }
                
                // Clean up
                unlink($local_zip_file);
                unlink($sql_file_to_import);
                $success_message = "Restore completed successfully!";

            } catch (Exception $e) {
                $error_message = $e->getMessage();
                log_error($e->getMessage());
                // Clean up temp files on failure
                if (file_exists($local_zip_file)) unlink($local_zip_file);
                if (isset($sql_file_to_import) && file_exists($sql_file_to_import)) unlink($sql_file_to_import);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple PHP Backup & Restore</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 20px auto; background: #fff; padding: 20px 40px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .header { text-align: center; border-bottom: 1px solid #eee; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { margin: 0; color: #2c3e50; }
        .header p { color: #7f8c8d; }
        .tabs { display: flex; justify-content: center; margin-bottom: 30px; }
        .tab-link { padding: 10px 20px; border: 1px solid #ddd; background: #f9f9f9; cursor: pointer; border-radius: 5px; margin: 0 5px; transition: background 0.3s; }
        .tab-link.active { background: #3498db; color: #fff; border-color: #3498db; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #555; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .form-group small { color: #95a5a6; font-size: 0.9em; }
        .button { display: inline-block; width: 100%; padding: 12px; background: #2ecc71; color: #fff; border: none; border-radius: 4px; font-size: 16px; font-weight: 600; cursor: pointer; text-align: center; }
        .button.restore-btn { background-color: #e74c3c; }
        .message { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .message.error { background: #ffdddd; border: 1px solid #ff9999; color: #D8000C; }
        .message.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>PHP Backup & Restore Utility</h1>
            <p>A simple tool to backup or restore your website files and database.</p>
        </div>

        <?php if ($error_message): ?>
            <div class="message error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="tabs">
            <div class="tab-link active" onclick="showTab('backup')">Create Backup</div>
            <div class="tab-link" onclick="showTab('restore')">Restore Backup</div>
        </div>

        <!-- Backup Form -->
        <div id="backup" class="tab-content active">
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                <input type="hidden" name="action" value="backup">
                <h2>Create a Backup</h2>
                <p>This will create a .zip file containing all files in the current directory (except the '<?php echo htmlspecialchars($backup_folder_name); ?>' folder) and a full database dump.</p>
                <div class="form-group">
                    <label for="db_host_b">Database Host</label>
                    <input type="text" id="db_host_b" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label for="db_name_b">Database Name</label>
                    <input type="text" id="db_name_b" name="db_name" required>
                </div>
                <div class="form-group">
                    <label for="db_user_b">Database Username</label>
                    <input type="text" id="db_user_b" name="db_user" required>
                </div>
                <div class="form-group">
                    <label for="db_pass_b">Database Password</label>
                    <input type="password" id="db_pass_b" name="db_pass">
                </div>
                 <div class="form-group">
                    <label for="backup_filename">Optional Backup Filename (.zip)</label>
                    <input type="text" id="backup_filename" name="backup_filename" placeholder="e.g., my-site-backup.zip">
                    <small>If left empty, a name will be generated automatically.</small>
                </div>
                <button type="submit" class="button">Start Backup</button>
            </form>
        </div>

        <!-- Restore Form -->
        <div id="restore" class="tab-content">
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" onsubmit="return confirm('Are you absolutely sure you want to restore? This will overwrite existing files and database tables.');">
                <input type="hidden" name="action" value="restore">
                <h2>Restore from a Backup</h2>
                <p>This will download a .zip backup, extract the files, and import the database. <strong>Warning:</strong> This will overwrite existing files and data.</p>
                <div class="form-group">
                    <label for="backup_url">Source Backup URL (.zip file)</label>
                    <input type="url" id="backup_url" name="backup_url" placeholder="http://example.com/backup.zip" required>
                </div>
                 <div class="form-group">
                    <label for="destination_path">Destination Path</label>
                    <input type="text" id="destination_path" name="destination_path" value="<?php echo htmlspecialchars(__DIR__); ?>" required>
                    <small>The absolute server path to restore files to. Defaults to the current directory.</small>
                </div>
                <hr style="border:0; border-top: 1px solid #eee; margin: 20px 0;">
                 <div class="form-group">
                    <label for="db_host_r">New Database Host</label>
                    <input type="text" id="db_host_r" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label for="db_name_r">New Database Name</label>
                    <input type="text" id="db_name_r" name="db_name" required>
                </div>
                <div class="form-group">
                    <label for="db_user_r">New Database Username</label>
                    <input type="text" id="db_user_r" name="db_user" required>
                </div>
                <div class="form-group">
                    <label for="db_pass_r">New Database Password</label>
                    <input type="password" id="db_pass_r" name="db_pass">
                </div>
                <button type="submit" class="button restore-btn">Start Restore</button>
            </form>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            let i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            tablinks = document.getElementsByClassName("tab-link");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            document.getElementById(tabName).style.display = "block";
            event.currentTarget.className += " active";
        }
    </script>
</body>
</html>
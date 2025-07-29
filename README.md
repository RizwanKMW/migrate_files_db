# PHP Single-File Website Migration Utility

A single-file PHP script to backup and restore a complete website, including all files and the MySQL database. This tool is designed to simplify the process of moving a PHP/MySQL website from one server to another.



## Prerequisites

For this script to function correctly, your server environment must meet the following requirements:

-   PHP 7.2 or newer.
-   **`ZipArchive`** PHP extension must be enabled.
-   **`mysqli`** PHP extension must be enabled.
-   The **`exec()`** function must be enabled and not blocked in `php.ini` (`disable_functions`).
-   The **`mysqldump`** command-line utility must be installed and accessible by the PHP process. This is standard on most LAMP/LEMP stacks.
-   The directory where the script is placed must be **writable** by the web server user so it can create the `backups` folder and the `.zip` file.

---

## How to Use

### 1. Creating a Backup

1.  Upload `migrate.php` to the **root directory** of the website you want to back up.
2.  Open your browser and navigate to `http://your-website.com/migrate.php`.
3.  Fill in your current database credentials (host, name, user, password).
4.  (Optional) Provide a custom filename for the backup.
5.  Click the **"Start Backup"** button.
6.  Upon success, a `.zip` file will be created in a `backups` sub-directory. A link will be shown.

### 2. Restoring a Backup

1.  On your new server, upload the *same* `migrate.php` script to the empty root directory of your new website.
2.  Navigate to `http://your-new-website.com/migrate.php`.
3.  Click the **"Restore Backup"** tab.
4.  Provide the full, public URL to the `.zip` file created during the backup step.
5.  Enter the database credentials for your **new, empty database**.
6.  Click the **"Start Restore"** button and confirm the action.

---
![Backup](https://raw.githubusercontent.com/RizwanKMW/migrate_files_db/refs/heads/main/Screenshot_1.png)
## ⚠️ Important Security Warning

This script is extremely powerful. It can read database credentials and overwrite your entire website. You **MUST** follow these security practices:

1.  **DELETE `migrate.php` IMMEDIATELY AFTER USE.** This is the most important rule. Leaving this script on a server creates a massive security vulnerability. Delete it from both the old and the new server once you are done.
2.  
**Step 13: CRITICAL - DELETE THE SCRIPT**
This is the most important step. **Delete the `migrate.php` file** from both your **Old Server** and your **New Server**. Leaving it accessible is a major security risk.

**Step 14: Clean Up the Backup File**
For added security, log in to your Old Server one last time and delete the `.zip` file from the `backups` folder.

# Ninja Forms - Auto Delete

A lightweight, powerful WordPress plugin for automated GDPR compliance and data retention management for **Ninja Forms**.

This plugin enables the automatic deletion of form submissions and uploaded files after a defined period. It offers granular control over what gets deleted (only files or the entire entry) and when it happens.

## ✨ Features

* **Automated Scheduling:** Automatically deletes old data in the background (Daily Cronjob).
* **Global & Individual Deadlines:**
    * Set a global default retention period (e.g., 365 days).
    * Define specific rules per form (e.g., delete "Job Applications" after 90 days, never delete "Contact").
* **Granular Deletion Logic:** Decide separately how to handle data types:
    * *Submissions:* Keep, move to trash, or permanently delete.
    * *Files:* Delete attachments from the server, even if the submission text is kept (saves storage space!).
* **Simulation (Dry Run):** Pre-calculate how many submissions or files would be affected by your rules without actually deleting them.
* **Logging & Transparency:** An integrated logging system records exactly what was deleted and when (including success/error status).
* **Batch Processing:** Processes deletions in small batches (default: 50 items) to prevent PHP timeouts with large datasets.
* **Self-Healing Cron:** Automatically checks and repairs the schedule upon initialization if the cron event is lost due to migrations or errors.

## 🚀 Installation

1.  Upload the plugin folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to **Ninja Forms > Auto Delete** to configure your retention rules.

## ⚙️ Configuration

The dashboard is divided into three sections:

1.  **Log:** Monitor executed actions, deletions, and errors.
2.  **Define Deadlines (Rules):** Set the number of days after which data is considered "expired". You can choose between:
    * *Global Default*
    * *Never Delete*
    * *Custom (Individual day count)*
3.  **Settings:**
    * Choose whether submissions are moved to Trash or permanently deleted.
    * Enable separate deletion for file attachments.
    * Trigger manual cleanup runs ("Run Now").

## 🛡️ Security & Performance

* **Symlink Protection:** Prevents accidental deletion of files outside the upload directory.
* **Jail Check:** Ensures operations are strictly confined to the WordPress uploads folder.
* **Resource Efficient:** Uses batch processing to keep server load low during cleanup operations.

## ⚠️ Important Note

Although this plugin features simulation modes and safety checks, data is **permanently deleted** (if configured to do so). It is highly recommended to create a database and file backup before the first major cleanup run.

---

**Requirements:**
* PHP 7.4+
* WordPress 5.0+
* Ninja Forms 3.0+
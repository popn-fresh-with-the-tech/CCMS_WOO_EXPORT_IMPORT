# CCMS Woo Export/Import Tool

Written by **Joseph Charnin**  
🔗 [josephcharnin.com](https://josephcharnin.com)

This tool allows you to export your WooCommerce data from WordPress and back it up or migrate it to another site or database.

---

## 🔄 Section 1: Exporting a Backup

Use this method if you want a backup of your WooCommerce data for safety or restoration purposes.

### Step-by-Step Instructions:

1. **Run the Script**
   - Upload the `index.php` file to your WordPress root directory.
   - Open the file in your browser (e.g. `https://yoursite.com/index.php`).

2. **Download the Exported SQL File**
   - The tool will generate a SQL file of your WooCommerce data.
   - Save this file somewhere secure.

3. **Backup Product Images**
   - Use FTP or your hosting file manager to download the entire `/wp-content/uploads/` folder.
   - This contains your product images and other media.

4. **Restoring the Backup (Optional)**
   - You can import the SQL file back into your database using:
     - WordPress admin import plugin (if available), or
     - **phpMyAdmin**:
       - Go to phpMyAdmin, select your database, and import the SQL file.

---

## 🌐 Section 2: Migrating to a Different Database or Domain

If you're moving your store to a new server, database, or domain, follow these steps instead.

### Includes All Backup Steps Plus:

1. **Follow the Backup Steps First**
   - Complete all the steps from **Section 1** to get your SQL file and media files.

2. **Edit the SQL File**
   - Open the exported `.sql` file in a text editor such as:
     - [Visual Studio Code](https://code.visualstudio.com/)
     - [Notepad++](https://notepad-plus-plus.org/)
   - Use **Find & Replace** to update old URLs with the new domain name:
     - Example:
       ```
       Find: https://oldsite.com
       Replace with: https://newsite.com
       ```
   - Save the updated SQL file.

3. **Import the Updated SQL File**
   - Use phpMyAdmin or your database tool to import the updated SQL into your **new** database.

4. **Move the Uploads Folder**
   - Copy the entire `/wp-content/uploads/` folder from the old site to the same path in the new site.
   - This ensures all media files are available.

5. ⚠️ **Warning About Third-Party Plugins**
   - Some plugins (e.g., product importers, page builders) store images in custom subdirectories within `/uploads/`.
   - Be sure to include **all subfolders** in your uploads backup to avoid missing product or media images.

---

## ✅ Final Notes

- This tool focuses on WooCommerce and related data but **does not include plugin-specific settings**.
- Always test your import on a staging site before deploying to a live site.

If you find this tool useful or need support, visit [josephcharnin.com](https://josephcharnin.com).

---
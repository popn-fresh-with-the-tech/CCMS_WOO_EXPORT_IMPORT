<?php
// Turn off any output buffering.
while (ob_get_level() > 0) { 
    ob_end_flush(); 
}
ini_set('implicit_flush', 1);
ob_implicit_flush(true);

// -----------------------
// Helper Functions (Common for Export & Import)
// -----------------------

// Generates an SQL INSERT statement for a given table row.
function generate_sql_insert($table, $row, $mode) {
    $columns = array_keys($row);
    $values  = array_values($row);
    $escaped = array();
    foreach ($values as $value) {
        $escaped[] = "'" . addslashes($value) . "'";
    }
    $columns_sql = "`" . implode("`, `", $columns) . "`";
    $values_sql  = implode(", ", $escaped);
    
    if ($mode == 'overwrite') {
        $cmd = "REPLACE INTO";
        $sql = "$cmd `$table` ($columns_sql) VALUES ($values_sql);\n";
    } elseif ($mode == 'append') {
        $cmd = "INSERT INTO";
        $sql = "$cmd `$table` ($columns_sql) VALUES ($values_sql);\n";
    } elseif ($mode == 'update') {
        $cmd = "INSERT INTO";
        $updates = array();
        foreach ($columns as $col) {
            $updates[] = "`$col`=VALUES(`$col`)";
        }
        $update_sql = implode(", ", $updates);
        $sql = "$cmd `$table` ($columns_sql) VALUES ($values_sql) ON DUPLICATE KEY UPDATE $update_sql;\n";
    } else {
        $cmd = "INSERT INTO";
        $sql = "$cmd `$table` ($columns_sql) VALUES ($values_sql);\n";
    }
    return $sql;
}

// Writes data to the current export file and splits files when the max size is reached.
function write_export_data($data, &$current_file_handle, &$current_file_size, $max_size, &$file_index, $export_dir, &$files_array) {
    fwrite($current_file_handle, $data);
    $current_file_size += strlen($data);
    if ($current_file_size >= $max_size) {
        fclose($current_file_handle);
        $file_index++;
        $new_filename = $export_dir . "/export_part_" . $file_index . ".sql";
        $current_file_handle = fopen($new_filename, "w");
        if (!$current_file_handle) {
            die("Cannot open file: " . $new_filename);
        }
        $current_file_size = 0;
        $files_array[] = $new_filename;
    }
}

// Returns the total row count for a given query (expects a table name and an optional WHERE clause).
function get_total_rows($mysqli, $table, $where = "") {
    $query = "SELECT COUNT(*) as cnt FROM `$table` " . $where;
    $result = $mysqli->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        return (int)$row['cnt'];
    }
    return 0;
}

// Executes SQL statements using multi_query.
function execute_sql($mysqli, $sql, $filename = '') {
    if (!$mysqli->multi_query($sql)) {
        echo "Error executing SQL from $filename: " . $mysqli->error . "<br>";
    }
    while ($mysqli->more_results() && $mysqli->next_result()) { }
}

// -----------------------
// Export Functions (with added taxonomy markers)
// -----------------------

// Process export queries in chunks.
function export_in_chunks($mysqli, $base_query, $dest_table, $export_mode, &$current_file_handle, &$current_file_size, $max_size, &$file_index, $export_dir, &$files_array, $chunk_size = 1000, $total_rows = 0) {
    $offset = 0;
    $processed = 0;
    do {
        $query = $base_query . " LIMIT $chunk_size OFFSET $offset";
        $result = $mysqli->query($query);
        if (!$result) {
            echo "Error executing query: " . $mysqli->error . "<br>";
            break;
        }
        $num_rows = $result->num_rows;
        while ($row = $result->fetch_assoc()) {
            $sql_line = generate_sql_insert($dest_table, $row, $export_mode);
            write_export_data($sql_line, $current_file_handle, $current_file_size, $max_size, $file_index, $export_dir, $files_array);
            $processed++;
        }
        $offset += $chunk_size;
        if ($total_rows > 0) {
            $percent = round(($processed / $total_rows) * 100);
            echo "Exported $processed / $total_rows rows from $dest_table ($percent% completed)...<br>";
        } else {
            echo "Exported $processed rows from $dest_table...<br>";
        }
        flush();
    } while ($num_rows > 0);
}

function export_products_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir) {
    $export_dir = $base_export_dir . "/products";
    if (!is_dir($export_dir)) mkdir($export_dir, 0777, true);
    $mysqli = new mysqli($source_host, $source_user, $source_pass, $source_db);
    if ($mysqli->connect_error) { echo "Products: Connection failed: " . $mysqli->connect_error . "<br>"; return; }
    
    $file_index = 1;
    $max_file_size = 50 * 1024 * 1024;
    $current_file_size = 0;
    $first_filename = $export_dir . "/export_part_" . $file_index . ".sql";
    $current_file_handle = fopen($first_filename, "w");
    if (!$current_file_handle) { echo "Products: Cannot open file: " . $first_filename . "<br>"; return; }
    $files_array = array($first_filename);
    
    $header = "-- Products Export Generated on " . date('Y-m-d H:i:s') . "\n" .
              "-- Source DB: $source_db (prefix: $source_prefix)\n" .
              "-- Destination prefix: $dest_prefix\n" .
              "-- Export Mode: $export_mode\n\n";
    write_export_data($header, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    echo "Exporting Products and Product Variations...<br>"; flush();
    $total_products = get_total_rows($mysqli, $source_prefix . "posts", "WHERE post_type IN ('product','product_variation')");
    $posts_query = "SELECT * FROM `{$source_prefix}posts` WHERE post_type IN ('product','product_variation') ORDER BY ID";
    export_in_chunks($mysqli, $posts_query, $dest_prefix . "posts", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array, 1000, $total_products);
    
    echo "Exporting Post Meta for Products...<br>"; flush();
    $postmeta_query = "SELECT pm.* FROM `{$source_prefix}postmeta` pm INNER JOIN `{$source_prefix}posts` p ON pm.post_id = p.ID WHERE p.post_type IN ('product','product_variation') ORDER BY pm.meta_id";
    export_in_chunks($mysqli, $postmeta_query, $dest_prefix . "postmeta", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    // --- NEW CODE: Export Term Relationships for Products ---
    // This ensures that product-category associations are maintained.
    $result_ids = $mysqli->query("SELECT ID FROM `{$source_prefix}posts` WHERE post_type IN ('product','product_variation')");
    $product_ids = array();
    while ($row = $result_ids->fetch_assoc()) {
        $product_ids[] = $row['ID'];
    }
    if (!empty($product_ids)) {
        $ids_str = implode(',', $product_ids);
        $query_terms = "SELECT * FROM `{$source_prefix}term_relationships` WHERE object_id IN ($ids_str)";
        echo "Exporting Term Relationships for Products...<br>"; flush();
        export_in_chunks($mysqli, $query_terms, $dest_prefix . "term_relationships", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    }
    
    fclose($current_file_handle);
    $mysqli->close();
}

function export_taxonomies_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir, $export_categories, $export_tags, $export_attributes) {
    $export_dir = $base_export_dir . "/taxonomies";
    if (!is_dir($export_dir)) mkdir($export_dir, 0777, true);
    $mysqli = new mysqli($source_host, $source_user, $source_pass, $source_db);
    if ($mysqli->connect_error) { echo "Taxonomies: Connection failed: " . $mysqli->connect_error . "<br>"; return; }
    
    $file_index = 1;
    $max_file_size = 50 * 1024 * 1024;
    $current_file_size = 0;
    $first_filename = $export_dir . "/export_part_" . $file_index . ".sql";
    $current_file_handle = fopen($first_filename, "w");
    if (!$current_file_handle) { echo "Taxonomies: Cannot open file: " . $first_filename . "<br>"; return; }
    $files_array = array($first_filename);
    
    $header = "-- Taxonomies Export Generated on " . date('Y-m-d H:i:s') . "\n" .
              "-- Source DB: $source_db (prefix: $source_prefix)\n" .
              "-- Destination prefix: $dest_prefix\n" .
              "-- Export Mode: $export_mode\n\n";
    write_export_data($header, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    echo "Exporting Taxonomies (Categories/Tags/Attributes)...<br>"; flush();
    $taxonomies = array();
    if ($export_categories) { $taxonomies[] = "'product_cat'"; }
    if ($export_tags) { $taxonomies[] = "'product_tag'"; }
    if ($export_attributes) { $taxonomies[] = "taxonomy LIKE 'pa\\_%'"; }
    $conditions = array();
    foreach ($taxonomies as $tax) {
        $conditions[] = (strpos($tax, "LIKE") !== false) ? $tax : "taxonomy = " . $tax;
    }
    $tax_condition = implode(" OR ", $conditions);
    // Order by taxonomy so we can group them and add markers.
    $tax_query = "SELECT t.*, tt.* FROM `{$source_prefix}terms` t JOIN `{$source_prefix}term_taxonomy` tt ON t.term_id = tt.term_id WHERE $tax_condition ORDER BY tt.taxonomy, t.term_id";
    $total_tax = get_total_rows($mysqli, $source_prefix . "terms");
    $chunk_size = 1000;
    $offset = 0;
    $current_taxonomy = "";
    do {
        $query = $tax_query . " LIMIT $chunk_size OFFSET $offset";
        $result = $mysqli->query($query);
        if (!$result) {
            echo "Taxonomies: Error executing query: " . $mysqli->error . "<br>";
            break;
        }
        $rows = $result->num_rows;
        while ($row = $result->fetch_assoc()) {
            // When taxonomy changes, add a marker for later filtering during import.
            $taxonomy = $row['taxonomy'];
            if ($taxonomy !== $current_taxonomy) {
                if ($taxonomy == 'product_cat') {
                    fwrite($current_file_handle, "-- BEGIN PRODUCT CATEGORIES\n");
                } elseif ($taxonomy == 'product_tag') {
                    fwrite($current_file_handle, "-- BEGIN PRODUCT TAGS\n");
                } elseif (strpos($taxonomy, 'pa_') === 0) {
                    fwrite($current_file_handle, "-- BEGIN PRODUCT ATTRIBUTES\n");
                }
                $current_taxonomy = $taxonomy;
            }
            $term_data = array(
                'term_id'    => $row['term_id'],
                'name'       => $row['name'],
                'slug'       => $row['slug'],
                'term_group' => $row['term_group']
            );
            write_export_data(generate_sql_insert($dest_prefix . "terms", $term_data, $export_mode), $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
            
            $tt_data = array(
                'term_taxonomy_id' => $row['term_taxonomy_id'],
                'term_id'          => $row['term_id'],
                'taxonomy'         => $row['taxonomy'],
                'description'      => $row['description'],
                'parent'           => $row['parent'],
                'count'            => $row['count']
            );
            write_export_data(generate_sql_insert($dest_prefix . "term_taxonomy", $tt_data, $export_mode), $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
        }
        $offset += $chunk_size;
        echo "Exported " . min($offset, $total_tax) . " / $total_tax taxonomy rows...<br>";
        flush();
    } while ($rows > 0);
    
    // --- NEW CODE: Export Term Meta for Product Categories (e.g. thumbnail_id) ---
    echo "Exporting Term Meta for Product Categories...<br>"; flush();
    $query_termmeta = "SELECT * FROM `{$source_prefix}termmeta` WHERE term_id IN (SELECT term_id FROM `{$source_prefix}term_taxonomy` WHERE taxonomy = 'product_cat')";
    export_in_chunks($mysqli, $query_termmeta, $dest_prefix . "termmeta", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    fwrite($current_file_handle, "\n");
    fclose($current_file_handle);
    $mysqli->close();
}

function export_users_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir, $export_users) {
    $export_dir = $base_export_dir . "/users";
    if (!is_dir($export_dir)) mkdir($export_dir, 0777, true);
    $mysqli = new mysqli($source_host, $source_user, $source_pass, $source_db);
    if ($mysqli->connect_error) { echo "Users: Connection failed: " . $mysqli->connect_error . "<br>"; return; }
    
    $file_index = 1;
    $max_file_size = 50 * 1024 * 1024;
    $current_file_size = 0;
    $first_filename = $export_dir . "/export_part_" . $file_index . ".sql";
    $current_file_handle = fopen($first_filename, "w");
    if (!$current_file_handle) { echo "Users: Cannot open file: " . $first_filename . "<br>"; return; }
    $files_array = array($first_filename);
    
    $header = "-- Users Export Generated on " . date('Y-m-d H:i:s') . "\n" .
              "-- Source DB: $source_db (prefix: $source_prefix)\n" .
              "-- Destination prefix: $dest_prefix\n" .
              "-- Export Mode: $export_mode\n\n";
    write_export_data($header, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    echo "Exporting Users...<br>"; flush();
    if ($export_users) {
        $users_query = "SELECT * FROM `{$source_prefix}users` ORDER BY ID";
    } else {
        $cap_key = $source_prefix . "capabilities";
        $users_query = "SELECT * FROM `{$source_prefix}users` WHERE ID IN (SELECT user_id FROM `{$source_prefix}usermeta` WHERE meta_key = '$cap_key' AND meta_value LIKE '%customer%') ORDER BY ID";
    }
    $total_users = get_total_rows($mysqli, $source_prefix . "users");
    export_in_chunks($mysqli, $users_query, $dest_prefix . "users", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array, 1000, $total_users);
    
    echo "Exporting User Meta...<br>"; flush();
    $usermeta_query = "SELECT * FROM `{$source_prefix}usermeta` ORDER BY umeta_id";
    export_in_chunks($mysqli, $usermeta_query, $dest_prefix . "usermeta", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    fclose($current_file_handle);
    $mysqli->close();
}

function export_orders_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir) {
    $export_dir = $base_export_dir . "/orders";
    if (!is_dir($export_dir)) mkdir($export_dir, 0777, true);
    $mysqli = new mysqli($source_host, $source_user, $source_pass, $source_db);
    if ($mysqli->connect_error) { echo "Orders: Connection failed: " . $mysqli->connect_error . "<br>"; return; }
    
    $file_index = 1;
    $max_file_size = 50 * 1024 * 1024;
    $current_file_size = 0;
    $first_filename = $export_dir . "/export_part_" . $file_index . ".sql";
    $current_file_handle = fopen($first_filename, "w");
    if (!$current_file_handle) { echo "Orders: Cannot open file: " . $first_filename . "<br>"; return; }
    $files_array = array($first_filename);
    
    $header = "-- Orders Export Generated on " . date('Y-m-d H:i:s') . "\n" .
              "-- Source DB: $source_db (prefix: $source_prefix)\n" .
              "-- Destination prefix: $dest_prefix\n" .
              "-- Export Mode: $export_mode\n\n";
    write_export_data($header, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    echo "Exporting Orders...<br>"; flush();
    $total_orders = get_total_rows($mysqli, $source_prefix . "posts", "WHERE post_type = 'shop_order'");
    $orders_query = "SELECT * FROM `{$source_prefix}posts` WHERE post_type = 'shop_order' ORDER BY ID";
    export_in_chunks($mysqli, $orders_query, $dest_prefix . "posts", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array, 1000, $total_orders);
    
    echo "Exporting Order Meta...<br>"; flush();
    $ordermeta_query = "SELECT * FROM `{$source_prefix}postmeta` WHERE post_id IN (SELECT ID FROM `{$source_prefix}posts` WHERE post_type = 'shop_order') ORDER BY meta_id";
    export_in_chunks($mysqli, $ordermeta_query, $dest_prefix . "postmeta", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    echo "Exporting WooCommerce Order Items...<br>"; flush();
    $orderitems_query = "SELECT * FROM `{$source_prefix}woocommerce_order_items` ORDER BY order_item_id";
    export_in_chunks($mysqli, $orderitems_query, $dest_prefix . "woocommerce_order_items", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    echo "Exporting WooCommerce Order Item Meta...<br>"; flush();
    $orderitemmeta_query = "SELECT * FROM `{$source_prefix}woocommerce_order_itemmeta` ORDER BY meta_id";
    export_in_chunks($mysqli, $orderitemmeta_query, $dest_prefix . "woocommerce_order_itemmeta", $export_mode, $current_file_handle, $current_file_size, $max_file_size, $file_index, $export_dir, $files_array);
    
    fclose($current_file_handle);
    $mysqli->close();
}

// -----------------------
// Import Helper Functions
// -----------------------

// For a full SQL file (used by products, users, orders)
function import_sql_file($mysqli, $filepath) {
    $sql = file_get_contents($filepath);
    execute_sql($mysqli, $sql, $filepath);
    echo "Imported file: $filepath<br>"; flush();
}

// For taxonomies files, parse block-by-block using our markers.
// $selectedTypes is an array containing (in lowercase) any of:
// "product categories", "product tags", "product attributes"
function import_taxonomies_file($mysqli, $filepath, $selectedTypes) {
    $lines = file($filepath);
    $current_block = null;
    $buffer = "";
    foreach ($lines as $line) {
        if (preg_match('/^-- BEGIN (PRODUCT CATEGORIES|PRODUCT TAGS|PRODUCT ATTRIBUTES)/i', $line, $matches)) {
            if ($buffer !== "" && $current_block !== null) {
                if (in_array(strtolower($current_block), $selectedTypes)) {
                    execute_sql($mysqli, $buffer, $filepath);
                }
            }
            $current_block = strtolower($matches[1]);
            $buffer = "";
        } else {
            $buffer .= $line;
        }
    }
    if ($buffer !== "" && $current_block !== null && in_array(strtolower($current_block), $selectedTypes)) {
        execute_sql($mysqli, $buffer, $filepath);
    }
    echo "Imported taxonomies from file: $filepath<br>"; flush();
}

// Import files from a folder for a given group (products, users, orders)
function import_group_from_folder($mysqli, $base_folder, $group) {
    $group_folder = rtrim($base_folder, '/') . '/' . $group;
    if (!is_dir($group_folder)) {
        echo "Folder for $group not found in $base_folder.<br>"; return;
    }
    $files = glob($group_folder . "/*.sql");
    foreach ($files as $file) {
        import_sql_file($mysqli, $file);
    }
}

// For taxonomies, import from folder with filtering.
function import_taxonomies_from_folder($mysqli, $base_folder, $selectedTypes) {
    $group_folder = rtrim($base_folder, '/') . '/taxonomies';
    if (!is_dir($group_folder)) {
        echo "Taxonomies folder not found in $base_folder.<br>"; return;
    }
    $files = glob($group_folder . "/*.sql");
    foreach ($files as $file) {
        import_taxonomies_file($mysqli, $file, $selectedTypes);
    }
}

// When files are uploaded, use a simple heuristic by matching the filename.
function import_group_from_upload($mysqli, $files_array, $group) {
    foreach ($files_array as $fileInfo) {
        $name = $fileInfo['name'];
        if (stripos($name, $group) !== false) {
            $tmp_name = $fileInfo['tmp_name'];
            import_sql_file($mysqli, $tmp_name);
        }
    }
}

function import_taxonomies_from_upload($mysqli, $files_array, $selectedTypes) {
    foreach ($files_array as $fileInfo) {
        $name = $fileInfo['name'];
        if (stripos($name, 'tax') !== false) {
            $tmp_name = $fileInfo['tmp_name'];
            import_taxonomies_file($mysqli, $tmp_name, $selectedTypes);
        }
    }
}

// -----------------------
// Main Process: Export/Import
// -----------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'export') {
        // -----------------------
        // Export Process
        // -----------------------
        $source_host   = $_POST['source_host'];
        $source_user   = $_POST['source_user'];
        $source_pass   = $_POST['source_pass'];
        $source_db     = $_POST['source_db'];
        $source_prefix = $_POST['source_prefix'];
        
        $dest_db     = $_POST['dest_db']; // For reference.
        $dest_prefix = $_POST['dest_prefix'];
        $export_mode = $_POST['export_mode']; // append, overwrite, or update.
        $output_method = $_POST['output_method']; // "progress" or "direct"
        
        $options = array(
            'export_products'   => isset($_POST['export_products']),
            'export_categories' => isset($_POST['export_categories']),
            'export_tags'       => isset($_POST['export_tags']),
            'export_attributes' => isset($_POST['export_attributes']),
            'export_users'      => isset($_POST['export_users']),
            'export_customers'  => isset($_POST['export_customers']),
            'export_orders'     => isset($_POST['export_orders']),
            'source_db'         => $source_db,
            'dest_db'           => $dest_db
        );
        
        $mysqli = new mysqli($source_host, $source_user, $source_pass, $source_db);
        if ($mysqli->connect_error) {
            die("Connection failed: " . $mysqli->connect_error);
        }
        
        // Choose export method.
        if ($output_method == 'direct') {
            // Direct Download mode: stream all output to browser.
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="export_' . date('Ymd_His') . '.sql"');
            
            $fh = fopen('php://output', 'w');
            fwrite($fh, "-- SQL Export Generated on " . date('Y-m-d H:i:s') . "\n");
            fwrite($fh, "-- Source DB: $source_db (prefix: $source_prefix)\n");
            fwrite($fh, "-- Destination DB (for reference): $dest_db (prefix: $dest_prefix)\n");
            fwrite($fh, "-- Export Mode: $export_mode\n\n");
            
            // 1. Export Products and Post Meta.
            if ($options['export_products']) {
                fwrite($fh, "-- Exporting Products and Product Variations\n");
                $query = "SELECT * FROM `{$source_prefix}posts` WHERE post_type IN ('product','product_variation')";
                $result = $mysqli->query($query);
                $product_ids = array();
                while ($row = $result->fetch_assoc()) {
                    $product_ids[] = $row['ID'];
                    fwrite($fh, generate_sql_insert($dest_prefix . "posts", $row, $export_mode));
                }
                if (!empty($product_ids)) {
                    $ids = implode(",", $product_ids);
                    $query_meta = "SELECT * FROM `{$source_prefix}postmeta` WHERE post_id IN ($ids)";
                    $result_meta = $mysqli->query($query_meta);
                    fwrite($fh, "-- Exporting Post Meta for Products\n");
                    while ($row = $result_meta->fetch_assoc()) {
                        fwrite($fh, generate_sql_insert($dest_prefix . "postmeta", $row, $export_mode));
                    }
                }
                
                // --- NEW CODE: Export Term Relationships for Products ---
                if (!empty($product_ids)) {
                    $ids = implode(",", $product_ids);
                    $query_terms = "SELECT * FROM `{$source_prefix}term_relationships` WHERE object_id IN ($ids)";
                    $result_terms = $mysqli->query($query_terms);
                    fwrite($fh, "-- Exporting Term Relationships for Products\n");
                    while ($row = $result_terms->fetch_assoc()) {
                        fwrite($fh, generate_sql_insert($dest_prefix . "term_relationships", $row, $export_mode));
                    }
                }
                fwrite($fh, "\n");
            }
            
            // --- NEW CODE: Export Attachments and their Post Meta ---
            fwrite($fh, "-- Exporting Attachments\n");
            $query_att = "SELECT * FROM `{$source_prefix}posts` WHERE post_type = 'attachment'";
            $result_att = $mysqli->query($query_att);
            while ($row = $result_att->fetch_assoc()) {
                fwrite($fh, generate_sql_insert($dest_prefix . "posts", $row, $export_mode));
            }
            fwrite($fh, "-- Exporting Attachment Post Meta\n");
            $query_att_meta = "SELECT * FROM `{$source_prefix}postmeta` WHERE post_id IN (SELECT ID FROM `{$source_prefix}posts` WHERE post_type = 'attachment')";
            $result_att_meta = $mysqli->query($query_att_meta);
            while ($row = $result_att_meta->fetch_assoc()) {
                fwrite($fh, generate_sql_insert($dest_prefix . "postmeta", $row, $export_mode));
            }
            
            // 2. Export Taxonomies.
            if ($options['export_categories'] || $options['export_tags'] || $options['export_attributes']) {
                fwrite($fh, "-- Exporting Taxonomies (Categories/Tags/Attributes)\n");
                $taxonomies = array();
                if ($options['export_categories']) { $taxonomies[] = "'product_cat'"; }
                if ($options['export_tags']) { $taxonomies[] = "'product_tag'"; }
                if ($options['export_attributes']) { $taxonomies[] = "taxonomy LIKE 'pa\\_%'"; }
                
                $conditions = array();
                foreach ($taxonomies as $tax) {
                    $conditions[] = (strpos($tax, "LIKE") !== false) ? $tax : "taxonomy = " . $tax;
                }
                $tax_condition = implode(" OR ", $conditions);
                
                $query = "SELECT t.*, tt.* 
                          FROM `{$source_prefix}terms` t 
                          JOIN `{$source_prefix}term_taxonomy` tt ON t.term_id = tt.term_id 
                          WHERE $tax_condition";
                $result = $mysqli->query($query);
                while ($row = $result->fetch_assoc()) {
                    $term_data = array(
                        'term_id'    => $row['term_id'],
                        'name'       => $row['name'],
                        'slug'       => $row['slug'],
                        'term_group' => $row['term_group']
                    );
                    $tt_data = array(
                        'term_taxonomy_id' => $row['term_taxonomy_id'],
                        'term_id'          => $row['term_id'],
                        'taxonomy'         => $row['taxonomy'],
                        'description'      => $row['description'],
                        'parent'           => $row['parent'],
                        'count'            => $row['count']
                    );
                    fwrite($fh, generate_sql_insert($dest_prefix . "terms", $term_data, $export_mode));
                    fwrite($fh, generate_sql_insert($dest_prefix . "term_taxonomy", $tt_data, $export_mode));
                }
                // --- NEW CODE: Export Term Meta for Product Categories (thumbnails, etc.) ---
                fwrite($fh, "-- Exporting Term Meta for Product Categories\n");
                $query_termmeta = "SELECT * FROM `{$source_prefix}termmeta` WHERE term_id IN (SELECT term_id FROM `{$source_prefix}term_taxonomy` WHERE taxonomy = 'product_cat')";
                $result_termmeta = $mysqli->query($query_termmeta);
                while ($row = $result_termmeta->fetch_assoc()) {
                    fwrite($fh, generate_sql_insert($dest_prefix . "termmeta", $row, $export_mode));
                }
                fwrite($fh, "\n");
            }
            
            // 3. Export Users.
            if ($options['export_users'] || (!$options['export_users'] && $options['export_customers'])) {
                fwrite($fh, "-- Exporting Users\n");
                if ($options['export_users']) {
                    $query = "SELECT * FROM `{$source_prefix}users` ORDER BY ID";
                } else {
                    $cap_key = $source_prefix . "capabilities";
                    $query = "SELECT * FROM `{$source_prefix}users` WHERE ID IN (
                                 SELECT user_id FROM `{$source_prefix}usermeta` 
                                 WHERE meta_key = '$cap_key' AND meta_value LIKE '%customer%'
                              ) ORDER BY ID";
                }
                $result = $mysqli->query($query);
                $user_ids = array();
                while ($row = $result->fetch_assoc()) {
                    $user_ids[] = $row['ID'];
                    fwrite($fh, generate_sql_insert($dest_prefix . "users", $row, $export_mode));
                }
                if (!empty($user_ids)) {
                    $ids = implode(",", $user_ids);
                    $query_meta = "SELECT * FROM `{$source_prefix}usermeta` WHERE user_id IN ($ids)";
                    $result_meta = $mysqli->query($query_meta);
                    fwrite($fh, "-- Exporting User Meta\n");
                    while ($row = $result_meta->fetch_assoc()) {
                        fwrite($fh, generate_sql_insert($dest_prefix . "usermeta", $row, $export_mode));
                    }
                }
                fwrite($fh, "\n");
            }
            
            // 4. Export Orders.
            if ($options['export_orders']) {
                fwrite($fh, "-- Exporting Orders\n");
                $query = "SELECT * FROM `{$source_prefix}posts` WHERE post_type = 'shop_order'";
                $result = $mysqli->query($query);
                $order_ids = array();
                while ($row = $result->fetch_assoc()) {
                    $order_ids[] = $row['ID'];
                    fwrite($fh, generate_sql_insert($dest_prefix . "posts", $row, $export_mode));
                }
                if (!empty($order_ids)) {
                    $ids = implode(",", $order_ids);
                    $query_meta = "SELECT * FROM `{$source_prefix}postmeta` WHERE post_id IN ($ids)";
                    $result_meta = $mysqli->query($query_meta);
                    fwrite($fh, "-- Exporting Order Meta\n");
                    while ($row = $result_meta->fetch_assoc()) {
                        fwrite($fh, generate_sql_insert($dest_prefix . "postmeta", $row, $export_mode));
                    }
                    // Export WooCommerce order items.
                    $query_items = "SELECT * FROM `{$source_prefix}woocommerce_order_items` WHERE order_id IN ($ids)";
                    $result_items = $mysqli->query($query_items);
                    $order_item_ids = array();
                    fwrite($fh, "-- Exporting WooCommerce Order Items\n");
                    while ($row = $result_items->fetch_assoc()) {
                        $order_item_ids[] = $row['order_item_id'];
                        fwrite($fh, generate_sql_insert($dest_prefix . "woocommerce_order_items", $row, $export_mode));
                    }
                    if (!empty($order_item_ids)) {
                        $ids_items = implode(",", $order_item_ids);
                        $query_itemmeta = "SELECT * FROM `{$source_prefix}woocommerce_order_itemmeta` WHERE order_item_id IN ($ids_items)";
                        $result_itemmeta = $mysqli->query($query_itemmeta);
                        fwrite($fh, "-- Exporting WooCommerce Order Item Meta\n");
                        while ($row = $result_itemmeta->fetch_assoc()) {
                            fwrite($fh, generate_sql_insert($dest_prefix . "woocommerce_order_itemmeta", $row, $export_mode));
                        }
                    }
                }
                fwrite($fh, "\n");
            }
            
            $mysqli->close();
            fclose($fh);
            exit;
        } else {
            // Progress mode: output progress messages to the browser.
            echo "<h2>Export Progress</h2>";
            echo "<div id='progress'>";
            echo "Starting export process...<br>";
            flush();
            
            // Write header info.
            echo "Writing header info...<br>"; flush();
            echo "-- SQL Export Generated on " . date('Y-m-d H:i:s') . "<br>";
            echo "-- Source DB: $source_db (prefix: $source_prefix)<br>";
            echo "-- Destination DB (for reference): $dest_db (prefix: $dest_prefix)<br>";
            echo "-- Export Mode: $export_mode<br><br>";
            flush();
            
            // Use a main export directory to hold subfolders.
            $base_export_dir = "exports/export_" . date("Ymd_His");
            if (!is_dir($base_export_dir)) mkdir($base_export_dir, 0777, true);
            
            // Use forking if available.
            if (function_exists('pcntl_fork')) {
                $children = array();
                $tasks = array();
                if ($options['export_products']) {
                    $tasks['products'] = function() use ($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir) {
                        export_products_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir);
                    };
                }
                if ($options['export_categories'] || $options['export_tags'] || $options['export_attributes']) {
                    $tasks['taxonomies'] = function() use ($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir, $options) {
                        export_taxonomies_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir, $options['export_categories'], $options['export_tags'], $options['export_attributes']);
                    };
                }
                if ($options['export_users'] || (!$options['export_users'] && $options['export_customers'])) {
                    $tasks['users'] = function() use ($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir, $options) {
                        export_users_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir, $options['export_users']);
                    };
                }
                if ($options['export_orders']) {
                    $tasks['orders'] = function() use ($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir) {
                        export_orders_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir);
                    };
                }
                foreach ($tasks as $task_name => $task) {
                    $pid = pcntl_fork();
                    if ($pid == -1) {
                        echo "Could not fork for $task_name.<br>";
                    } elseif ($pid) {
                        $children[$task_name] = $pid;
                    } else {
                        $task();
                        exit(0);
                    }
                }
                foreach ($children as $task_name => $pid) {
                    pcntl_waitpid($pid, $status);
                    echo ucfirst($task_name) . " export completed.<br><br>";
                    flush();
                }
            } else {
                // Sequential fallback.
                if ($options['export_products']) {
                    export_products_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir);
                    echo "Products export completed.<br><br>"; flush();
                }
                if ($options['export_categories'] || $options['export_tags'] || $options['export_attributes']) {
                    export_taxonomies_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir, $options['export_categories'], $options['export_tags'], $options['export_attributes']);
                    echo "Taxonomies export completed.<br><br>"; flush();
                }
                if ($options['export_users'] || (!$options['export_users'] && $options['export_customers'])) {
                    export_users_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir, $options['export_users']);
                    echo "Users export completed.<br><br>"; flush();
                }
                if ($options['export_orders']) {
                    export_orders_block($source_host, $source_user, $source_pass, $source_db, $source_prefix, $dest_prefix, $export_mode, $base_export_dir);
                    echo "Orders export completed.<br><br>"; flush();
                }
            }
            
            // Gather exported SQL files.
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_export_dir));
            $files_array = array();
            foreach ($files as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) == 'sql') {
                    $files_array[] = $file->getPathname();
                }
            }
            
            echo "<h3>Export Complete</h3>";
            echo "<p>Your export has been split into " . count($files_array) . " file(s). Download links:</p><ul>";
            foreach ($files_array as $file) {
                echo "<li><a href='$file' target='_blank'>$file</a></li>";
            }
            echo "</ul><p>Please download all files before closing this page.</p>";
            echo "</div>";
            exit;
        }
    } elseif (isset($_POST['action']) && $_POST['action'] == 'import') {
        // -----------------------
        // Import Process
        // -----------------------
        // Destination DB connection details.
        $dest_host = $_POST['dest_host'];
        $dest_user = $_POST['dest_user'];
        $dest_pass = $_POST['dest_pass'];
        $dest_db   = $_POST['dest_db'];
        $dest_prefix = $_POST['dest_prefix'];
        
        $mysqli = new mysqli($dest_host, $dest_user, $dest_pass, $dest_db);
        if ($mysqli->connect_error) {
            die("Destination DB Connection failed: " . $mysqli->connect_error);
        }
        
        echo "<h2>Import Progress</h2>"; flush();
        
        // Determine import source: "upload" or "folder"
        $import_source = $_POST['import_source'];
        
        if ($import_source == 'upload') {
            $uploaded_files = array();
            if (isset($_FILES['import_files'])) {
                foreach ($_FILES['import_files']['error'] as $key => $error) {
                    if ($error == UPLOAD_ERR_OK) {
                        $uploaded_files[] = array(
                            'name' => $_FILES['import_files']['name'][$key],
                            'tmp_name' => $_FILES['import_files']['tmp_name'][$key]
                        );
                    }
                }
            }
            if (empty($uploaded_files)) {
                echo "No files uploaded.<br>";
                exit;
            }
        } else {
            $export_folder = $_POST['export_folder'];
            if (!is_dir($export_folder)) {
                echo "Selected export folder does not exist.<br>";
                exit;
            }
        }
        
        // Import options from the form.
        $import_products = isset($_POST['import_products']);
        $import_categories = isset($_POST['import_categories']);
        $import_tags = isset($_POST['import_tags']);
        $import_attributes = isset($_POST['import_attributes']);
        $import_users = isset($_POST['import_users']);
        $import_customers = isset($_POST['import_customers']);
        $import_orders = isset($_POST['import_orders']);
        
        if ($import_source == 'upload') {
            if ($import_products) {
                import_group_from_upload($mysqli, $uploaded_files, 'products');
                echo "Imported Products.<br>"; flush();
            }
            $selectedTypes = array();
            if ($import_categories) $selectedTypes[] = 'product categories';
            if ($import_tags) $selectedTypes[] = 'product tags';
            if ($import_attributes) $selectedTypes[] = 'product attributes';
            if (!empty($selectedTypes)) {
                import_taxonomies_from_upload($mysqli, $uploaded_files, $selectedTypes);
                echo "Imported Taxonomies.<br>"; flush();
            }
            if ($import_users || $import_customers) {
                import_group_from_upload($mysqli, $uploaded_files, 'users');
                echo "Imported Users.<br>"; flush();
            }
            if ($import_orders) {
                import_group_from_upload($mysqli, $uploaded_files, 'orders');
                echo "Imported Orders.<br>"; flush();
            }
        } else {
            if ($import_products) {
                import_group_from_folder($mysqli, $export_folder, 'products');
                echo "Imported Products.<br>"; flush();
            }
            $selectedTypes = array();
            if ($import_categories) $selectedTypes[] = 'product categories';
            if ($import_tags) $selectedTypes[] = 'product tags';
            if ($import_attributes) $selectedTypes[] = 'product attributes';
            if (!empty($selectedTypes)) {
                import_taxonomies_from_folder($mysqli, $export_folder, $selectedTypes);
                echo "Imported Taxonomies.<br>"; flush();
            }
            if ($import_users || $import_customers) {
                import_group_from_folder($mysqli, $export_folder, 'users');
                echo "Imported Users.<br>"; flush();
            }
            if ($import_orders) {
                import_group_from_folder($mysqli, $export_folder, 'orders');
                echo "Imported Orders.<br>"; flush();
            }
        }
        echo "<h3>Import Complete</h3>";
        $mysqli->close();
        exit;
    }
}

// For the import tab, scan the "exports" directory for available export folders.
$exportFolders = array();
if (is_dir("exports")) {
    foreach (scandir("exports") as $dir) {
        if ($dir != "." && $dir != ".." && is_dir("exports/$dir")) {
            $exportFolders[] = "exports/$dir";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>CCMS WOO Export/Import Tool</title>
  <!-- Bootstrap CSS CDN -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    pre { background-color: #f8f9fa; padding: 10px; }
  </style>
</head>
<body>
  <div class="container my-5">
    <h1 class="mb-4">CCMS WOO Export/Import Tool</h1>
    <div class="alert alert-info">
      <h5>Important Instructions</h5>
      <p>This tool lets you export your WooCommerce tables and import them into another database. It offers two export methods:</p>
      <ul>
        <li><strong>Progress Updates:</strong> Displays live progress messages. This mode may help prevent timeouts on shared hosts but is slightly slower.</li>
        <li><strong>Direct Download:</strong> Streams the SQL file directly to your browser for a faster export. Note that very large exports may be interrupted on hosts with strict timeout limits.</li>
      </ul>
      <p><em>Please do not refresh or close your browser during export/import. Large DB operations can take a long time, so be patient.</em></p>
    </div>
    <!-- Nav Tabs (Export/Import) -->
    <ul class="nav nav-tabs" id="tabMenu" role="tablist">
      <li class="nav-item">
        <a class="nav-link active" id="export-tab" data-toggle="tab" href="#export" role="tab">Export</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" id="import-tab" data-toggle="tab" href="#import" role="tab">Import</a>
      </li>
    </ul>
    <div class="tab-content" id="tabContent">
      <!-- Export Tab -->
      <div class="tab-pane fade show active" id="export" role="tabpanel" aria-labelledby="export-tab">
        <form method="post">
          <input type="hidden" name="action" value="export">
          <h2>Source Database Connection</h2>
          <div class="form-group">
            <label>Source DB Host:</label>
            <input type="text" class="form-control" name="source_host" value="localhost" required>
          </div>
          <div class="form-group">
            <label>Source DB Username:</label>
            <input type="text" class="form-control" name="source_user" required>
          </div>
          <div class="form-group">
            <label>Source DB Password:</label>
            <input type="password" class="form-control" name="source_pass" required>
          </div>
          <div class="form-group">
            <label>Source DB Name:</label>
            <input type="text" class="form-control" name="source_db" required>
          </div>
          <div class="form-group">
            <label>Source Table Prefix:</label>
            <input type="text" class="form-control" name="source_prefix" required>
          </div>
          
          <h2>Destination Details (for SQL generation)</h2>
          <div class="form-group">
            <label>Destination DB Name (for reference):</label>
            <input type="text" class="form-control" name="dest_db">
          </div>
          <div class="form-group">
            <label>Destination Table Prefix:</label>
            <input type="text" class="form-control" name="dest_prefix" required>
          </div>
          
          <h2>Export Options</h2>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="export_products" id="export_products">
            <label class="form-check-label" for="export_products">Export Products (including product variations)</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="export_categories" id="export_categories" checked>
            <label class="form-check-label" for="export_categories">Export Product Categories</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="export_tags" id="export_tags">
            <label class="form-check-label" for="export_tags">Export Product Tags</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="export_attributes" id="export_attributes">
            <label class="form-check-label" for="export_attributes">Export Product Attributes (taxonomies starting with 'pa_')</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="export_users" id="export_users">
            <label class="form-check-label" for="export_users">Export All Users</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="export_customers" id="export_customers">
            <label class="form-check-label" for="export_customers">Export Customers Only (if All Users not selected)</label>
          </div>
          <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="export_orders" id="export_orders">
            <label class="form-check-label" for="export_orders">Export Orders</label>
          </div>
          
          <h2>Export Mode (SQL Generation)</h2>
          <div class="form-check">
            <input type="radio" class="form-check-input" name="export_mode" id="mode_append" value="append" checked>
            <label class="form-check-label" for="mode_append">Append (INSERT INTO)</label>
          </div>
          <div class="form-check">
            <input type="radio" class="form-check-input" name="export_mode" id="mode_overwrite" value="overwrite">
            <label class="form-check-label" for="mode_overwrite">Overwrite (REPLACE INTO)</label>
          </div>
          <div class="form-check mb-4">
            <input type="radio" class="form-check-input" name="export_mode" id="mode_update" value="update">
            <label class="form-check-label" for="mode_update">Update (INSERT ... ON DUPLICATE KEY UPDATE)</label>
          </div>
          
          <h2>Output Method</h2>
          <div class="form-check">
            <input type="radio" class="form-check-input" name="output_method" id="method_progress" value="progress" checked>
            <label class="form-check-label" for="method_progress">Progress Updates (slower but may prevent timeouts)</label>
          </div>
          <div class="form-check mb-4">
            <input type="radio" class="form-check-input" name="output_method" id="method_direct" value="direct">
            <label class="form-check-label" for="method_direct">Direct Download (faster, no progress shown)</label>
          </div>
          
          <button type="submit" class="btn btn-primary">Start Export</button>
        </form>
      </div>
      
      <!-- Import Tab -->
      <div class="tab-pane fade" id="import" role="tabpanel" aria-labelledby="import-tab">
        <div class="alert alert-info mt-3">
          <h5>Import Instructions</h5>
          <p>Select your destination database details and choose your import source. You can either upload your exported SQL files (multiple files allowed) or select an existing export folder from the server. Then, pick which parts you want to import.</p>
        </div>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="import">
          <h2>Destination Database Connection</h2>
          <div class="form-group">
            <label>Destination DB Host:</label>
            <input type="text" class="form-control" name="dest_host" value="localhost" required>
          </div>
          <div class="form-group">
            <label>Destination DB Username:</label>
            <input type="text" class="form-control" name="dest_user" required>
          </div>
          <div class="form-group">
            <label>Destination DB Password:</label>
            <input type="password" class="form-control" name="dest_pass" required>
          </div>
          <div class="form-group">
            <label>Destination DB Name:</label>
            <input type="text" class="form-control" name="dest_db" required>
          </div>
          <div class="form-group">
            <label>Destination Table Prefix:</label>
            <input type="text" class="form-control" name="dest_prefix" required>
          </div>
          <h2>Import Source</h2>
          <div class="form-check">
            <input type="radio" class="form-check-input" name="import_source" id="source_upload" value="upload" checked>
            <label class="form-check-label" for="source_upload">Upload Export Files</label>
          </div>
          <div class="form-group">
            <label>Choose Files:</label>
            <input type="file" class="form-control-file" name="import_files[]" multiple>
          </div>
          <div class="form-check">
            <input type="radio" class="form-check-input" name="import_source" id="source_folder" value="folder">
            <label class="form-check-label" for="source_folder">Select from Existing Export Folder</label>
          </div>
          <div class="form-group">
            <label>Export Folder:</label>
            <select class="form-control" name="export_folder">
              <?php
              foreach ($exportFolders as $folder) {
                  echo "<option value='$folder'>$folder</option>";
              }
              ?>
            </select>
          </div>
          <h2>Import Options</h2>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="import_products" id="import_products">
            <label class="form-check-label" for="import_products">Import Products (including product variations)</label>
          </div>
          <h4>Taxonomies</h4>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="import_categories" id="import_categories">
            <label class="form-check-label" for="import_categories">Import Product Categories</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="import_tags" id="import_tags">
            <label class="form-check-label" for="import_tags">Import Product Tags</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="import_attributes" id="import_attributes">
            <label class="form-check-label" for="import_attributes">Import Product Attributes (taxonomies starting with 'pa_')</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="import_users" id="import_users">
            <label class="form-check-label" for="import_users">Import All Users</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="import_customers" id="import_customers">
            <label class="form-check-label" for="import_customers">Import Customers Only (if All Users not selected)</label>
          </div>
          <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="import_orders" id="import_orders">
            <label class="form-check-label" for="import_orders">Import Orders</label>
          </div>
          <button type="submit" class="btn btn-primary">Start Import</button>
        </form>
      </div>
    </div>
  </div>
  <!-- Bootstrap JS and dependencies -->
  <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
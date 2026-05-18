<?php

$pass = 0;
$fail = 0;

function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "  PASS: $msg\n";
        $pass++;
    } else {
        echo "  FAIL: $msg\n";
        $fail++;
    }
}

function assert_same($actual, $expected, $msg) {
    assert_true(
        $actual === $expected,
        "$msg (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")"
    );
}

function run_merge_cli(array $args): array {
    $script = dirname(__DIR__, 2) . '/scripts/cow/merge.php';
    $command = array_map('escapeshellarg', array_merge([PHP_BINARY, $script], $args));
    exec(implode(' ', $command) . ' 2>&1', $output, $status);
    return [
        'status' => $status,
        'output' => implode("\n", $output) . ($output === [] ? '' : "\n"),
    ];
}

function remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function copy_tree_for_test(string $source, string $dest): void {
    mkdir($dest, 0777, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        $target = $dest . '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($source) + 1));
        if ($entry->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
            continue;
        }
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($entry->getPathname(), $target);
    }
}

function write_test_file(string $path, string $contents): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function create_test_symlink(string $target, string $link): bool {
    if (!is_dir(dirname($link))) {
        mkdir(dirname($link), 0777, true);
    }
    if ((file_exists($link) || is_link($link)) && !unlink($link)) {
        throw new RuntimeException("failed to replace test symlink: $link");
    }
    return @symlink($target, $link);
}

function open_db(string $path): SQLite3 {
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function scalar(string $db_path, string $sql): mixed {
    $db = open_db($db_path);
    $value = $db->querySingle($sql);
    $db->close();
    return $value;
}

function create_wp_semantic_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (30, 'Shared reusable block', '<!-- wp:paragraph --><p>Shared block</p><!-- /wp:paragraph -->', 'publish', 'wp_block', 'shared-reusable-block'),
        (31, 'Page with reusable block', '<!-- wp:block {\"ref\":30} /--><!-- wp:paragraph --><p>Base page content</p><!-- /wp:paragraph -->', 'publish', 'page', 'page-with-reusable-block'),
        (32, 'Shared synced pattern', '<!-- wp:paragraph --><p>Shared synced pattern</p><!-- /wp:paragraph -->', 'publish', 'wp_block', 'shared-synced-pattern'),
        (33, 'Page with synced pattern', '<!-- wp:block {\"ref\":32} /--><!-- wp:paragraph --><p>Base synced pattern content</p><!-- /wp:paragraph -->', 'publish', 'page', 'page-with-synced-pattern'),
        (35, 'Shared navigation', '<!-- wp:navigation-link {\"label\":\"Home\",\"url\":\"/\"} /-->', 'publish', 'wp_navigation', 'shared-navigation'),
        (36, 'Page with navigation block', '<!-- wp:navigation {\"ref\":35} /--><!-- wp:paragraph --><p>Base navigation page content</p><!-- /wp:paragraph -->', 'publish', 'page', 'page-with-navigation-block'),
        (37, 'Theme header template part', '<!-- wp:paragraph --><p>Header template part</p><!-- /wp:paragraph -->', 'publish', 'wp_template_part', 'forkpress-test//header'),
        (38, 'Template using header part', '<!-- wp:template-part {\"slug\":\"header\",\"theme\":\"forkpress-test\",\"tagName\":\"header\"} /--><!-- wp:paragraph --><p>Base template content</p><!-- /wp:paragraph -->', 'publish', 'wp_template', 'forkpress-test//front-page')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (34, 32, 'wp_pattern_sync_status', 'synced')");
    $db->close();
}

function create_wp_post_parent_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        post_parent INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, post_parent) VALUES
        (37, 'Deleted parent page', '<!-- wp:paragraph --><p>Parent page</p><!-- /wp:paragraph -->', 'publish', 'page', 'deleted-parent-page', 0),
        (38, 'Child page', '<!-- wp:paragraph --><p>Child page</p><!-- /wp:paragraph -->', 'publish', 'page', 'child-page', 37),
        (39, 'Child attachment', '', 'inherit', 'attachment', 'child-attachment', 37)");
    $db->close();
}

function create_wp_duplicate_page_route_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        post_parent INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, post_parent) VALUES
        (80, 'Shared parent page', '<!-- wp:paragraph --><p>Parent page</p><!-- /wp:paragraph -->', 'publish', 'page', 'shared-parent-page', 0)");
    $db->close();
}

function create_wp_duplicate_term_route_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (70, 'Shared parent category', 'shared-parent-category')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (70, 70, 'category', '', 0, 0)");
    $db->close();
}

function insert_wp_test_term(SQLite3 $db, string $name, string $slug, string $taxonomy, int $parent = 0): void {
    $stmt = $db->prepare('INSERT INTO wp_terms (name, slug) VALUES (:name, :slug)');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
    $stmt->execute();
    $term_id = (int)$db->lastInsertRowID();
    $stmt = $db->prepare('INSERT INTO wp_term_taxonomy (term_id, taxonomy, description, parent, count) VALUES (:term_id, :taxonomy, "", :parent, 0)');
    $stmt->bindValue(':term_id', $term_id, SQLITE3_INTEGER);
    $stmt->bindValue(':taxonomy', $taxonomy, SQLITE3_TEXT);
    $stmt->bindValue(':parent', $parent, SQLITE3_INTEGER);
    $stmt->execute();
}

function create_wp_duplicate_user_login_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        user_login TEXT NOT NULL,
        user_email TEXT NOT NULL DEFAULT '',
        display_name TEXT NOT NULL DEFAULT ''
    )");
    $db->close();
}

function insert_wp_test_user(SQLite3 $db, string $login, string $email, string $display_name): void {
    $stmt = $db->prepare('INSERT INTO wp_users (user_login, user_email, display_name) VALUES (:login, :email, :display_name)');
    $stmt->bindValue(':login', $login, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':display_name', $display_name, SQLITE3_TEXT);
    $stmt->execute();
}

function create_wp_global_styles_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->close();
}

function create_wp_site_editor_objects_db(string $path): void {
    create_wp_global_styles_db($path);
}

function create_wp_active_plugin_db(string $path, string $plugin): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_options (
        option_id INTEGER PRIMARY KEY AUTOINCREMENT,
        option_name TEXT NOT NULL,
        option_value TEXT NOT NULL,
        autoload TEXT NOT NULL DEFAULT 'yes'
    )");
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('active_plugins', :plugins, 'yes')");
    $stmt->bindValue(':plugins', serialize([$plugin]), SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function write_wp_block_asset_plugin(string $root): void {
    write_test_file($root . '/wp-content/plugins/forkpress-block-fixture/forkpress-block-fixture.php', "<?php\n");
    write_test_file($root . '/wp-content/plugins/forkpress-block-fixture/src/block.json', json_encode([
        'apiVersion' => 3,
        'name' => 'forkpress-fixture/example',
        'title' => 'ForkPress Fixture',
        'editorScript' => 'file:./index.js',
        'viewScript' => 'file:https://example.test/remote-block.js',
        'render' => 'file:C:/forkpress-block-fixture/render.php',
        'style' => ['file:./style.css'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    write_test_file($root . '/wp-content/plugins/forkpress-block-fixture/src/index.js', 'console.log("fixture");');
    write_test_file($root . '/wp-content/plugins/forkpress-block-fixture/src/style.css', '.wp-block-forkpress-fixture-example{}');
}

function create_wp_post_author_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        user_login TEXT NOT NULL,
        user_email TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        post_author INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("INSERT INTO wp_users (ID, user_login, user_email) VALUES (44, 'deleted_post_author', 'deleted-post-author@example.test')");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, post_author) VALUES
        (45, 'Page by deleted author', '<!-- wp:paragraph --><p>Authored page</p><!-- /wp:paragraph -->', 'publish', 'page', 'page-by-deleted-author', 44),
        (46, 'Attachment by deleted author', '', 'inherit', 'attachment', 'attachment-by-deleted-author', 44)");
    $db->close();
}

function create_wp_postmeta_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (52, 'Deleted postmeta owner', '<!-- wp:paragraph --><p>Postmeta owner</p><!-- /wp:paragraph -->', 'publish', 'page', 'deleted-postmeta-owner')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
        (53, 52, '_forkpress_meta_note', 'Base postmeta note'),
        (54, 52, '_forkpress_meta_json', '{\"favorite\":\"base\"}')");
    $db->close();
}

function create_wp_usermeta_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        user_login TEXT NOT NULL,
        user_email TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_users (ID, user_login, user_email) VALUES (49, 'deleted_usermeta_owner', 'deleted-usermeta-owner@example.test')");
    $db->exec("INSERT INTO wp_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES
        (50, 49, 'description', 'Base user description'),
        (51, 49, 'forkpress_profile_json', '{\"favorite\":\"base\"}')");
    $db->close();
}

function create_wp_menu_ref_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (40, 'Shared menu page', '<!-- wp:paragraph --><p>Menu page</p><!-- /wp:paragraph -->', 'publish', 'page', 'shared-menu-page'),
        (41, 'Menu item for shared page', '', 'publish', 'nav_menu_item', 'menu-item-shared-page'),
        (42, 'Menu item for shared category', '', 'publish', 'nav_menu_item', 'menu-item-shared-category')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (43, 'Shared menu category', 'shared-menu-category')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (43, 43, 'category', '', 0, 0)");
    $db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
        (41, '_menu_item_type', 'post_type'),
        (41, '_menu_item_object', 'page'),
        (41, '_menu_item_object_id', '40'),
        (41, '_menu_item_menu_item_parent', '0'),
        (42, '_menu_item_type', 'taxonomy'),
        (42, '_menu_item_object', 'category'),
        (42, '_menu_item_object_id', '43'),
        (42, '_menu_item_menu_item_parent', '0')");
    $db->close();
}

function create_wp_menu_parent_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (47, 'Deleted parent menu item', '', 'publish', 'nav_menu_item', 'deleted-parent-menu-item'),
        (48, 'Child menu item', '', 'publish', 'nav_menu_item', 'child-menu-item')");
    $db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
        (47, '_menu_item_type', 'custom'),
        (47, '_menu_item_menu_item_parent', '0'),
        (48, '_menu_item_type', 'custom'),
        (48, '_menu_item_menu_item_parent', '47')");
    $db->close();
}

function create_wp_featured_media_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (60, 'Featured media page', '<!-- wp:paragraph --><p>Featured media page</p><!-- /wp:paragraph -->', 'publish', 'page', 'featured-media-page', ''),
        (61, 'Featured attachment', '', 'inherit', 'attachment', 'featured-attachment', 'wp-content/uploads/2026/05/featured-image.jpg')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (6000, 60, '_thumbnail_id', '61')");
    $db->close();
}

function create_wp_attachment_upload_metadata_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (63, 'Attachment with generated files', '', 'inherit', 'attachment', 'generated-attachment', 'wp-content/uploads/2026/05/generated-image.jpg')");
    $metadata = serialize([
        'file' => '2026/05/generated-image.jpg',
        'width' => 1200,
        'height' => 800,
        'sizes' => [
            'thumbnail' => [
                'file' => 'generated-image-150x150.jpg',
                'width' => 150,
                'height' => 150,
            ],
            'medium' => [
                'file' => 'generated-image-300x200.jpg',
                'width' => 300,
                'height' => 200,
            ],
        ],
        'original_image' => 'generated-image-original.jpg',
        'backup_sizes' => [
            'full-orig' => [
                'file' => 'generated-image-backup.jpg',
                'width' => 1200,
                'height' => 800,
            ],
        ],
    ]);
    $stmt = $db->prepare("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
        (6300, 63, '_wp_attached_file', '2026/05/generated-image.jpg'),
        (6301, 63, '_wp_attachment_metadata', :metadata)");
    $stmt->bindValue(':metadata', $metadata, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function create_wp_image_block_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $image_block_content = '<!-- wp:image {"id":71,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="wp-content/uploads/2026/05/block-image.jpg" class="wp-image-71"/></figure><!-- /wp:image -->';
    $classic_image_content = '<p><img src="wp-content/uploads/2026/05/block-image.jpg" class="alignnone wp-image-71" /></p>';
    $classic_gallery_content = '[gallery ids="71"]';
    $stmt = $db->prepare("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (70, 'Image block page', :content, 'publish', 'page', 'image-block-page', ''),
        (75, 'Image block product CPT', :content, 'publish', 'forkpress_product', 'image-block-product', ''),
        (76, 'Classic image page', :classic_image, 'publish', 'page', 'classic-image-page', ''),
        (77, 'Classic gallery page', :classic_gallery, 'publish', 'page', 'classic-gallery-page', ''),
        (71, 'Image block attachment', '', 'inherit', 'attachment', 'block-image', 'wp-content/uploads/2026/05/block-image.jpg')");
    $stmt->bindValue(':content', $image_block_content, SQLITE3_TEXT);
    $stmt->bindValue(':classic_image', $classic_image_content, SQLITE3_TEXT);
    $stmt->bindValue(':classic_gallery', $classic_gallery_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function create_wp_media_block_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $media_block_content = '<!-- wp:audio {"id":182} --><figure class="wp-block-audio"><audio controls src="wp-content/uploads/2026/05/block-audio.mp3"></audio></figure><!-- /wp:audio -->'
        . '<!-- wp:cover {"id":183,"url":"wp-content/uploads/2026/05/block-cover.jpg"} --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background"></span><img class="wp-block-cover__image-background wp-image-183" alt="" src="wp-content/uploads/2026/05/block-cover.jpg"/></div><!-- /wp:cover -->'
        . '<!-- wp:file {"id":184,"href":"wp-content/uploads/2026/05/block-file.pdf"} --><div class="wp-block-file"><a href="wp-content/uploads/2026/05/block-file.pdf">Download</a></div><!-- /wp:file -->'
        . '<!-- wp:video {"id":185} --><figure class="wp-block-video"><video controls src="wp-content/uploads/2026/05/block-video.mp4"></video></figure><!-- /wp:video -->'
        . '<!-- wp:media-text {"mediaId":186,"mediaType":"image"} --><div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="wp-content/uploads/2026/05/block-media-text.jpg" class="wp-image-186"/></figure><div class="wp-block-media-text__content"><p>Media text</p></div></div><!-- /wp:media-text -->';
    $stmt = $db->prepare("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (181, 'Media block page', :content, 'publish', 'page', 'media-block-page', ''),
        (182, 'Audio block attachment', '', 'inherit', 'attachment', 'block-audio', 'wp-content/uploads/2026/05/block-audio.mp3'),
        (183, 'Cover block attachment', '', 'inherit', 'attachment', 'block-cover', 'wp-content/uploads/2026/05/block-cover.jpg'),
        (184, 'File block attachment', '', 'inherit', 'attachment', 'block-file', 'wp-content/uploads/2026/05/block-file.pdf'),
        (185, 'Video block attachment', '', 'inherit', 'attachment', 'block-video', 'wp-content/uploads/2026/05/block-video.mp4'),
        (186, 'Media text attachment', '', 'inherit', 'attachment', 'block-media-text', 'wp-content/uploads/2026/05/block-media-text.jpg')");
    $stmt->bindValue(':content', $media_block_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function create_wp_avatar_navigation_link_block_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        user_login TEXT NOT NULL,
        user_pass TEXT NOT NULL DEFAULT '',
        user_nicename TEXT NOT NULL DEFAULT '',
        user_email TEXT NOT NULL DEFAULT '',
        user_url TEXT NOT NULL DEFAULT '',
        user_registered TEXT NOT NULL DEFAULT '2026-05-16 00:00:00',
        user_activation_key TEXT NOT NULL DEFAULT '',
        user_status INTEGER NOT NULL DEFAULT 0,
        display_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL, term_group INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $block_content = '<!-- wp:avatar {"userId":188,"size":96} /-->'
        . '<!-- wp:navigation-link {"id":189,"kind":"post-type","type":"page","label":"Deleted page","url":"/deleted-page"} /-->'
        . '<!-- wp:navigation-link {"id":190,"kind":"taxonomy","type":"category","label":"Deleted category","url":"/category/deleted"} /-->'
        . '<!-- wp:navigation-submenu {"id":191,"kind":"post-type","type":"page","label":"Deleted submenu page","url":"/deleted-submenu-page"} --><!-- /wp:navigation-submenu -->';
    $stmt = $db->prepare("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (187, 'Avatar and navigation link page', :content, 'publish', 'page', 'avatar-navigation-link-page'),
        (189, 'Deleted navigation page', '<!-- wp:paragraph --><p>Deleted nav page</p><!-- /wp:paragraph -->', 'publish', 'page', 'deleted-navigation-page'),
        (191, 'Deleted navigation submenu page', '<!-- wp:paragraph --><p>Deleted nav submenu page</p><!-- /wp:paragraph -->', 'publish', 'page', 'deleted-navigation-submenu-page')");
    $stmt->bindValue(':content', $block_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->exec("INSERT INTO wp_users (ID, user_login, user_nicename, user_email, display_name) VALUES
        (188, 'deleted_avatar_user', 'deleted-avatar-user', 'avatar-user@example.com', 'Deleted Avatar User')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (190, 'Deleted navigation category', 'deleted-navigation-category')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, count) VALUES
        (191, 190, 'category', 'Deleted navigation category taxonomy', 1)");
    $db->close();
}

function create_wp_gallery_block_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $gallery_block_content = '<!-- wp:gallery {"ids":[73,74],"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":73,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="wp-content/uploads/2026/05/gallery-deleted.jpg" class="wp-image-73"/></figure><!-- /wp:image --><!-- wp:image {"id":74,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="wp-content/uploads/2026/05/gallery-kept.jpg" class="wp-image-74"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
    $stmt = $db->prepare("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (72, 'Gallery block page', :content, 'publish', 'page', 'gallery-block-page', ''),
        (73, 'Deleted gallery attachment', '', 'inherit', 'attachment', 'deleted-gallery-attachment', 'wp-content/uploads/2026/05/gallery-deleted.jpg'),
        (74, 'Kept gallery attachment', '', 'inherit', 'attachment', 'kept-gallery-attachment', 'wp-content/uploads/2026/05/gallery-kept.jpg')");
    $stmt->bindValue(':content', $gallery_block_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function create_wp_query_block_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        user_login TEXT NOT NULL,
        user_pass TEXT NOT NULL DEFAULT '',
        user_nicename TEXT NOT NULL DEFAULT '',
        user_email TEXT NOT NULL DEFAULT '',
        user_url TEXT NOT NULL DEFAULT '',
        user_registered TEXT NOT NULL DEFAULT '2026-05-16 00:00:00',
        user_activation_key TEXT NOT NULL DEFAULT '',
        user_status INTEGER NOT NULL DEFAULT 0,
        display_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL, term_group INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $query_block_content = '<!-- wp:query {"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":75,"categoryIds":[76],"tagIds":[78],"taxQuery":{"category":[83],"post_tag":[85]}}} --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query --><!-- wp:latest-posts {"selectedAuthor":75,"categories":[83],"postsToShow":5} /-->';
    $stmt = $db->prepare("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (79, 'Query block page', :content, 'publish', 'page', 'query-block-page')");
    $stmt->bindValue(':content', $query_block_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->exec("INSERT INTO wp_users (ID, user_login, user_nicename, user_email, display_name) VALUES
        (75, 'deleted_query_author', 'deleted-query-author', 'query-author@example.com', 'Deleted Query Author')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (76, 'Deleted query category', 'deleted-query-category'),
        (78, 'Deleted query tag', 'deleted-query-tag'),
        (83, 'Deleted taxQuery category', 'deleted-taxquery-category'),
        (85, 'Deleted taxQuery tag', 'deleted-taxquery-tag')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, count) VALUES
        (77, 76, 'category', 'Deleted query category taxonomy', 1),
        (80, 78, 'post_tag', 'Deleted query tag taxonomy', 1),
        (84, 83, 'category', 'Deleted taxQuery category taxonomy', 1),
        (86, 85, 'post_tag', 'Deleted taxQuery tag taxonomy', 1)");
    $db->close();
}

function create_wp_term_relationship_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL, term_group INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_term_relationships (object_id INTEGER NOT NULL, term_taxonomy_id INTEGER NOT NULL, term_order INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (object_id, term_taxonomy_id))');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (80, 'Term relationship page', '<!-- wp:paragraph --><p>Term relationship page</p><!-- /wp:paragraph -->', 'publish', 'page', 'term-relationship-page')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (81, 'Retired category', 'retired-category')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, count) VALUES (82, 81, 'category', '', 0)");
    $db->close();
}

function create_wp_term_taxonomy_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL, term_group INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (89, 'Deleted taxonomy owner', 'deleted-taxonomy-owner')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, count) VALUES
        (90, 89, 'category', 'Base category taxonomy', 0),
        (91, 89, 'post_tag', 'Base tag taxonomy', 0)");
    $db->close();
}

function create_wp_term_parent_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL, term_group INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (83, 'Deleted parent category', 'deleted-parent-category'),
        (85, 'Child category', 'child-category')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (84, 83, 'category', 'Parent category description', 0, 1),
        (86, 85, 'category', 'Child category description', 83, 0)");
    $db->close();
}

function create_wp_termmeta_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL, term_group INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_termmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (87, 'Deleted termmeta owner', 'deleted-termmeta-owner')");
    $db->exec("INSERT INTO wp_termmeta (meta_id, term_id, meta_key, meta_value) VALUES
        (88, 87, '_forkpress_term_note', 'Base termmeta note'),
        (89, 87, '_forkpress_term_json', '{\"favorite\":\"base\"}')");
    $db->close();
}

function create_wp_comment_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        user_login TEXT NOT NULL,
        user_email TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_comments (
        comment_ID INTEGER PRIMARY KEY AUTOINCREMENT,
        comment_post_ID INTEGER NOT NULL DEFAULT 0,
        comment_content TEXT NOT NULL DEFAULT '',
        comment_parent INTEGER NOT NULL DEFAULT 0,
        user_id INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec('CREATE TABLE wp_commentmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, comment_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (120, 'Deleted comment host page', '<!-- wp:paragraph --><p>Comment host</p><!-- /wp:paragraph -->', 'publish', 'page', 'deleted-comment-host-page'),
        (127, 'Surviving comment host page', '<!-- wp:paragraph --><p>Surviving host</p><!-- /wp:paragraph -->', 'publish', 'page', 'surviving-comment-host-page')");
    $db->exec("INSERT INTO wp_users (ID, user_login, user_email) VALUES (121, 'deleted_comment_author', 'deleted-comment-author@example.test')");
    $db->exec("INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_content, comment_parent, user_id) VALUES
        (122, 120, 'Base comment pointing at deleted post and user', 0, 121),
        (123, 127, 'Base comment with metadata', 0, 0),
        (125, 127, 'Base parent comment', 0, 0),
        (126, 127, 'Base child comment', 125, 0)");
    $db->exec("INSERT INTO wp_commentmeta (meta_id, comment_id, meta_key, meta_value) VALUES (124, 123, '_forkpress_comment_note', 'base comment metadata')");
    $db->close();
}

function create_wp_option_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_options (
        option_id INTEGER PRIMARY KEY AUTOINCREMENT,
        option_name TEXT NOT NULL,
        option_value TEXT NOT NULL,
        autoload TEXT NOT NULL DEFAULT 'yes'
    )");
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (90, 'Featured option page', '<!-- wp:paragraph --><p>Featured option page</p><!-- /wp:paragraph -->', 'publish', 'page', 'featured-option-page', ''),
        (91, 'Primary posts page', '<!-- wp:paragraph --><p>Primary posts page</p><!-- /wp:paragraph -->', 'publish', 'page', 'primary-posts-page', ''),
        (92, 'Sticky option post', '<!-- wp:paragraph --><p>Sticky option post</p><!-- /wp:paragraph -->', 'publish', 'post', 'sticky-option-post', ''),
        (93, 'Option logo', '', 'inherit', 'attachment', 'option-logo', 'http://example.test/wp-content/uploads/2026/05/option-logo.jpg')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (94, 'Primary menu', 'primary-menu')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (94, 94, 'nav_menu', '', 0, 1)");

    $theme_mods = serialize([
        'forkpress_featured_page' => 90,
        'nav_menu_locations' => [
            'primary' => 94,
        ],
        'custom_logo' => 93,
        'forkpress_accent' => 'base',
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('theme_mods_forkpress_active', :value, 'yes')");
    $stmt->bindValue(':value', $theme_mods, SQLITE3_TEXT);
    $stmt->execute();
    $db->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('site_icon', '93', 'yes')");
    $db->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('page_on_front', '90', 'yes')");
    $db->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('page_for_posts', '91', 'yes')");

    $sticky_posts = serialize([92]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sticky_posts', :value, 'yes')");
    $stmt->bindValue(':value', $sticky_posts, SQLITE3_TEXT);
    $stmt->execute();

    $widget_nav_menu = serialize([
        2 => [
            'title' => 'Footer menu',
            'nav_menu' => 94,
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_nav_menu', :value, 'yes')");
    $stmt->bindValue(':value', $widget_nav_menu, SQLITE3_TEXT);
    $stmt->execute();

    $nav_menu_options = serialize([
        'auto_add' => [94],
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('nav_menu_options', :value, 'yes')");
    $stmt->bindValue(':value', $nav_menu_options, SQLITE3_TEXT);
    $stmt->execute();

    $widget_media_image = serialize([
        3 => [
            'attachment_id' => 93,
            'url' => 'http://example.test/wp-content/uploads/2026/05/option-logo.jpg',
            'caption' => 'Base media image widget',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_media_image', :value, 'yes')");
    $stmt->bindValue(':value', $widget_media_image, SQLITE3_TEXT);
    $stmt->execute();

    $widget_media_audio = serialize([
        6 => [
            'attachment_id' => 93,
            'caption' => 'Base media audio widget',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_media_audio', :value, 'yes')");
    $stmt->bindValue(':value', $widget_media_audio, SQLITE3_TEXT);
    $stmt->execute();

    $widget_media_video = serialize([
        7 => [
            'attachment_id' => 93,
            'caption' => 'Base media video widget',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_media_video', :value, 'yes')");
    $stmt->bindValue(':value', $widget_media_video, SQLITE3_TEXT);
    $stmt->execute();

    $widget_media_gallery = serialize([
        8 => [
            'ids' => [93],
            'caption' => 'Base media gallery widget',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_media_gallery', :value, 'yes')");
    $stmt->bindValue(':value', $widget_media_gallery, SQLITE3_TEXT);
    $stmt->execute();

    $widget_pages = serialize([
        9 => [
            'title' => 'Base pages widget',
            'exclude' => '90',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_pages', :value, 'yes')");
    $stmt->bindValue(':value', $widget_pages, SQLITE3_TEXT);
    $stmt->execute();

    $widget_block = serialize([
        5 => [
            'content' => '<!-- wp:image {"id":93,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img class="wp-image-93"/></figure><!-- /wp:image -->',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_block', :value, 'yes')");
    $stmt->bindValue(':value', $widget_block, SQLITE3_TEXT);
    $stmt->execute();

    $widget_text = serialize([
        4 => [
            'title' => 'Base text widget',
            'text' => '<!-- wp:image {"id":93,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img class="wp-image-93"/></figure><!-- /wp:image -->',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_text', :value, 'yes')");
    $stmt->bindValue(':value', $widget_text, SQLITE3_TEXT);
    $stmt->execute();

    $widget_rss = serialize([
        12 => [
            'title' => 'Base RSS widget',
            'url' => 'https://example.test/feed/',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_rss', :value, 'yes')");
    $stmt->bindValue(':value', $widget_rss, SQLITE3_TEXT);
    $stmt->execute();

    $widget_custom_html = serialize([
        10 => [
            'title' => 'Base custom HTML widget',
            'content' => '<!-- wp:image {"id":93,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img class="wp-image-93"/></figure><!-- /wp:image -->',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_custom_html', :value, 'yes')");
    $stmt->bindValue(':value', $widget_custom_html, SQLITE3_TEXT);
    $stmt->execute();

    $sidebars_widgets = serialize([
        'sidebar-1' => ['nav_menu-2', 'media_image-3', 'text-4', 'custom_html-10', 'rss-12'],
        'array_version' => 3,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sidebars_widgets', :value, 'yes')");
    $stmt->bindValue(':value', $sidebars_widgets, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function create_wp_scalar_option_owner_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_options (
        option_id INTEGER PRIMARY KEY AUTOINCREMENT,
        option_name TEXT NOT NULL,
        option_value TEXT NOT NULL,
        autoload TEXT NOT NULL DEFAULT 'yes'
    )");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (130, 'Target front page candidate', '<!-- wp:paragraph --><p>Front page</p><!-- /wp:paragraph -->', 'publish', 'page', 'target-front-page-candidate', ''),
        (131, 'Target posts page candidate', '<!-- wp:paragraph --><p>Posts page</p><!-- /wp:paragraph -->', 'publish', 'page', 'target-posts-page-candidate', ''),
        (132, 'Target site icon attachment', '', 'inherit', 'attachment', 'target-site-icon', 'wp-content/uploads/2026/05/site-icon.png')");
    $db->exec("INSERT INTO wp_options (option_id, option_name, option_value, autoload) VALUES
        (1300, 'page_on_front', '0', 'yes'),
        (1301, 'page_for_posts', '0', 'yes'),
        (1302, 'site_icon', '0', 'yes')");
    $db->close();
}

function create_wp_serialized_option_owner_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_terms (
        term_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL,
        term_group INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE TABLE wp_users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        user_login TEXT NOT NULL,
        user_email TEXT NOT NULL DEFAULT '',
        display_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_term_taxonomy (
        term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT,
        term_id INTEGER NOT NULL,
        taxonomy TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        parent INTEGER NOT NULL DEFAULT 0,
        count INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE TABLE wp_options (
        option_id INTEGER PRIMARY KEY AUTOINCREMENT,
        option_name TEXT NOT NULL,
        option_value TEXT NOT NULL,
        autoload TEXT NOT NULL DEFAULT 'yes'
    )");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (140, 'Sticky post candidate', '<!-- wp:paragraph --><p>Sticky post</p><!-- /wp:paragraph -->', 'publish', 'post', 'sticky-post-candidate', ''),
        (141, 'Pages widget candidate', '<!-- wp:paragraph --><p>Pages widget</p><!-- /wp:paragraph -->', 'publish', 'page', 'pages-widget-candidate', ''),
        (142, 'Serialized media attachment', '', 'inherit', 'attachment', 'serialized-media-attachment', 'wp-content/uploads/2026/05/serialized-media.jpg'),
        (145, 'Serialized content attachment', '', 'inherit', 'attachment', 'serialized-content-attachment', 'wp-content/uploads/2026/05/serialized-content.jpg')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug, term_group) VALUES
        (143, 'Serialized Menu', 'serialized-menu', 0)");
    $db->exec("INSERT INTO wp_users (ID, user_login, user_email, display_name) VALUES
        (144, 'serialized_widget_author', 'serialized-widget-author@example.test', 'Serialized Widget Author')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (1430, 143, 'nav_menu', '', 0, 0)");

    $options = [
        [1400, 'sticky_posts', serialize([140])],
        [1401, 'theme_mods_forkpress_active', serialize([
            'custom_logo' => 142,
            'nav_menu_locations' => ['primary' => 143],
            'forkpress_accent' => 'base',
        ])],
        [1402, 'widget_nav_menu', serialize([
            2 => ['title' => 'Base menu widget', 'nav_menu' => 143],
            '_multiwidget' => 1,
        ])],
        [1403, 'nav_menu_options', serialize(['auto_add' => [143]])],
        [1404, 'widget_media_image', serialize([
            3 => ['attachment_id' => 142, 'caption' => 'Base image widget'],
            '_multiwidget' => 1,
        ])],
        [1405, 'widget_media_audio', serialize([
            4 => ['attachment_id' => 142, 'caption' => 'Base audio widget'],
            '_multiwidget' => 1,
        ])],
        [1406, 'widget_media_video', serialize([
            5 => ['attachment_id' => 142, 'caption' => 'Base video widget'],
            '_multiwidget' => 1,
        ])],
        [1407, 'widget_media_gallery', serialize([
            6 => ['ids' => [142], 'caption' => 'Base gallery widget'],
            '_multiwidget' => 1,
        ])],
        [1408, 'widget_pages', serialize([
            7 => ['exclude' => '141', 'title' => 'Base pages widget'],
            '_multiwidget' => 1,
        ])],
        [1409, 'widget_block', serialize([
            8 => ['content' => '<!-- wp:avatar {"userId":144} /-->', 'title' => 'Base block widget'],
            '_multiwidget' => 1,
        ])],
        [1410, 'widget_text', serialize([
            9 => ['text' => '<!-- wp:image {"id":145} --><figure class="wp-block-image"><img class="wp-image-145"/></figure><!-- /wp:image -->', 'title' => 'Base text widget'],
            '_multiwidget' => 1,
        ])],
        [1411, 'widget_custom_html', serialize([
            10 => ['content' => '<!-- wp:gallery {"ids":[145]} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->', 'title' => 'Base custom HTML widget'],
            '_multiwidget' => 1,
        ])],
    ];
    $stmt = $db->prepare('INSERT INTO wp_options (option_id, option_name, option_value, autoload) VALUES (:id, :name, :value, :autoload)');
    foreach ($options as [$option_id, $option_name, $option_value]) {
        $stmt->bindValue(':id', $option_id, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $option_name, SQLITE3_TEXT);
        $stmt->bindValue(':value', $option_value, SQLITE3_TEXT);
        $stmt->bindValue(':autoload', 'yes', SQLITE3_TEXT);
        $stmt->execute();
    }
    $db->close();
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW WordPress semantic validator focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-wp-semantic-validator-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base_root = $tmp . '/base';
    $source_root = $tmp . '/source';
    $target_root = $tmp . '/target';
    $base = $base_root . '/wp-content/database/.ht.sqlite';
    $source = $source_root . '/wp-content/database/.ht.sqlite';
    $target = $target_root . '/wp-content/database/.ht.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/wp-semantic-validator-metadata.sqlite';
    $file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-semantic-validator.json';

    mkdir($base_root . '/wp-content/database', 0777, true);
    create_wp_semantic_db($base);
    write_test_file($base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT ID, post_content FROM wp_posts WHERE post_type NOT IN ('attachment', 'revision') AND post_content <> ''");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $content = (string)$row['post_content'];
    preg_match_all('/<!--\s+wp:block\s+\{[^}]*"ref"\s*:\s*(\d+)/', $content, $block_matches);
    foreach ($block_matches[1] as $ref) {
        $ref_id = (int)$ref;
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $ref_id AND post_type = 'wp_block'");
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-block-refs',
                'object' => 'post:' . $row['ID'],
                'reason' => 'post content references a missing reusable block',
                'type' => 'plugin-wp-block-missing-reference',
                'tables' => ['wp_posts'],
                'validator' => 'forkpress-wp-block-refs@1',
                'candidate' => [
                    'post_id' => (int)$row['ID'],
                    'missing_ref' => $ref_id,
                ],
            ];
        }
    }
    preg_match_all('/<!--\s+wp:navigation\s+\{[^}]*"ref"\s*:\s*(\d+)/', $content, $navigation_matches);
    foreach ($navigation_matches[1] as $ref) {
        $ref_id = (int)$ref;
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $ref_id AND post_type = 'wp_navigation'");
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-block-refs',
                'object' => 'post:' . $row['ID'],
                'reason' => 'post content references a missing navigation block',
                'type' => 'plugin-wp-block-missing-reference',
                'tables' => ['wp_posts'],
                'validator' => 'forkpress-wp-block-refs@1',
                'candidate' => [
                    'post_id' => (int)$row['ID'],
                    'missing_ref' => $ref_id,
                    'block_name' => 'core/navigation',
                    'expected_post_type' => 'wp_navigation',
                ],
            ];
        }
    }
    preg_match_all('/<!--\s+wp:template-part\s+(\{.*?\})\s*\/?-->/', $content, $template_part_matches);
    foreach ($template_part_matches[1] as $raw_attrs) {
        $attrs = json_decode($raw_attrs, true);
        if (!is_array($attrs) || !isset($attrs['slug']) || !is_string($attrs['slug']) || $attrs['slug'] === '') {
            continue;
        }
        $slug = $attrs['slug'];
        $theme = isset($attrs['theme']) && is_string($attrs['theme']) ? $attrs['theme'] : '';
        $post_names = [$slug];
        if ($theme !== '') {
            array_unshift($post_names, $theme . '//' . $slug);
        }
        $exists = 0;
        foreach (array_unique($post_names) as $post_name) {
            $escaped_post_name = SQLite3::escapeString($post_name);
            $exists += (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_template_part' AND post_name = '$escaped_post_name'");
        }
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-block-refs',
                'object' => 'post:' . $row['ID'],
                'reason' => 'post content references a missing template part',
                'type' => 'plugin-wp-block-missing-reference',
                'tables' => ['wp_posts'],
                'validator' => 'forkpress-wp-block-refs@1',
                'candidate' => [
                    'post_id' => (int)$row['ID'],
                    'missing_template_part' => [
                        'theme' => $theme,
                        'slug' => $slug,
                    ],
                    'block_name' => 'core/template-part',
                    'expected_post_type' => 'wp_template_part',
                ],
            ];
        }
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);

    copy_tree_for_test($base_root, $source_root);
    copy_tree_for_test($base_root, $target_root);
    cow_merge_capture_file_base($base_root, $file_base);
    cow_merge_allocate_autoincrement_bands($source, $metadata, 'feature-wp-semantic-source');
    cow_merge_allocate_autoincrement_bands($target, $metadata, 'main');

    $db = open_db($source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 30');
    $db->exec('DELETE FROM wp_posts WHERE ID = 32');
    $db->exec('DELETE FROM wp_posts WHERE ID = 35');
    $db->exec('DELETE FROM wp_posts WHERE ID = 37');
    $db->exec('DELETE FROM wp_postmeta WHERE post_id = 32');
    $db->close();

    $db = open_db($target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using reusable block' WHERE ID = 31");
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using synced pattern' WHERE ID = 33");
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using navigation block' WHERE ID = 36");
    $db->exec("UPDATE wp_posts SET post_title = 'Target template still using header part' WHERE ID = 38");
    $db->close();

    $result = cow_merge_branch_state(
        $base,
        $source,
        $target,
        $metadata,
        'feature-wp-semantic-source',
        'main',
        $file_base,
        $source_root,
        $target_root
    );

    assert_same($result['status'], 'completed_with_conflicts', 'WordPress block-reference validator holds missing reusable blocks, synced patterns, navigation blocks, and template parts for review');
    assert_same((int)($result['plugin_validators'] ?? 0), 1, 'WordPress block-reference validator is discovered from mu-plugins during merge');
    assert_same((int)($result['plugin_validator_conflicts'] ?? 0), 4, 'WordPress block-reference validator records missing reusable block, synced pattern, navigation, and template-part references');
    assert_same((int)scalar($target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 30'), 0, 'WordPress block-reference validator leaves the source block deletion staged for review');
    assert_same((int)scalar($target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 32'), 0, 'WordPress block-reference validator leaves the source synced pattern deletion staged for review');
    assert_same((int)scalar($target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 35'), 0, 'WordPress block-reference validator leaves the source navigation deletion staged for review');
    assert_same((int)scalar($target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 37'), 0, 'WordPress block-reference validator leaves the source template-part deletion staged for review');
    assert_same((int)scalar($target, 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 32'), 0, 'WordPress block-reference validator leaves the synced pattern metadata deletion staged for review');
    assert_same(scalar($target, 'SELECT post_title FROM wp_posts WHERE ID = 31'), 'Target page still using reusable block', 'WordPress block-reference validator preserves the target page edit');
    assert_same(scalar($target, 'SELECT post_title FROM wp_posts WHERE ID = 33'), 'Target page still using synced pattern', 'WordPress block-reference validator preserves the target synced pattern page edit');
    assert_same(scalar($target, 'SELECT post_title FROM wp_posts WHERE ID = 36'), 'Target page still using navigation block', 'WordPress block-reference validator preserves the target navigation page edit');
    assert_same(scalar($target, 'SELECT post_title FROM wp_posts WHERE ID = 38'), 'Target template still using header part', 'WordPress block-reference validator preserves the target template edit');

    $audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-block-missing-reference',
    ]);
    assert_same(count($audit['conflicts']), 4, 'WordPress block-reference validator exposes missing refs as plugin-scoped audit conflicts');
    assert_same($audit['conflicts'][0]['semantic_scope'] ?? null, 'wordpress', 'WordPress block-reference audit exposes semantic scope');
    $semantic_scope_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'semantic_scope' => 'wordpress',
        'records' => 'conflicts',
    ]);
    assert_same($semantic_scope_audit['filters']['scope'], 'plugin', 'WordPress semantic-scope filter defaults to plugin audit scope');
    assert_same(count($semantic_scope_audit['conflicts']), 4, 'WordPress semantic-scope filter returns built-in WordPress semantic findings');
    $plugin_scope_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'semantic_scope' => 'plugin',
        'records' => 'conflicts',
    ]);
    assert_same(count($plugin_scope_audit['conflicts']), 0, 'WordPress semantic-scope filter excludes ordinary plugin semantic findings');
    ob_start();
    cow_merge_print_audit_text($semantic_scope_audit);
    $semantic_scope_text = ob_get_clean();
    assert_true(str_contains($semantic_scope_text, 'semantic-scope=wordpress'), 'WordPress semantic-scope is visible in text audit output');
    $preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $audit['conflicts']));
    assert_true(str_contains($preview, '"missing_ref":30'), 'WordPress block-reference audit includes the missing reusable block ID');
    assert_true(str_contains($preview, '"missing_ref":32'), 'WordPress block-reference audit includes the missing synced pattern ID');
    assert_true(str_contains($preview, '"missing_ref":35'), 'WordPress block-reference audit includes the missing navigation ID');
    assert_true(str_contains($preview, '"post_id":33'), 'WordPress block-reference audit includes the synced pattern consumer page ID');
    assert_true(str_contains($preview, '"block_name":"core/navigation"'), 'WordPress block-reference audit includes the navigation block name');
    assert_true(str_contains($preview, '"block_name":"core/template-part"'), 'WordPress block-reference audit includes the template-part block name');
    $template_part_payloads = array_values(array_filter(
        array_map(
            fn($conflict) => cow_merge_audit_decode_payload(json_decode((string)($conflict['chosen_payload'] ?? ''), true)),
            $audit['conflicts']
        ),
        fn($payload) => is_array($payload) && (($payload['candidate']['block_name'] ?? null) === 'core/template-part')
    ));
    assert_same($template_part_payloads[0]['candidate']['missing_template_part']['theme'] ?? null, 'forkpress-test', 'WordPress block-reference audit includes the missing template part theme');
    assert_same($template_part_payloads[0]['candidate']['missing_template_part']['slug'] ?? null, 'header', 'WordPress block-reference audit includes the missing template part slug');

    $block_asset_base_root = $tmp . '/block-asset-base';
    $block_asset_source_root = $tmp . '/block-asset-source';
    $block_asset_target_root = $tmp . '/block-asset-target';
    $block_asset_base = $block_asset_base_root . '/wp-content/database/.ht.sqlite';
    $block_asset_source = $block_asset_source_root . '/wp-content/database/.ht.sqlite';
    $block_asset_target = $block_asset_target_root . '/wp-content/database/.ht.sqlite';
    $block_asset_metadata = $tmp . '/.forkpress/cow/merge/wp-block-asset-validator-metadata.sqlite';
    $block_asset_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-block-asset-validator.json';

    mkdir($block_asset_base_root . '/wp-content/database', 0777, true);
    create_wp_active_plugin_db($block_asset_base, 'forkpress-block-fixture/forkpress-block-fixture.php');
    write_wp_block_asset_plugin($block_asset_base_root);
    copy_tree_for_test($block_asset_base_root, $block_asset_source_root);
    copy_tree_for_test($block_asset_base_root, $block_asset_target_root);
    cow_merge_capture_file_base($block_asset_base_root, $block_asset_file_base);
    cow_merge_allocate_autoincrement_bands($block_asset_source, $block_asset_metadata, 'feature-wp-block-asset-source');
    cow_merge_allocate_autoincrement_bands($block_asset_target, $block_asset_metadata, 'main');

    unlink($block_asset_source_root . '/wp-content/plugins/forkpress-block-fixture/src/index.js');

    $block_asset_result = cow_merge_branch_state(
        $block_asset_base,
        $block_asset_source,
        $block_asset_target,
        $block_asset_metadata,
        'feature-wp-block-asset-source',
        'main',
        $block_asset_file_base,
        $block_asset_source_root,
        $block_asset_target_root
    );

    assert_same($block_asset_result['status'], 'completed_with_conflicts', 'built-in WordPress block-asset validator holds missing active-plugin block.json assets for review');
    assert_same((int)($block_asset_result['wordpress_semantic_validator_conflicts'] ?? 0), 3, 'built-in WordPress block-asset validator records missing and unsafe file references');
    assert_true(!is_file($block_asset_target_root . '/wp-content/plugins/forkpress-block-fixture/src/index.js'), 'block-asset validator leaves the source file deletion staged for review');
    assert_true(is_file($block_asset_target_root . '/wp-content/plugins/forkpress-block-fixture/src/style.css'), 'block-asset validator ignores still-present block assets');

    $block_asset_audit = cow_merge_audit_report($block_asset_metadata, (int)$block_asset_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-block-asset-reference',
    ]);
    assert_same(count($block_asset_audit['conflicts']), 3, 'block-asset validator exposes missing and unsafe assets as plugin-scoped audit conflicts');
    assert_same($block_asset_audit['conflicts'][0]['semantic_scope'] ?? null, 'wordpress', 'block-asset validator exposes WordPress semantic scope');
    assert_same($block_asset_audit['conflicts'][0]['plugin'] ?? null, 'forkpress-block-fixture', 'block-asset validator preserves the active plugin as audit scope');
    $missing_asset_conflicts = array_values(array_filter(
        $block_asset_audit['conflicts'],
        fn($conflict) => ($conflict['plugin_files'] ?? null) === [
            'wp-content/plugins/forkpress-block-fixture/src/block.json',
            'wp-content/plugins/forkpress-block-fixture/src/index.js',
        ]
    ));
    assert_same(count($missing_asset_conflicts), 1, 'block-asset validator exposes block.json and missing asset paths');
    $block_asset_payload = cow_merge_audit_decode_payload(json_decode((string)($missing_asset_conflicts[0]['chosen_payload'] ?? ''), true));
    assert_same($block_asset_payload['candidate']['json_path'] ?? null, '/editorScript', 'block-asset audit includes the block.json field path');
    assert_same(
        $block_asset_payload['candidate']['resolved_file'] ?? null,
        'wp-content/plugins/forkpress-block-fixture/src/index.js',
        'block-asset audit includes the resolved missing asset path'
    );
    $unsafe_block_asset_payloads = array_values(array_filter(
        array_map(
            fn($conflict) => cow_merge_audit_decode_payload(json_decode((string)($conflict['chosen_payload'] ?? ''), true)),
            $block_asset_audit['conflicts']
        ),
        fn($payload) => is_array($payload) && (($payload['reason'] ?? null) === 'block.json contains an unsafe file reference')
    ));
    assert_same(count($unsafe_block_asset_payloads), 2, 'block-asset audit classifies URL-like and drive-letter references as unsafe');
    $unsafe_file_references = array_values(array_map(
        fn($payload) => (string)($payload['candidate']['file_reference'] ?? ''),
        $unsafe_block_asset_payloads
    ));
    sort($unsafe_file_references, SORT_STRING);
    assert_same(
        $unsafe_file_references,
        [
            'file:C:/forkpress-block-fixture/render.php',
            'file:https://example.test/remote-block.js',
        ],
        'block-asset audit payload includes unsafe URL-like and drive-letter file references'
    );

    $post_parent_base_root = $tmp . '/post-parent-base';
    $post_parent_source_root = $tmp . '/post-parent-source';
    $post_parent_target_root = $tmp . '/post-parent-target';
    $post_parent_base = $post_parent_base_root . '/wp-content/database/.ht.sqlite';
    $post_parent_source = $post_parent_source_root . '/wp-content/database/.ht.sqlite';
    $post_parent_target = $post_parent_target_root . '/wp-content/database/.ht.sqlite';
    $post_parent_metadata = $tmp . '/.forkpress/cow/merge/wp-post-parent-validator-metadata.sqlite';
    $post_parent_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-post-parent-validator.json';

    mkdir($post_parent_base_root . '/wp-content/database', 0777, true);
    create_wp_post_parent_reference_db($post_parent_base);
    write_test_file($post_parent_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT child.ID, child.post_type, child.post_parent FROM wp_posts child WHERE child.post_parent > 0");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $parent_id = (int)$row['post_parent'];
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $parent_id");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-post-parent-refs',
            'object' => 'post:' . $row['ID'],
            'reason' => 'post parent references a missing post',
            'type' => 'plugin-wp-post-parent-missing-reference',
            'tables' => ['wp_posts'],
            'validator' => 'forkpress-wp-post-parent-refs@1',
            'candidate' => [
                'post_id' => (int)$row['ID'],
                'post_type' => (string)$row['post_type'],
                'field' => 'post_parent',
                'missing_parent_id' => $parent_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($post_parent_base_root, $post_parent_source_root);
    copy_tree_for_test($post_parent_base_root, $post_parent_target_root);
    cow_merge_capture_file_base($post_parent_base_root, $post_parent_file_base);
    cow_merge_allocate_autoincrement_bands($post_parent_source, $post_parent_metadata, 'feature-wp-post-parent-source');
    cow_merge_allocate_autoincrement_bands($post_parent_target, $post_parent_metadata, 'main');

    $db = open_db($post_parent_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 37');
    $db->close();

    $db = open_db($post_parent_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target child page still pointing at deleted parent' WHERE ID = 38");
    $db->exec("UPDATE wp_posts SET post_title = 'Target child attachment still pointing at deleted parent' WHERE ID = 39");
    $db->close();

    $post_parent_result = cow_merge_branch_state(
        $post_parent_base,
        $post_parent_source,
        $post_parent_target,
        $post_parent_metadata,
        'feature-wp-post-parent-source',
        'main',
        $post_parent_file_base,
        $post_parent_source_root,
        $post_parent_target_root
    );

    assert_same($post_parent_result['status'], 'completed_with_conflicts', 'WordPress post-parent validator holds missing parent posts for review');
    assert_same((int)($post_parent_result['plugin_validators'] ?? 0), 1, 'WordPress post-parent validator is discovered from mu-plugins during merge');
    assert_same((int)($post_parent_result['plugin_validator_conflicts'] ?? 0), 2, 'WordPress post-parent validator records missing parent references for child posts and attachments');
    assert_same((int)scalar($post_parent_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 37'), 0, 'WordPress post-parent validator leaves the source parent deletion staged for review');
    assert_same(scalar($post_parent_target, 'SELECT post_title FROM wp_posts WHERE ID = 38'), 'Target child page still pointing at deleted parent', 'WordPress post-parent validator preserves the target child page edit');
    assert_same(scalar($post_parent_target, 'SELECT post_title FROM wp_posts WHERE ID = 39'), 'Target child attachment still pointing at deleted parent', 'WordPress post-parent validator preserves the target child attachment edit');

    $post_parent_audit = cow_merge_audit_report($post_parent_metadata, (int)$post_parent_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-post-parent-missing-reference',
    ]);
    assert_same(count($post_parent_audit['conflicts']), 2, 'WordPress post-parent validator exposes missing parents as plugin-scoped audit conflicts');
    $post_parent_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $post_parent_audit['conflicts']));
    assert_true(str_contains($post_parent_preview, '"missing_parent_id":37'), 'WordPress post-parent audit includes the missing parent ID');
    assert_true(str_contains($post_parent_preview, '"field":"post_parent"'), 'WordPress post-parent audit includes the stale field name');
    assert_true(str_contains($post_parent_preview, '"post_type":"attachment"'), 'WordPress post-parent audit includes attachment children');

    $duplicate_page_base_root = $tmp . '/duplicate-page-route-base';
    $duplicate_page_source_root = $tmp . '/duplicate-page-route-source';
    $duplicate_page_target_root = $tmp . '/duplicate-page-route-target';
    $duplicate_page_base = $duplicate_page_base_root . '/wp-content/database/.ht.sqlite';
    $duplicate_page_source = $duplicate_page_source_root . '/wp-content/database/.ht.sqlite';
    $duplicate_page_target = $duplicate_page_target_root . '/wp-content/database/.ht.sqlite';
    $duplicate_page_metadata = $tmp . '/.forkpress/cow/merge/wp-duplicate-page-route-metadata.sqlite';
    $duplicate_page_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-duplicate-page-route.json';

    mkdir($duplicate_page_base_root . '/wp-content/database', 0777, true);
    create_wp_duplicate_page_route_db($duplicate_page_base);
    copy_tree_for_test($duplicate_page_base_root, $duplicate_page_source_root);
    copy_tree_for_test($duplicate_page_base_root, $duplicate_page_target_root);
    cow_merge_capture_file_base($duplicate_page_base_root, $duplicate_page_file_base);
    cow_merge_allocate_autoincrement_bands($duplicate_page_source, $duplicate_page_metadata, 'feature-wp-duplicate-page-route-source');
    cow_merge_allocate_autoincrement_bands($duplicate_page_target, $duplicate_page_metadata, 'main');

    $db = open_db($duplicate_page_source);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name, post_parent) VALUES
        ('Source route page', '<!-- wp:paragraph --><p>Source route page</p><!-- /wp:paragraph -->', 'publish', 'page', 'shared-route', 80)");
    $db->close();

    $db = open_db($duplicate_page_target);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name, post_parent) VALUES
        ('Target route page', '<!-- wp:paragraph --><p>Target route page</p><!-- /wp:paragraph -->', 'publish', 'page', 'shared-route', 80)");
    $db->close();

    $duplicate_page_result = cow_merge_branch_state(
        $duplicate_page_base,
        $duplicate_page_source,
        $duplicate_page_target,
        $duplicate_page_metadata,
        'feature-wp-duplicate-page-route-source',
        'main',
        $duplicate_page_file_base,
        $duplicate_page_source_root,
        $duplicate_page_target_root
    );

    assert_same($duplicate_page_result['status'], 'completed_with_conflicts', 'built-in WordPress page-route validator holds duplicate published child-page slugs for review');
    assert_same((int)($duplicate_page_result['wordpress_semantic_validator_conflicts'] ?? 0), 1, 'built-in WordPress page-route validator records the duplicate route identity');
    assert_same((int)($duplicate_page_result['plugin_validator_conflicts'] ?? 0), 1, 'built-in WordPress page-route validator exposes duplicate routes through plugin-scoped audit conflicts');
    assert_same((int)scalar($duplicate_page_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page' AND post_parent = 80 AND post_name = 'shared-route'"), 2, 'built-in WordPress page-route validator keeps both duplicate pages visible for review');

    $duplicate_page_audit = cow_merge_audit_report($duplicate_page_metadata, (int)$duplicate_page_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-duplicate-page-route',
    ]);
    assert_same(count($duplicate_page_audit['conflicts']), 1, 'built-in WordPress page-route validator exposes duplicate routes as plugin-scoped audit conflicts');
    $duplicate_page_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $duplicate_page_audit['conflicts']));
    assert_true(str_contains($duplicate_page_preview, '"post_parent":80'), 'WordPress duplicate page-route audit includes the parent page ID');
    assert_true(str_contains($duplicate_page_preview, '"post_name":"shared-route"'), 'WordPress duplicate page-route audit includes the duplicated slug');
    $duplicate_page_payload = cow_merge_audit_decode_payload(json_decode((string)($duplicate_page_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
    assert_same($duplicate_page_payload['semantic_scope'] ?? null, 'wordpress', 'built-in WordPress page-route validator is tagged as WordPress semantic scope');
    $duplicate_page_titles = array_column($duplicate_page_payload['candidate']['posts'] ?? [], 'post_title');
    sort($duplicate_page_titles, SORT_STRING);
    assert_same($duplicate_page_titles, ['Source route page', 'Target route page'], 'WordPress duplicate page-route audit payload includes both duplicate page titles');

    $duplicate_page_cli_base_root = $tmp . '/duplicate-page-route-cli-base';
    $duplicate_page_cli_source_root = $tmp . '/duplicate-page-route-cli-source';
    $duplicate_page_cli_target_root = $tmp . '/duplicate-page-route-cli-target';
    $duplicate_page_cli_base = $duplicate_page_cli_base_root . '/wp-content/database/.ht.sqlite';
    $duplicate_page_cli_source = $duplicate_page_cli_source_root . '/wp-content/database/.ht.sqlite';
    $duplicate_page_cli_target = $duplicate_page_cli_target_root . '/wp-content/database/.ht.sqlite';
    $duplicate_page_cli_metadata = $tmp . '/.forkpress/cow/merge/wp-duplicate-page-route-cli-metadata.sqlite';
    $duplicate_page_cli_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-duplicate-page-route-cli.json';

    mkdir($duplicate_page_cli_base_root . '/wp-content/database', 0777, true);
    create_wp_duplicate_page_route_db($duplicate_page_cli_base);
    copy_tree_for_test($duplicate_page_cli_base_root, $duplicate_page_cli_source_root);
    copy_tree_for_test($duplicate_page_cli_base_root, $duplicate_page_cli_target_root);
    cow_merge_capture_file_base($duplicate_page_cli_base_root, $duplicate_page_cli_file_base);
    cow_merge_allocate_autoincrement_bands($duplicate_page_cli_source, $duplicate_page_cli_metadata, 'feature-wp-duplicate-page-route-cli-source');
    cow_merge_allocate_autoincrement_bands($duplicate_page_cli_target, $duplicate_page_cli_metadata, 'main');

    $db = open_db($duplicate_page_cli_source);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name, post_parent) VALUES
        ('Source CLI route page', '<!-- wp:paragraph --><p>Source CLI route page</p><!-- /wp:paragraph -->', 'publish', 'page', 'shared-cli-route', 80)");
    $db->close();

    $db = open_db($duplicate_page_cli_target);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name, post_parent) VALUES
        ('Target CLI route page', '<!-- wp:paragraph --><p>Target CLI route page</p><!-- /wp:paragraph -->', 'publish', 'page', 'shared-cli-route', 80)");
    $db->close();

    $duplicate_page_cli_result = run_merge_cli([
        'merge',
        '--base-db', $duplicate_page_cli_base,
        '--source-db', $duplicate_page_cli_source,
        '--target-db', $duplicate_page_cli_target,
        '--metadata-db', $duplicate_page_cli_metadata,
        '--source', 'feature-wp-duplicate-page-route-cli-source',
        '--target', 'main',
        '--base-files', $duplicate_page_cli_file_base,
        '--source-root', $duplicate_page_cli_source_root,
        '--target-root', $duplicate_page_cli_target_root,
    ]);

    assert_same($duplicate_page_cli_result['status'], 0, 'built-in WordPress page-route validator CLI merge exits successfully with review conflicts');
    assert_true(str_contains($duplicate_page_cli_result['output'], 'status:    completed_with_conflicts'), 'built-in WordPress page-route validator CLI reports conflicted merge status');
    assert_true(str_contains($duplicate_page_cli_result['output'], 'wordpress: semantic_conflicts=1'), 'built-in WordPress page-route validator CLI reports WordPress semantic conflicts');
    assert_true(str_contains($duplicate_page_cli_result['output'], 'plugins:   validators=0 conflicts=1'), 'built-in WordPress page-route validator CLI reports the audit conflict channel even without plugin validators');

    $existing_duplicate_page_base_root = $tmp . '/existing-duplicate-page-route-base';
    $existing_duplicate_page_source_root = $tmp . '/existing-duplicate-page-route-source';
    $existing_duplicate_page_target_root = $tmp . '/existing-duplicate-page-route-target';
    $existing_duplicate_page_base = $existing_duplicate_page_base_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_page_source = $existing_duplicate_page_source_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_page_target = $existing_duplicate_page_target_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_page_metadata = $tmp . '/.forkpress/cow/merge/wp-existing-duplicate-page-route-metadata.sqlite';
    $existing_duplicate_page_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-existing-duplicate-page-route.json';

    mkdir($existing_duplicate_page_base_root . '/wp-content/database', 0777, true);
    create_wp_duplicate_page_route_db($existing_duplicate_page_base);
    copy_tree_for_test($existing_duplicate_page_base_root, $existing_duplicate_page_source_root);
    copy_tree_for_test($existing_duplicate_page_base_root, $existing_duplicate_page_target_root);
    cow_merge_capture_file_base($existing_duplicate_page_base_root, $existing_duplicate_page_file_base);
    cow_merge_allocate_autoincrement_bands($existing_duplicate_page_source, $existing_duplicate_page_metadata, 'feature-existing-wp-duplicate-page-route-source');
    cow_merge_allocate_autoincrement_bands($existing_duplicate_page_target, $existing_duplicate_page_metadata, 'main');

    $db = open_db($existing_duplicate_page_source);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name, post_parent) VALUES
        ('Source unrelated page', '<!-- wp:paragraph --><p>Source unrelated page</p><!-- /wp:paragraph -->', 'publish', 'page', 'source-unrelated-page', 80)");
    $db->close();

    $db = open_db($existing_duplicate_page_target);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name, post_parent) VALUES
        ('Existing duplicate route page A', '<!-- wp:paragraph --><p>Existing duplicate A</p><!-- /wp:paragraph -->', 'publish', 'page', 'preexisting-shared-route', 80),
        ('Existing duplicate route page B', '<!-- wp:paragraph --><p>Existing duplicate B</p><!-- /wp:paragraph -->', 'publish', 'page', 'preexisting-shared-route', 80)");
    $db->close();

    $existing_duplicate_page_result = cow_merge_branch_state(
        $existing_duplicate_page_base,
        $existing_duplicate_page_source,
        $existing_duplicate_page_target,
        $existing_duplicate_page_metadata,
        'feature-existing-wp-duplicate-page-route-source',
        'main',
        $existing_duplicate_page_file_base,
        $existing_duplicate_page_source_root,
        $existing_duplicate_page_target_root
    );

    assert_same($existing_duplicate_page_result['status'], 'completed', 'built-in WordPress page-route validator ignores duplicate route state that predates the merge');
    assert_same((int)($existing_duplicate_page_result['wordpress_semantic_validator_conflicts'] ?? 0), 0, 'built-in WordPress page-route validator only records newly introduced or worsened duplicate routes');
    assert_same((int)scalar($existing_duplicate_page_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page' AND post_parent = 80 AND post_name = 'preexisting-shared-route'"), 2, 'built-in WordPress page-route validator preserves preexisting duplicate pages during unrelated merges');
    assert_same((int)scalar($existing_duplicate_page_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page' AND post_parent = 80 AND post_name = 'source-unrelated-page'"), 1, 'built-in WordPress page-route validator still lets unrelated source pages merge');

    $duplicate_term_base_root = $tmp . '/duplicate-term-route-base';
    $duplicate_term_source_root = $tmp . '/duplicate-term-route-source';
    $duplicate_term_target_root = $tmp . '/duplicate-term-route-target';
    $duplicate_term_base = $duplicate_term_base_root . '/wp-content/database/.ht.sqlite';
    $duplicate_term_source = $duplicate_term_source_root . '/wp-content/database/.ht.sqlite';
    $duplicate_term_target = $duplicate_term_target_root . '/wp-content/database/.ht.sqlite';
    $duplicate_term_metadata = $tmp . '/.forkpress/cow/merge/wp-duplicate-term-route-metadata.sqlite';
    $duplicate_term_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-duplicate-term-route.json';

    mkdir($duplicate_term_base_root . '/wp-content/database', 0777, true);
    create_wp_duplicate_term_route_db($duplicate_term_base);
    copy_tree_for_test($duplicate_term_base_root, $duplicate_term_source_root);
    copy_tree_for_test($duplicate_term_base_root, $duplicate_term_target_root);
    cow_merge_capture_file_base($duplicate_term_base_root, $duplicate_term_file_base);
    cow_merge_allocate_autoincrement_bands($duplicate_term_source, $duplicate_term_metadata, 'feature-wp-duplicate-term-route-source');
    cow_merge_allocate_autoincrement_bands($duplicate_term_target, $duplicate_term_metadata, 'main');

    $db = open_db($duplicate_term_source);
    insert_wp_test_term($db, 'Source route category', 'shared-term-route', 'category', 70);
    $db->close();

    $db = open_db($duplicate_term_target);
    insert_wp_test_term($db, 'Target route category', 'shared-term-route', 'category', 70);
    $db->close();

    $duplicate_term_result = cow_merge_branch_state(
        $duplicate_term_base,
        $duplicate_term_source,
        $duplicate_term_target,
        $duplicate_term_metadata,
        'feature-wp-duplicate-term-route-source',
        'main',
        $duplicate_term_file_base,
        $duplicate_term_source_root,
        $duplicate_term_target_root
    );

    assert_same($duplicate_term_result['status'], 'completed_with_conflicts', 'built-in WordPress term-route validator holds duplicate taxonomy child slugs for review');
    assert_same((int)($duplicate_term_result['wordpress_semantic_validator_conflicts'] ?? 0), 1, 'built-in WordPress term-route validator records the duplicate taxonomy route identity');
    assert_same((int)($duplicate_term_result['plugin_validator_conflicts'] ?? 0), 1, 'built-in WordPress term-route validator exposes duplicate taxonomy routes through plugin-scoped audit conflicts');
    assert_same((int)scalar($duplicate_term_target, "SELECT COUNT(*) FROM wp_term_taxonomy tt JOIN wp_terms t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'category' AND tt.parent = 70 AND t.slug = 'shared-term-route'"), 2, 'built-in WordPress term-route validator keeps both duplicate terms visible for review');

    $duplicate_term_audit = cow_merge_audit_report($duplicate_term_metadata, (int)$duplicate_term_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-duplicate-term-route',
        'semantic_scope' => 'wordpress',
    ]);
    assert_same(count($duplicate_term_audit['conflicts']), 1, 'built-in WordPress term-route validator exposes duplicate routes as WordPress-scoped audit conflicts');
    $duplicate_term_payload = cow_merge_audit_decode_payload(json_decode((string)($duplicate_term_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
    assert_same($duplicate_term_payload['logical_identity']['taxonomy'] ?? null, 'category', 'WordPress duplicate term-route audit records the taxonomy');
    assert_same($duplicate_term_payload['logical_identity']['parent'] ?? null, 70, 'WordPress duplicate term-route audit records the parent term taxonomy route');
    assert_same($duplicate_term_payload['logical_identity']['slug'] ?? null, 'shared-term-route', 'WordPress duplicate term-route audit records the duplicated slug');
    $duplicate_term_names = array_column($duplicate_term_payload['candidate']['terms'] ?? [], 'name');
    sort($duplicate_term_names, SORT_STRING);
    assert_same($duplicate_term_names, ['Source route category', 'Target route category'], 'WordPress duplicate term-route audit payload includes both duplicate term names');

    $existing_duplicate_term_base_root = $tmp . '/existing-duplicate-term-route-base';
    $existing_duplicate_term_source_root = $tmp . '/existing-duplicate-term-route-source';
    $existing_duplicate_term_target_root = $tmp . '/existing-duplicate-term-route-target';
    $existing_duplicate_term_base = $existing_duplicate_term_base_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_term_source = $existing_duplicate_term_source_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_term_target = $existing_duplicate_term_target_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_term_metadata = $tmp . '/.forkpress/cow/merge/wp-existing-duplicate-term-route-metadata.sqlite';
    $existing_duplicate_term_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-existing-duplicate-term-route.json';

    mkdir($existing_duplicate_term_base_root . '/wp-content/database', 0777, true);
    create_wp_duplicate_term_route_db($existing_duplicate_term_base);
    copy_tree_for_test($existing_duplicate_term_base_root, $existing_duplicate_term_source_root);
    copy_tree_for_test($existing_duplicate_term_base_root, $existing_duplicate_term_target_root);
    cow_merge_capture_file_base($existing_duplicate_term_base_root, $existing_duplicate_term_file_base);
    cow_merge_allocate_autoincrement_bands($existing_duplicate_term_source, $existing_duplicate_term_metadata, 'feature-existing-wp-duplicate-term-route-source');
    cow_merge_allocate_autoincrement_bands($existing_duplicate_term_target, $existing_duplicate_term_metadata, 'main');

    $db = open_db($existing_duplicate_term_source);
    insert_wp_test_term($db, 'Source unrelated category', 'source-unrelated-category', 'category', 70);
    $db->close();

    $db = open_db($existing_duplicate_term_target);
    insert_wp_test_term($db, 'Existing duplicate category A', 'preexisting-shared-term-route', 'category', 70);
    insert_wp_test_term($db, 'Existing duplicate category B', 'preexisting-shared-term-route', 'category', 70);
    $db->close();

    $existing_duplicate_term_result = cow_merge_branch_state(
        $existing_duplicate_term_base,
        $existing_duplicate_term_source,
        $existing_duplicate_term_target,
        $existing_duplicate_term_metadata,
        'feature-existing-wp-duplicate-term-route-source',
        'main',
        $existing_duplicate_term_file_base,
        $existing_duplicate_term_source_root,
        $existing_duplicate_term_target_root
    );

    assert_same($existing_duplicate_term_result['status'], 'completed', 'built-in WordPress term-route validator ignores duplicate taxonomy routes that predate the merge');
    assert_same((int)($existing_duplicate_term_result['wordpress_semantic_validator_conflicts'] ?? 0), 0, 'built-in WordPress term-route validator only records newly introduced or worsened duplicate taxonomy routes');
    assert_same((int)scalar($existing_duplicate_term_target, "SELECT COUNT(*) FROM wp_term_taxonomy tt JOIN wp_terms t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'category' AND tt.parent = 70 AND t.slug = 'preexisting-shared-term-route'"), 2, 'built-in WordPress term-route validator preserves preexisting duplicate terms during unrelated merges');
    assert_same((int)scalar($existing_duplicate_term_target, "SELECT COUNT(*) FROM wp_term_taxonomy tt JOIN wp_terms t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'category' AND tt.parent = 70 AND t.slug = 'source-unrelated-category'"), 1, 'built-in WordPress term-route validator still lets unrelated source terms merge');

    $duplicate_user_base_root = $tmp . '/duplicate-user-login-base';
    $duplicate_user_source_root = $tmp . '/duplicate-user-login-source';
    $duplicate_user_target_root = $tmp . '/duplicate-user-login-target';
    $duplicate_user_base = $duplicate_user_base_root . '/wp-content/database/.ht.sqlite';
    $duplicate_user_source = $duplicate_user_source_root . '/wp-content/database/.ht.sqlite';
    $duplicate_user_target = $duplicate_user_target_root . '/wp-content/database/.ht.sqlite';
    $duplicate_user_metadata = $tmp . '/.forkpress/cow/merge/wp-duplicate-user-login-metadata.sqlite';
    $duplicate_user_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-duplicate-user-login.json';

    mkdir($duplicate_user_base_root . '/wp-content/database', 0777, true);
    create_wp_duplicate_user_login_db($duplicate_user_base);
    copy_tree_for_test($duplicate_user_base_root, $duplicate_user_source_root);
    copy_tree_for_test($duplicate_user_base_root, $duplicate_user_target_root);
    cow_merge_capture_file_base($duplicate_user_base_root, $duplicate_user_file_base);
    cow_merge_allocate_autoincrement_bands($duplicate_user_source, $duplicate_user_metadata, 'feature-wp-duplicate-user-login-source');
    cow_merge_allocate_autoincrement_bands($duplicate_user_target, $duplicate_user_metadata, 'main');

    $db = open_db($duplicate_user_source);
    insert_wp_test_user($db, 'shared_editor', 'source-editor@example.test', 'Source Editor');
    $db->close();

    $db = open_db($duplicate_user_target);
    insert_wp_test_user($db, 'Shared_Editor', 'target-editor@example.test', 'Target Editor');
    $db->close();

    $duplicate_user_result = cow_merge_branch_state(
        $duplicate_user_base,
        $duplicate_user_source,
        $duplicate_user_target,
        $duplicate_user_metadata,
        'feature-wp-duplicate-user-login-source',
        'main',
        $duplicate_user_file_base,
        $duplicate_user_source_root,
        $duplicate_user_target_root
    );

    assert_same($duplicate_user_result['status'], 'completed_with_conflicts', 'built-in WordPress user-login validator holds duplicate login identities for review');
    assert_same((int)($duplicate_user_result['wordpress_semantic_validator_conflicts'] ?? 0), 1, 'built-in WordPress user-login validator records the duplicate login identity');
    assert_same((int)($duplicate_user_result['plugin_validator_conflicts'] ?? 0), 1, 'built-in WordPress user-login validator exposes duplicate logins through plugin-scoped audit conflicts');
    assert_same((int)scalar($duplicate_user_target, "SELECT COUNT(*) FROM wp_users WHERE LOWER(user_login) = 'shared_editor'"), 2, 'built-in WordPress user-login validator keeps both duplicate users visible for review');

    $duplicate_user_audit = cow_merge_audit_report($duplicate_user_metadata, (int)$duplicate_user_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-duplicate-user-login',
        'semantic_scope' => 'wordpress',
    ]);
    assert_same(count($duplicate_user_audit['conflicts']), 1, 'built-in WordPress user-login validator exposes duplicate logins as WordPress-scoped audit conflicts');
    $duplicate_user_payload = cow_merge_audit_decode_payload(json_decode((string)($duplicate_user_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
    assert_same($duplicate_user_payload['logical_identity']['user_login_key'] ?? null, 'shared_editor', 'WordPress duplicate user-login audit records the normalized login identity');
    $duplicate_user_logins = array_column($duplicate_user_payload['candidate']['users'] ?? [], 'user_login');
    sort($duplicate_user_logins, SORT_STRING);
    assert_same($duplicate_user_logins, ['Shared_Editor', 'shared_editor'], 'WordPress duplicate user-login audit payload includes both duplicate user logins');
    $duplicate_user_names = array_column($duplicate_user_payload['candidate']['users'] ?? [], 'display_name');
    sort($duplicate_user_names, SORT_STRING);
    assert_same($duplicate_user_names, ['Source Editor', 'Target Editor'], 'WordPress duplicate user-login audit payload includes both duplicate display names');

    $existing_duplicate_user_base_root = $tmp . '/existing-duplicate-user-login-base';
    $existing_duplicate_user_source_root = $tmp . '/existing-duplicate-user-login-source';
    $existing_duplicate_user_target_root = $tmp . '/existing-duplicate-user-login-target';
    $existing_duplicate_user_base = $existing_duplicate_user_base_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_user_source = $existing_duplicate_user_source_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_user_target = $existing_duplicate_user_target_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_user_metadata = $tmp . '/.forkpress/cow/merge/wp-existing-duplicate-user-login-metadata.sqlite';
    $existing_duplicate_user_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-existing-duplicate-user-login.json';

    mkdir($existing_duplicate_user_base_root . '/wp-content/database', 0777, true);
    create_wp_duplicate_user_login_db($existing_duplicate_user_base);
    copy_tree_for_test($existing_duplicate_user_base_root, $existing_duplicate_user_source_root);
    copy_tree_for_test($existing_duplicate_user_base_root, $existing_duplicate_user_target_root);
    cow_merge_capture_file_base($existing_duplicate_user_base_root, $existing_duplicate_user_file_base);
    cow_merge_allocate_autoincrement_bands($existing_duplicate_user_source, $existing_duplicate_user_metadata, 'feature-existing-wp-duplicate-user-login-source');
    cow_merge_allocate_autoincrement_bands($existing_duplicate_user_target, $existing_duplicate_user_metadata, 'main');

    $db = open_db($existing_duplicate_user_source);
    insert_wp_test_user($db, 'source_unrelated_editor', 'source-unrelated-editor@example.test', 'Source Unrelated Editor');
    $db->close();

    $db = open_db($existing_duplicate_user_target);
    insert_wp_test_user($db, 'preexisting_editor', 'preexisting-a@example.test', 'Preexisting Editor A');
    insert_wp_test_user($db, 'Preexisting_Editor', 'preexisting-b@example.test', 'Preexisting Editor B');
    $db->close();

    $existing_duplicate_user_result = cow_merge_branch_state(
        $existing_duplicate_user_base,
        $existing_duplicate_user_source,
        $existing_duplicate_user_target,
        $existing_duplicate_user_metadata,
        'feature-existing-wp-duplicate-user-login-source',
        'main',
        $existing_duplicate_user_file_base,
        $existing_duplicate_user_source_root,
        $existing_duplicate_user_target_root
    );

    assert_same($existing_duplicate_user_result['status'], 'completed', 'built-in WordPress user-login validator ignores duplicate login identities that predate the merge');
    assert_same((int)($existing_duplicate_user_result['wordpress_semantic_validator_conflicts'] ?? 0), 0, 'built-in WordPress user-login validator only records newly introduced or worsened duplicate login identities');
    assert_same((int)scalar($existing_duplicate_user_target, "SELECT COUNT(*) FROM wp_users WHERE LOWER(user_login) = 'preexisting_editor'"), 2, 'built-in WordPress user-login validator preserves preexisting duplicate users during unrelated merges');
    assert_same((int)scalar($existing_duplicate_user_target, "SELECT COUNT(*) FROM wp_users WHERE user_login = 'source_unrelated_editor'"), 1, 'built-in WordPress user-login validator still lets unrelated source users merge');

    $duplicate_global_styles_base_root = $tmp . '/duplicate-global-styles-base';
    $duplicate_global_styles_source_root = $tmp . '/duplicate-global-styles-source';
    $duplicate_global_styles_target_root = $tmp . '/duplicate-global-styles-target';
    $duplicate_global_styles_base = $duplicate_global_styles_base_root . '/wp-content/database/.ht.sqlite';
    $duplicate_global_styles_source = $duplicate_global_styles_source_root . '/wp-content/database/.ht.sqlite';
    $duplicate_global_styles_target = $duplicate_global_styles_target_root . '/wp-content/database/.ht.sqlite';
    $duplicate_global_styles_metadata = $tmp . '/.forkpress/cow/merge/wp-duplicate-global-styles-metadata.sqlite';
    $duplicate_global_styles_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-duplicate-global-styles.json';

    mkdir($duplicate_global_styles_base_root . '/wp-content/database', 0777, true);
    create_wp_global_styles_db($duplicate_global_styles_base);
    copy_tree_for_test($duplicate_global_styles_base_root, $duplicate_global_styles_source_root);
    copy_tree_for_test($duplicate_global_styles_base_root, $duplicate_global_styles_target_root);
    cow_merge_capture_file_base($duplicate_global_styles_base_root, $duplicate_global_styles_file_base);
    cow_merge_allocate_autoincrement_bands($duplicate_global_styles_source, $duplicate_global_styles_metadata, 'feature-wp-duplicate-global-styles-source');
    cow_merge_allocate_autoincrement_bands($duplicate_global_styles_target, $duplicate_global_styles_metadata, 'main');

    $db = open_db($duplicate_global_styles_source);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name) VALUES
        ('Source theme styles', '{\"version\":2,\"styles\":{\"color\":{\"text\":\"#111111\"}}}', 'publish', 'wp_global_styles', 'wp-global-styles-forkpress-test')");
    $db->close();

    $db = open_db($duplicate_global_styles_target);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name) VALUES
        ('Target theme styles', '{\"version\":2,\"styles\":{\"color\":{\"text\":\"#222222\"}}}', 'publish', 'wp_global_styles', 'wp-global-styles-forkpress-test')");
    $db->close();

    $duplicate_global_styles_result = cow_merge_branch_state(
        $duplicate_global_styles_base,
        $duplicate_global_styles_source,
        $duplicate_global_styles_target,
        $duplicate_global_styles_metadata,
        'feature-wp-duplicate-global-styles-source',
        'main',
        $duplicate_global_styles_file_base,
        $duplicate_global_styles_source_root,
        $duplicate_global_styles_target_root
    );

    assert_same($duplicate_global_styles_result['status'], 'completed_with_conflicts', 'built-in WordPress global-styles validator holds duplicate published style keys for review');
    assert_same((int)($duplicate_global_styles_result['wordpress_semantic_validator_conflicts'] ?? 0), 1, 'built-in WordPress global-styles validator records the duplicate style key');
    assert_same((int)($duplicate_global_styles_result['plugin_validator_conflicts'] ?? 0), 1, 'built-in WordPress global-styles validator exposes duplicate style keys through plugin-scoped audit conflicts');
    assert_same((int)scalar($duplicate_global_styles_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_global_styles' AND post_status = 'publish' AND post_name = 'wp-global-styles-forkpress-test'"), 2, 'built-in WordPress global-styles validator keeps both duplicate style rows visible for review');

    $duplicate_global_styles_audit = cow_merge_audit_report($duplicate_global_styles_metadata, (int)$duplicate_global_styles_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-duplicate-global-styles',
        'semantic_scope' => 'wordpress',
    ]);
    assert_same(count($duplicate_global_styles_audit['conflicts']), 1, 'built-in WordPress global-styles validator exposes duplicate style keys as WordPress-scoped audit conflicts');
    $duplicate_global_styles_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $duplicate_global_styles_audit['conflicts']));
    assert_true(str_contains($duplicate_global_styles_preview, '"post_name":"wp-global-styles-forkpress-test"'), 'WordPress duplicate global-styles audit includes the duplicated style key');
    assert_true(str_contains($duplicate_global_styles_preview, '"duplicate_count":2'), 'WordPress duplicate global-styles audit includes the duplicate count');
    $duplicate_global_styles_payload = cow_merge_audit_decode_payload(json_decode((string)($duplicate_global_styles_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
    $duplicate_global_styles_titles = array_column($duplicate_global_styles_payload['candidate']['posts'] ?? [], 'post_title');
    sort($duplicate_global_styles_titles, SORT_STRING);
    assert_same($duplicate_global_styles_titles, ['Source theme styles', 'Target theme styles'], 'WordPress duplicate global-styles audit payload includes both duplicate style row titles');

    $existing_duplicate_global_styles_base_root = $tmp . '/existing-duplicate-global-styles-base';
    $existing_duplicate_global_styles_source_root = $tmp . '/existing-duplicate-global-styles-source';
    $existing_duplicate_global_styles_target_root = $tmp . '/existing-duplicate-global-styles-target';
    $existing_duplicate_global_styles_base = $existing_duplicate_global_styles_base_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_global_styles_source = $existing_duplicate_global_styles_source_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_global_styles_target = $existing_duplicate_global_styles_target_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_global_styles_metadata = $tmp . '/.forkpress/cow/merge/wp-existing-duplicate-global-styles-metadata.sqlite';
    $existing_duplicate_global_styles_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-existing-duplicate-global-styles.json';

    mkdir($existing_duplicate_global_styles_base_root . '/wp-content/database', 0777, true);
    create_wp_global_styles_db($existing_duplicate_global_styles_base);
    copy_tree_for_test($existing_duplicate_global_styles_base_root, $existing_duplicate_global_styles_source_root);
    copy_tree_for_test($existing_duplicate_global_styles_base_root, $existing_duplicate_global_styles_target_root);
    cow_merge_capture_file_base($existing_duplicate_global_styles_base_root, $existing_duplicate_global_styles_file_base);
    cow_merge_allocate_autoincrement_bands($existing_duplicate_global_styles_source, $existing_duplicate_global_styles_metadata, 'feature-existing-wp-duplicate-global-styles-source');
    cow_merge_allocate_autoincrement_bands($existing_duplicate_global_styles_target, $existing_duplicate_global_styles_metadata, 'main');

    $db = open_db($existing_duplicate_global_styles_source);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name) VALUES
        ('Source unrelated style variation', '{\"version\":2,\"styles\":{\"spacing\":{\"padding\":\"1rem\"}}}', 'publish', 'wp_global_styles', 'wp-global-styles-source-variation')");
    $db->close();

    $db = open_db($existing_duplicate_global_styles_target);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name) VALUES
        ('Existing duplicate theme styles A', '{\"version\":2,\"styles\":{\"color\":{\"text\":\"#333333\"}}}', 'publish', 'wp_global_styles', 'wp-global-styles-preexisting'),
        ('Existing duplicate theme styles B', '{\"version\":2,\"styles\":{\"color\":{\"text\":\"#444444\"}}}', 'publish', 'wp_global_styles', 'wp-global-styles-preexisting')");
    $db->close();

    $existing_duplicate_global_styles_result = cow_merge_branch_state(
        $existing_duplicate_global_styles_base,
        $existing_duplicate_global_styles_source,
        $existing_duplicate_global_styles_target,
        $existing_duplicate_global_styles_metadata,
        'feature-existing-wp-duplicate-global-styles-source',
        'main',
        $existing_duplicate_global_styles_file_base,
        $existing_duplicate_global_styles_source_root,
        $existing_duplicate_global_styles_target_root
    );

    assert_same($existing_duplicate_global_styles_result['status'], 'completed', 'built-in WordPress global-styles validator ignores duplicate style keys that predate the merge');
    assert_same((int)($existing_duplicate_global_styles_result['wordpress_semantic_validator_conflicts'] ?? 0), 0, 'built-in WordPress global-styles validator only records newly introduced or worsened duplicate style keys');
    assert_same((int)scalar($existing_duplicate_global_styles_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_global_styles' AND post_status = 'publish' AND post_name = 'wp-global-styles-preexisting'"), 2, 'built-in WordPress global-styles validator preserves preexisting duplicate style rows during unrelated merges');
    assert_same((int)scalar($existing_duplicate_global_styles_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_global_styles' AND post_status = 'publish' AND post_name = 'wp-global-styles-source-variation'"), 1, 'built-in WordPress global-styles validator still lets unrelated source style rows merge');

    $duplicate_site_editor_base_root = $tmp . '/duplicate-site-editor-base';
    $duplicate_site_editor_source_root = $tmp . '/duplicate-site-editor-source';
    $duplicate_site_editor_target_root = $tmp . '/duplicate-site-editor-target';
    $duplicate_site_editor_base = $duplicate_site_editor_base_root . '/wp-content/database/.ht.sqlite';
    $duplicate_site_editor_source = $duplicate_site_editor_source_root . '/wp-content/database/.ht.sqlite';
    $duplicate_site_editor_target = $duplicate_site_editor_target_root . '/wp-content/database/.ht.sqlite';
    $duplicate_site_editor_metadata = $tmp . '/.forkpress/cow/merge/wp-duplicate-site-editor-metadata.sqlite';
    $duplicate_site_editor_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-duplicate-site-editor.json';

    mkdir($duplicate_site_editor_base_root . '/wp-content/database', 0777, true);
    create_wp_site_editor_objects_db($duplicate_site_editor_base);
    copy_tree_for_test($duplicate_site_editor_base_root, $duplicate_site_editor_source_root);
    copy_tree_for_test($duplicate_site_editor_base_root, $duplicate_site_editor_target_root);
    cow_merge_capture_file_base($duplicate_site_editor_base_root, $duplicate_site_editor_file_base);
    cow_merge_allocate_autoincrement_bands($duplicate_site_editor_source, $duplicate_site_editor_metadata, 'feature-wp-duplicate-site-editor-source');
    cow_merge_allocate_autoincrement_bands($duplicate_site_editor_target, $duplicate_site_editor_metadata, 'main');

    $db = open_db($duplicate_site_editor_source);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name) VALUES
        ('Source single template', '<!-- wp:paragraph --><p>Source single template</p><!-- /wp:paragraph -->', 'publish', 'wp_template', 'forkpress-test//single'),
        ('Source header template part', '<!-- wp:paragraph --><p>Source header</p><!-- /wp:paragraph -->', 'publish', 'wp_template_part', 'forkpress-test//header')");
    $db->close();

    $db = open_db($duplicate_site_editor_target);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name) VALUES
        ('Target single template', '<!-- wp:paragraph --><p>Target single template</p><!-- /wp:paragraph -->', 'publish', 'wp_template', 'forkpress-test//single'),
        ('Target header template part', '<!-- wp:paragraph --><p>Target header</p><!-- /wp:paragraph -->', 'publish', 'wp_template_part', 'forkpress-test//header')");
    $db->close();

    $duplicate_site_editor_result = cow_merge_branch_state(
        $duplicate_site_editor_base,
        $duplicate_site_editor_source,
        $duplicate_site_editor_target,
        $duplicate_site_editor_metadata,
        'feature-wp-duplicate-site-editor-source',
        'main',
        $duplicate_site_editor_file_base,
        $duplicate_site_editor_source_root,
        $duplicate_site_editor_target_root
    );

    assert_same($duplicate_site_editor_result['status'], 'completed_with_conflicts', 'built-in WordPress Site Editor validator holds duplicate template and template-part keys for review');
    assert_same((int)($duplicate_site_editor_result['wordpress_semantic_validator_conflicts'] ?? 0), 2, 'built-in WordPress Site Editor validator records duplicate template and template-part keys');
    assert_same((int)($duplicate_site_editor_result['plugin_validator_conflicts'] ?? 0), 2, 'built-in WordPress Site Editor validator exposes duplicate object keys through plugin-scoped audit conflicts');
    assert_same((int)scalar($duplicate_site_editor_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_template' AND post_status = 'publish' AND post_name = 'forkpress-test//single'"), 2, 'built-in WordPress Site Editor validator keeps both duplicate templates visible for review');
    assert_same((int)scalar($duplicate_site_editor_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_template_part' AND post_status = 'publish' AND post_name = 'forkpress-test//header'"), 2, 'built-in WordPress Site Editor validator keeps both duplicate template parts visible for review');

    $duplicate_template_audit = cow_merge_audit_report($duplicate_site_editor_metadata, (int)$duplicate_site_editor_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-duplicate-template-key',
        'semantic_scope' => 'wordpress',
    ]);
    assert_same(count($duplicate_template_audit['conflicts']), 1, 'built-in WordPress Site Editor validator exposes duplicate templates as WordPress-scoped audit conflicts');
    $duplicate_template_payload = cow_merge_audit_decode_payload(json_decode((string)($duplicate_template_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
    assert_same($duplicate_template_payload['logical_identity']['post_type'] ?? null, 'wp_template', 'WordPress duplicate template audit records the template post type');
    assert_same($duplicate_template_payload['logical_identity']['post_name'] ?? null, 'forkpress-test//single', 'WordPress duplicate template audit records the template key');
    $duplicate_template_titles = array_column($duplicate_template_payload['candidate']['posts'] ?? [], 'post_title');
    sort($duplicate_template_titles, SORT_STRING);
    assert_same($duplicate_template_titles, ['Source single template', 'Target single template'], 'WordPress duplicate template audit payload includes both duplicate template titles');

    $duplicate_template_part_audit = cow_merge_audit_report($duplicate_site_editor_metadata, (int)$duplicate_site_editor_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-duplicate-template-part-key',
        'semantic_scope' => 'wordpress',
    ]);
    assert_same(count($duplicate_template_part_audit['conflicts']), 1, 'built-in WordPress Site Editor validator exposes duplicate template parts as WordPress-scoped audit conflicts');
    $duplicate_template_part_payload = cow_merge_audit_decode_payload(json_decode((string)($duplicate_template_part_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
    assert_same($duplicate_template_part_payload['logical_identity']['post_type'] ?? null, 'wp_template_part', 'WordPress duplicate template-part audit records the template-part post type');
    assert_same($duplicate_template_part_payload['logical_identity']['post_name'] ?? null, 'forkpress-test//header', 'WordPress duplicate template-part audit records the template-part key');
    $duplicate_template_part_titles = array_column($duplicate_template_part_payload['candidate']['posts'] ?? [], 'post_title');
    sort($duplicate_template_part_titles, SORT_STRING);
    assert_same($duplicate_template_part_titles, ['Source header template part', 'Target header template part'], 'WordPress duplicate template-part audit payload includes both duplicate template-part titles');

    $existing_duplicate_site_editor_base_root = $tmp . '/existing-duplicate-site-editor-base';
    $existing_duplicate_site_editor_source_root = $tmp . '/existing-duplicate-site-editor-source';
    $existing_duplicate_site_editor_target_root = $tmp . '/existing-duplicate-site-editor-target';
    $existing_duplicate_site_editor_base = $existing_duplicate_site_editor_base_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_site_editor_source = $existing_duplicate_site_editor_source_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_site_editor_target = $existing_duplicate_site_editor_target_root . '/wp-content/database/.ht.sqlite';
    $existing_duplicate_site_editor_metadata = $tmp . '/.forkpress/cow/merge/wp-existing-duplicate-site-editor-metadata.sqlite';
    $existing_duplicate_site_editor_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-existing-duplicate-site-editor.json';

    mkdir($existing_duplicate_site_editor_base_root . '/wp-content/database', 0777, true);
    create_wp_site_editor_objects_db($existing_duplicate_site_editor_base);
    copy_tree_for_test($existing_duplicate_site_editor_base_root, $existing_duplicate_site_editor_source_root);
    copy_tree_for_test($existing_duplicate_site_editor_base_root, $existing_duplicate_site_editor_target_root);
    cow_merge_capture_file_base($existing_duplicate_site_editor_base_root, $existing_duplicate_site_editor_file_base);
    cow_merge_allocate_autoincrement_bands($existing_duplicate_site_editor_source, $existing_duplicate_site_editor_metadata, 'feature-existing-wp-duplicate-site-editor-source');
    cow_merge_allocate_autoincrement_bands($existing_duplicate_site_editor_target, $existing_duplicate_site_editor_metadata, 'main');

    $db = open_db($existing_duplicate_site_editor_source);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name) VALUES
        ('Source unrelated template', '<!-- wp:paragraph --><p>Source archive template</p><!-- /wp:paragraph -->', 'publish', 'wp_template', 'forkpress-test//archive')");
    $db->close();

    $db = open_db($existing_duplicate_site_editor_target);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name) VALUES
        ('Existing duplicate template A', '<!-- wp:paragraph --><p>Existing template A</p><!-- /wp:paragraph -->', 'publish', 'wp_template', 'forkpress-test//preexisting'),
        ('Existing duplicate template B', '<!-- wp:paragraph --><p>Existing template B</p><!-- /wp:paragraph -->', 'publish', 'wp_template', 'forkpress-test//preexisting')");
    $db->close();

    $existing_duplicate_site_editor_result = cow_merge_branch_state(
        $existing_duplicate_site_editor_base,
        $existing_duplicate_site_editor_source,
        $existing_duplicate_site_editor_target,
        $existing_duplicate_site_editor_metadata,
        'feature-existing-wp-duplicate-site-editor-source',
        'main',
        $existing_duplicate_site_editor_file_base,
        $existing_duplicate_site_editor_source_root,
        $existing_duplicate_site_editor_target_root
    );

    assert_same($existing_duplicate_site_editor_result['status'], 'completed', 'built-in WordPress Site Editor validator ignores duplicate template keys that predate the merge');
    assert_same((int)($existing_duplicate_site_editor_result['wordpress_semantic_validator_conflicts'] ?? 0), 0, 'built-in WordPress Site Editor validator only records newly introduced or worsened duplicate object keys');
    assert_same((int)scalar($existing_duplicate_site_editor_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_template' AND post_status = 'publish' AND post_name = 'forkpress-test//preexisting'"), 2, 'built-in WordPress Site Editor validator preserves preexisting duplicate templates during unrelated merges');
    assert_same((int)scalar($existing_duplicate_site_editor_target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_template' AND post_status = 'publish' AND post_name = 'forkpress-test//archive'"), 1, 'built-in WordPress Site Editor validator still lets unrelated source templates merge');

    $post_author_base_root = $tmp . '/post-author-base';
    $post_author_source_root = $tmp . '/post-author-source';
    $post_author_target_root = $tmp . '/post-author-target';
    $post_author_base = $post_author_base_root . '/wp-content/database/.ht.sqlite';
    $post_author_source = $post_author_source_root . '/wp-content/database/.ht.sqlite';
    $post_author_target = $post_author_target_root . '/wp-content/database/.ht.sqlite';
    $post_author_metadata = $tmp . '/.forkpress/cow/merge/wp-post-author-validator-metadata.sqlite';
    $post_author_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-post-author-validator.json';

    mkdir($post_author_base_root . '/wp-content/database', 0777, true);
    create_wp_post_author_reference_db($post_author_base);
    write_test_file($post_author_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT ID, post_type, post_author FROM wp_posts WHERE post_author > 0");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $author_id = (int)$row['post_author'];
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_users WHERE ID = $author_id");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-post-author-refs',
            'object' => 'post:' . $row['ID'],
            'reason' => 'post author references a missing user',
            'type' => 'plugin-wp-post-author-missing-user',
            'tables' => ['wp_posts', 'wp_users'],
            'validator' => 'forkpress-wp-post-author-refs@1',
            'candidate' => [
                'post_id' => (int)$row['ID'],
                'post_type' => (string)$row['post_type'],
                'field' => 'post_author',
                'missing_user_id' => $author_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($post_author_base_root, $post_author_source_root);
    copy_tree_for_test($post_author_base_root, $post_author_target_root);
    cow_merge_capture_file_base($post_author_base_root, $post_author_file_base);
    cow_merge_allocate_autoincrement_bands($post_author_source, $post_author_metadata, 'feature-wp-post-author-source');
    cow_merge_allocate_autoincrement_bands($post_author_target, $post_author_metadata, 'main');

    $db = open_db($post_author_source);
    $db->exec('DELETE FROM wp_users WHERE ID = 44');
    $db->close();

    $db = open_db($post_author_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still pointing at deleted author' WHERE ID = 45");
    $db->exec("UPDATE wp_posts SET post_title = 'Target attachment still pointing at deleted author' WHERE ID = 46");
    $db->close();

    $post_author_result = cow_merge_branch_state(
        $post_author_base,
        $post_author_source,
        $post_author_target,
        $post_author_metadata,
        'feature-wp-post-author-source',
        'main',
        $post_author_file_base,
        $post_author_source_root,
        $post_author_target_root
    );

    assert_same($post_author_result['status'], 'completed_with_conflicts', 'WordPress post-author validator holds missing author users for review');
    assert_same((int)($post_author_result['plugin_validators'] ?? 0), 1, 'WordPress post-author validator is discovered from mu-plugins during merge');
    assert_same((int)($post_author_result['plugin_validator_conflicts'] ?? 0), 2, 'WordPress post-author validator records missing author references for posts and attachments');
    assert_same((int)scalar($post_author_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 44'), 0, 'WordPress post-author validator leaves the source author deletion staged for review');
    assert_same(scalar($post_author_target, 'SELECT post_title FROM wp_posts WHERE ID = 45'), 'Target page still pointing at deleted author', 'WordPress post-author validator preserves the target page edit');
    assert_same(scalar($post_author_target, 'SELECT post_title FROM wp_posts WHERE ID = 46'), 'Target attachment still pointing at deleted author', 'WordPress post-author validator preserves the target attachment edit');

    $post_author_audit = cow_merge_audit_report($post_author_metadata, (int)$post_author_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-post-author-missing-user',
    ]);
    assert_same(count($post_author_audit['conflicts']), 2, 'WordPress post-author validator exposes missing authors as plugin-scoped audit conflicts');
    $post_author_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $post_author_audit['conflicts']));
    assert_true(str_contains($post_author_preview, '"missing_user_id":44'), 'WordPress post-author audit includes the missing user ID');
    assert_true(str_contains($post_author_preview, '"field":"post_author"'), 'WordPress post-author audit includes the stale field name');
    assert_true(str_contains($post_author_preview, '"post_type":"attachment"'), 'WordPress post-author audit includes attachment authors');

    $postmeta_base_root = $tmp . '/postmeta-base';
    $postmeta_source_root = $tmp . '/postmeta-source';
    $postmeta_target_root = $tmp . '/postmeta-target';
    $postmeta_base = $postmeta_base_root . '/wp-content/database/.ht.sqlite';
    $postmeta_source = $postmeta_source_root . '/wp-content/database/.ht.sqlite';
    $postmeta_target = $postmeta_target_root . '/wp-content/database/.ht.sqlite';
    $postmeta_metadata = $tmp . '/.forkpress/cow/merge/wp-postmeta-validator-metadata.sqlite';
    $postmeta_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-postmeta-validator.json';

    mkdir($postmeta_base_root . '/wp-content/database', 0777, true);
    create_wp_postmeta_reference_db($postmeta_base);
    write_test_file($postmeta_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT meta_id, post_id, meta_key FROM wp_postmeta WHERE post_id > 0");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $post_id = (int)$row['post_id'];
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $post_id");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-postmeta-refs',
            'object' => 'postmeta:' . $row['meta_id'],
            'reason' => 'postmeta references a missing post',
            'type' => 'plugin-wp-postmeta-missing-post',
            'tables' => ['wp_postmeta', 'wp_posts'],
            'validator' => 'forkpress-wp-postmeta-refs@1',
            'candidate' => [
                'meta_id' => (int)$row['meta_id'],
                'meta_key' => (string)$row['meta_key'],
                'field' => 'post_id',
                'missing_post_id' => $post_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($postmeta_base_root, $postmeta_source_root);
    copy_tree_for_test($postmeta_base_root, $postmeta_target_root);
    cow_merge_capture_file_base($postmeta_base_root, $postmeta_file_base);
    cow_merge_allocate_autoincrement_bands($postmeta_source, $postmeta_metadata, 'feature-wp-postmeta-source');
    cow_merge_allocate_autoincrement_bands($postmeta_target, $postmeta_metadata, 'main');

    $db = open_db($postmeta_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 52');
    $db->close();

    $db = open_db($postmeta_target);
    $db->exec("UPDATE wp_postmeta SET meta_value = 'Target postmeta still pointing at deleted post' WHERE meta_id = 53");
    $db->exec("UPDATE wp_postmeta SET meta_value = '{\"favorite\":\"target\"}' WHERE meta_id = 54");
    $db->close();

    $postmeta_result = cow_merge_branch_state(
        $postmeta_base,
        $postmeta_source,
        $postmeta_target,
        $postmeta_metadata,
        'feature-wp-postmeta-source',
        'main',
        $postmeta_file_base,
        $postmeta_source_root,
        $postmeta_target_root
    );

    assert_same($postmeta_result['status'], 'completed_with_conflicts', 'WordPress postmeta owner delete with target-edited metadata stays reviewable');
    assert_same((int)($postmeta_result['plugin_validators'] ?? 0), 1, 'WordPress postmeta validator is discovered from mu-plugins during merge');
    assert_same((int)($postmeta_result['plugin_validator_conflicts'] ?? 0), 0, 'WordPress postmeta owner delete guard prevents missing-owner validator fallout');
    assert_same((int)scalar($postmeta_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 52'), 1, 'WordPress postmeta owner delete guard keeps the parent post before review');
    assert_same(scalar($postmeta_target, 'SELECT meta_value FROM wp_postmeta WHERE meta_id = 53'), 'Target postmeta still pointing at deleted post', 'WordPress postmeta validator preserves the target scalar postmeta edit');
    assert_same(scalar($postmeta_target, 'SELECT meta_value FROM wp_postmeta WHERE meta_id = 54'), '{"favorite":"target"}', 'WordPress postmeta validator preserves the target JSON postmeta edit');

    $postmeta_audit = cow_merge_audit_report($postmeta_metadata, (int)$postmeta_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($postmeta_audit['conflicts']), 1, 'WordPress postmeta owner delete guard records one row constraint conflict');
    $postmeta_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $postmeta_audit['conflicts']));
    assert_true(str_contains($postmeta_preview, '52'), 'WordPress postmeta audit includes the guarded post ID');
    $postmeta_blocker_reason = (string)scalar($postmeta_metadata, "SELECT reason FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-wins' ORDER BY id DESC LIMIT 1");
    assert_true(str_contains($postmeta_blocker_reason, 'target has changed wp_postmeta rows'), 'WordPress postmeta audit explains the changed dependent metadata blocker');

    $usermeta_base_root = $tmp . '/usermeta-base';
    $usermeta_source_root = $tmp . '/usermeta-source';
    $usermeta_target_root = $tmp . '/usermeta-target';
    $usermeta_base = $usermeta_base_root . '/wp-content/database/.ht.sqlite';
    $usermeta_source = $usermeta_source_root . '/wp-content/database/.ht.sqlite';
    $usermeta_target = $usermeta_target_root . '/wp-content/database/.ht.sqlite';
    $usermeta_metadata = $tmp . '/.forkpress/cow/merge/wp-usermeta-validator-metadata.sqlite';
    $usermeta_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-usermeta-validator.json';

    mkdir($usermeta_base_root . '/wp-content/database', 0777, true);
    create_wp_usermeta_reference_db($usermeta_base);
    write_test_file($usermeta_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT umeta_id, user_id, meta_key FROM wp_usermeta WHERE user_id > 0");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $user_id = (int)$row['user_id'];
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_users WHERE ID = $user_id");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-usermeta-refs',
            'object' => 'usermeta:' . $row['umeta_id'],
            'reason' => 'usermeta references a missing user',
            'type' => 'plugin-wp-usermeta-missing-user',
            'tables' => ['wp_usermeta', 'wp_users'],
            'validator' => 'forkpress-wp-usermeta-refs@1',
            'candidate' => [
                'umeta_id' => (int)$row['umeta_id'],
                'meta_key' => (string)$row['meta_key'],
                'field' => 'user_id',
                'missing_user_id' => $user_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($usermeta_base_root, $usermeta_source_root);
    copy_tree_for_test($usermeta_base_root, $usermeta_target_root);
    cow_merge_capture_file_base($usermeta_base_root, $usermeta_file_base);
    cow_merge_allocate_autoincrement_bands($usermeta_source, $usermeta_metadata, 'feature-wp-usermeta-source');
    cow_merge_allocate_autoincrement_bands($usermeta_target, $usermeta_metadata, 'main');

    $db = open_db($usermeta_source);
    $db->exec('DELETE FROM wp_users WHERE ID = 49');
    $db->close();

    $db = open_db($usermeta_target);
    $db->exec("UPDATE wp_usermeta SET meta_value = 'Target user description still pointing at deleted user' WHERE umeta_id = 50");
    $db->exec("UPDATE wp_usermeta SET meta_value = '{\"favorite\":\"target\"}' WHERE umeta_id = 51");
    $db->close();

    $usermeta_result = cow_merge_branch_state(
        $usermeta_base,
        $usermeta_source,
        $usermeta_target,
        $usermeta_metadata,
        'feature-wp-usermeta-source',
        'main',
        $usermeta_file_base,
        $usermeta_source_root,
        $usermeta_target_root
    );

    assert_same($usermeta_result['status'], 'completed_with_conflicts', 'WordPress usermeta owner delete with target-edited metadata stays reviewable');
    assert_same((int)($usermeta_result['plugin_validators'] ?? 0), 1, 'WordPress usermeta validator is discovered from mu-plugins during merge');
    assert_same((int)($usermeta_result['plugin_validator_conflicts'] ?? 0), 0, 'WordPress usermeta owner delete guard prevents missing-owner validator fallout');
    assert_same((int)scalar($usermeta_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 49'), 1, 'WordPress usermeta owner delete guard keeps the parent user before review');
    assert_same(scalar($usermeta_target, 'SELECT meta_value FROM wp_usermeta WHERE umeta_id = 50'), 'Target user description still pointing at deleted user', 'WordPress usermeta validator preserves the target scalar usermeta edit');
    assert_same(scalar($usermeta_target, 'SELECT meta_value FROM wp_usermeta WHERE umeta_id = 51'), '{"favorite":"target"}', 'WordPress usermeta validator preserves the target JSON usermeta edit');

    $usermeta_audit = cow_merge_audit_report($usermeta_metadata, (int)$usermeta_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($usermeta_audit['conflicts']), 1, 'WordPress usermeta owner delete guard records one row constraint conflict');
    $usermeta_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $usermeta_audit['conflicts']));
    assert_true(str_contains($usermeta_preview, '49'), 'WordPress usermeta audit includes the guarded user ID');
    $usermeta_blocker_reason = (string)scalar($usermeta_metadata, "SELECT reason FROM merge_decisions WHERE table_name = 'wp_users' AND decision = 'target-wins' ORDER BY id DESC LIMIT 1");
    assert_true(str_contains($usermeta_blocker_reason, 'target has changed wp_usermeta rows'), 'WordPress usermeta audit explains the changed dependent metadata blocker');

    $menu_parent_base_root = $tmp . '/menu-parent-base';
    $menu_parent_source_root = $tmp . '/menu-parent-source';
    $menu_parent_target_root = $tmp . '/menu-parent-target';
    $menu_parent_base = $menu_parent_base_root . '/wp-content/database/.ht.sqlite';
    $menu_parent_source = $menu_parent_source_root . '/wp-content/database/.ht.sqlite';
    $menu_parent_target = $menu_parent_target_root . '/wp-content/database/.ht.sqlite';
    $menu_parent_metadata = $tmp . '/.forkpress/cow/merge/wp-menu-parent-validator-metadata.sqlite';
    $menu_parent_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-menu-parent-validator.json';

    mkdir($menu_parent_base_root . '/wp-content/database', 0777, true);
    create_wp_menu_parent_reference_db($menu_parent_base);
    write_test_file($menu_parent_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT item.ID AS menu_item_id, parent_meta.meta_value AS parent_id
    FROM wp_posts item
    JOIN wp_postmeta parent_meta ON parent_meta.post_id = item.ID AND parent_meta.meta_key = '_menu_item_menu_item_parent'
    WHERE item.post_type = 'nav_menu_item' AND CAST(parent_meta.meta_value AS INTEGER) > 0");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $parent_id = (int)$row['parent_id'];
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $parent_id AND post_type = 'nav_menu_item'");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-menu-parent-refs',
            'object' => 'nav_menu_item:' . $row['menu_item_id'],
            'reason' => 'nav menu item references a missing parent menu item',
            'type' => 'plugin-wp-menu-parent-missing-reference',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-menu-parent-refs@1',
            'candidate' => [
                'menu_item_id' => (int)$row['menu_item_id'],
                'field' => '_menu_item_menu_item_parent',
                'missing_parent_menu_item_id' => $parent_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($menu_parent_base_root, $menu_parent_source_root);
    copy_tree_for_test($menu_parent_base_root, $menu_parent_target_root);
    cow_merge_capture_file_base($menu_parent_base_root, $menu_parent_file_base);
    cow_merge_allocate_autoincrement_bands($menu_parent_source, $menu_parent_metadata, 'feature-wp-menu-parent-source');
    cow_merge_allocate_autoincrement_bands($menu_parent_target, $menu_parent_metadata, 'main');

    $db = open_db($menu_parent_source);
    $db->exec('DELETE FROM wp_postmeta WHERE post_id = 47');
    $db->exec('DELETE FROM wp_posts WHERE ID = 47');
    $db->close();

    $db = open_db($menu_parent_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target child menu item still pointing at deleted parent' WHERE ID = 48");
    $db->close();

    $menu_parent_result = cow_merge_branch_state(
        $menu_parent_base,
        $menu_parent_source,
        $menu_parent_target,
        $menu_parent_metadata,
        'feature-wp-menu-parent-source',
        'main',
        $menu_parent_file_base,
        $menu_parent_source_root,
        $menu_parent_target_root
    );

    assert_same($menu_parent_result['status'], 'completed_with_conflicts', 'WordPress menu-parent validator holds missing parent menu items for review');
    assert_same((int)($menu_parent_result['plugin_validators'] ?? 0), 1, 'WordPress menu-parent validator is discovered from mu-plugins during merge');
    assert_same((int)($menu_parent_result['plugin_validator_conflicts'] ?? 0), 1, 'WordPress menu-parent validator records the missing parent menu item');
    assert_same((int)scalar($menu_parent_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 47'), 0, 'WordPress menu-parent validator leaves the source parent menu item deletion staged for review');
    assert_same((int)scalar($menu_parent_target, 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 47'), 0, 'WordPress menu-parent validator leaves the source parent menu metadata deletion staged for review');
    assert_same(scalar($menu_parent_target, 'SELECT post_title FROM wp_posts WHERE ID = 48'), 'Target child menu item still pointing at deleted parent', 'WordPress menu-parent validator preserves the target child menu item edit');
    assert_same(scalar($menu_parent_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 48 AND meta_key = '_menu_item_menu_item_parent'"), '47', 'WordPress menu-parent validator keeps the stale menu parent reference visible for review');

    $menu_parent_audit = cow_merge_audit_report($menu_parent_metadata, (int)$menu_parent_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-menu-parent-missing-reference',
    ]);
    assert_same(count($menu_parent_audit['conflicts']), 1, 'WordPress menu-parent validator exposes the missing parent menu item as a plugin-scoped audit conflict');
    $menu_parent_preview = (string)($menu_parent_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($menu_parent_preview, '"missing_parent_menu_item_id":47'), 'WordPress menu-parent audit includes the missing parent menu item ID');
    assert_true(str_contains($menu_parent_preview, '"menu_item_id":48'), 'WordPress menu-parent audit includes the child menu item ID');
    assert_true(str_contains($menu_parent_preview, '"field":"_menu_item_menu_item_parent"'), 'WordPress menu-parent audit includes the stale field name');

    $menu_base_root = $tmp . '/menu-base';
    $menu_source_root = $tmp . '/menu-source';
    $menu_target_root = $tmp . '/menu-target';
    $menu_base = $menu_base_root . '/wp-content/database/.ht.sqlite';
    $menu_source = $menu_source_root . '/wp-content/database/.ht.sqlite';
    $menu_target = $menu_target_root . '/wp-content/database/.ht.sqlite';
    $menu_metadata = $tmp . '/.forkpress/cow/merge/wp-menu-ref-validator-metadata.sqlite';
    $menu_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-menu-ref-validator.json';

    mkdir($menu_base_root . '/wp-content/database', 0777, true);
    create_wp_menu_ref_db($menu_base);
    write_test_file($menu_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT item.ID AS menu_item_id, item_type.meta_value AS menu_item_type, object.meta_value AS object_type, object_id.meta_value AS object_id
    FROM wp_posts item
    JOIN wp_postmeta item_type ON item_type.post_id = item.ID AND item_type.meta_key = '_menu_item_type'
    JOIN wp_postmeta object ON object.post_id = item.ID AND object.meta_key = '_menu_item_object'
    JOIN wp_postmeta object_id ON object_id.post_id = item.ID AND object_id.meta_key = '_menu_item_object_id'
    WHERE item.post_type = 'nav_menu_item' AND item_type.meta_value IN ('post_type', 'taxonomy')");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $menu_item_type = (string)$row['menu_item_type'];
    $object_type = (string)$row['object_type'];
    $object_id = (int)$row['object_id'];
    $escaped_type = SQLite3::escapeString($object_type);
    if ($menu_item_type === 'taxonomy') {
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = '$escaped_type' WHERE t.term_id = $object_id");
        $reason = 'nav menu item references a missing taxonomy term';
        $tables = ['wp_posts', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy'];
    } else {
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $object_id AND post_type = '$escaped_type'");
        $reason = 'nav menu item references a missing post object';
        $tables = ['wp_posts', 'wp_postmeta'];
    }
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-menu-refs',
            'object' => 'nav_menu_item:' . $row['menu_item_id'],
            'reason' => $reason,
            'type' => 'plugin-wp-menu-missing-object',
            'tables' => $tables,
            'validator' => 'forkpress-wp-menu-refs@1',
            'candidate' => [
                'menu_item_id' => (int)$row['menu_item_id'],
                'menu_item_type' => $menu_item_type,
                'object_type' => $object_type,
                'missing_object_id' => $object_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($menu_base_root, $menu_source_root);
    copy_tree_for_test($menu_base_root, $menu_target_root);
    cow_merge_capture_file_base($menu_base_root, $menu_file_base);
    cow_merge_allocate_autoincrement_bands($menu_source, $menu_metadata, 'feature-wp-menu-ref-source');
    cow_merge_allocate_autoincrement_bands($menu_target, $menu_metadata, 'main');

    $db = open_db($menu_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 40');
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_id = 43');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 43');
    $db->close();

    $db = open_db($menu_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target menu item still pointing at deleted page' WHERE ID = 41");
    $db->exec("UPDATE wp_posts SET post_title = 'Target menu item still pointing at deleted category' WHERE ID = 42");
    $db->close();

    $menu_result = cow_merge_branch_state(
        $menu_base,
        $menu_source,
        $menu_target,
        $menu_metadata,
        'feature-wp-menu-ref-source',
        'main',
        $menu_file_base,
        $menu_source_root,
        $menu_target_root
    );

    assert_same($menu_result['status'], 'completed_with_conflicts', 'WordPress menu-reference validator holds missing menu objects for review');
    assert_same((int)($menu_result['plugin_validators'] ?? 0), 1, 'WordPress menu-reference validator is discovered from mu-plugins during merge');
    assert_same((int)($menu_result['plugin_validator_conflicts'] ?? 0), 2, 'WordPress menu-reference validator records missing page and taxonomy objects');
    assert_same((int)scalar($menu_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 40'), 0, 'WordPress menu-reference validator leaves the source page deletion staged for review');
    assert_same((int)scalar($menu_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 43'), 0, 'WordPress menu-reference validator leaves the source term deletion staged for review');
    assert_same((int)scalar($menu_target, 'SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_id = 43'), 0, 'WordPress menu-reference validator leaves the source taxonomy deletion staged for review');
    assert_same(scalar($menu_target, 'SELECT post_title FROM wp_posts WHERE ID = 41'), 'Target menu item still pointing at deleted page', 'WordPress menu-reference validator preserves the target menu item edit');
    assert_same(scalar($menu_target, 'SELECT post_title FROM wp_posts WHERE ID = 42'), 'Target menu item still pointing at deleted category', 'WordPress menu-reference validator preserves the taxonomy menu item edit');

    $menu_audit = cow_merge_audit_report($menu_metadata, (int)$menu_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-menu-missing-object',
    ]);
    assert_same(count($menu_audit['conflicts']), 2, 'WordPress menu-reference validator exposes missing menu objects as plugin-scoped audit conflicts');
    $menu_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $menu_audit['conflicts']));
    assert_true(str_contains($menu_preview, '"missing_object_id":40'), 'WordPress menu-reference audit includes the missing page ID');
    assert_true(str_contains($menu_preview, '"object_type":"page"'), 'WordPress menu-reference audit includes the page object type');
    assert_true(str_contains($menu_preview, '"missing_object_id":43'), 'WordPress menu-reference audit includes the missing term ID');
    assert_true(str_contains($menu_preview, '"menu_item_type":"taxonomy"'), 'WordPress menu-reference audit includes the taxonomy menu item type');
    assert_true(str_contains($menu_preview, '"object_type":"category"'), 'WordPress menu-reference audit includes the taxonomy object type');

    $menu_guard_base_root = $tmp . '/menu-metadata-owner-guard-base';
    $menu_guard_source_root = $tmp . '/menu-metadata-owner-guard-source';
    $menu_guard_target_root = $tmp . '/menu-metadata-owner-guard-target';
    $menu_guard_base = $menu_guard_base_root . '/wp-content/database/.ht.sqlite';
    $menu_guard_source = $menu_guard_source_root . '/wp-content/database/.ht.sqlite';
    $menu_guard_target = $menu_guard_target_root . '/wp-content/database/.ht.sqlite';
    $menu_guard_metadata = $tmp . '/.forkpress/cow/merge/wp-menu-metadata-owner-guard-metadata.sqlite';

    mkdir($menu_guard_base_root . '/wp-content/database', 0777, true);
    create_wp_menu_ref_db($menu_guard_base);
    copy_tree_for_test($menu_guard_base_root, $menu_guard_source_root);
    copy_tree_for_test($menu_guard_base_root, $menu_guard_target_root);
    cow_merge_allocate_autoincrement_bands($menu_guard_source, $menu_guard_metadata, 'feature-wp-menu-metadata-owner-guard-source');
    cow_merge_allocate_autoincrement_bands($menu_guard_target, $menu_guard_metadata, 'main');

    $db = open_db($menu_guard_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 40');
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_id = 43');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 43');
    $db->close();

    $db = open_db($menu_guard_target);
    $db->exec("UPDATE wp_postmeta SET meta_value = '40 ' WHERE post_id = 41 AND meta_key = '_menu_item_object_id'");
    $db->exec("UPDATE wp_postmeta SET meta_value = '43 ' WHERE post_id = 42 AND meta_key = '_menu_item_object_id'");
    $db->close();

    $menu_guard_result = cow_merge_branch_state(
        $menu_guard_base,
        $menu_guard_source,
        $menu_guard_target,
        $menu_guard_metadata,
        'feature-wp-menu-metadata-owner-guard-source',
        'main'
    );

    assert_same($menu_guard_result['status'], 'completed_with_conflicts', 'WordPress menu object metadata owner deletes with target-edited value refs stay reviewable');
    assert_same((int)scalar($menu_guard_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 40'), 1, 'WordPress menu object owner guard keeps the referenced page before review');
    assert_same((int)scalar($menu_guard_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 43'), 1, 'WordPress menu object owner guard keeps the referenced term before review');
    assert_same(scalar($menu_guard_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 41 AND meta_key = '_menu_item_object_id'"), '40 ', 'WordPress menu object owner guard preserves target-edited page menu metadata');
    assert_same(scalar($menu_guard_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 42 AND meta_key = '_menu_item_object_id'"), '43 ', 'WordPress menu object owner guard preserves target-edited taxonomy menu metadata');

    $menu_guard_audit = cow_merge_audit_report($menu_guard_metadata, (int)$menu_guard_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($menu_guard_audit['conflicts']), 2, 'WordPress menu object owner guard records one row constraint per guarded object');
    $menu_guard_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $menu_guard_audit['conflicts']));
    foreach (['40', '43'] as $needle) {
        assert_true(str_contains($menu_guard_preview, $needle), 'WordPress menu object owner guard audit includes ' . $needle);
    }
    $menu_guard_reasons = (string)scalar($menu_guard_metadata, "SELECT group_concat(reason, '\n') FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_terms') AND decision = 'target-wins'");
    assert_true(str_contains($menu_guard_reasons, 'target has changed wp_postmeta rows'), 'WordPress menu object owner guard audit explains changed menu metadata blockers');

    $menu_parent_guard_base_root = $tmp . '/menu-parent-metadata-owner-guard-base';
    $menu_parent_guard_source_root = $tmp . '/menu-parent-metadata-owner-guard-source';
    $menu_parent_guard_target_root = $tmp . '/menu-parent-metadata-owner-guard-target';
    $menu_parent_guard_base = $menu_parent_guard_base_root . '/wp-content/database/.ht.sqlite';
    $menu_parent_guard_source = $menu_parent_guard_source_root . '/wp-content/database/.ht.sqlite';
    $menu_parent_guard_target = $menu_parent_guard_target_root . '/wp-content/database/.ht.sqlite';
    $menu_parent_guard_metadata = $tmp . '/.forkpress/cow/merge/wp-menu-parent-metadata-owner-guard-metadata.sqlite';

    mkdir($menu_parent_guard_base_root . '/wp-content/database', 0777, true);
    create_wp_menu_parent_reference_db($menu_parent_guard_base);
    copy_tree_for_test($menu_parent_guard_base_root, $menu_parent_guard_source_root);
    copy_tree_for_test($menu_parent_guard_base_root, $menu_parent_guard_target_root);
    cow_merge_allocate_autoincrement_bands($menu_parent_guard_source, $menu_parent_guard_metadata, 'feature-wp-menu-parent-metadata-owner-guard-source');
    cow_merge_allocate_autoincrement_bands($menu_parent_guard_target, $menu_parent_guard_metadata, 'main');

    $db = open_db($menu_parent_guard_source);
    $db->exec('DELETE FROM wp_postmeta WHERE post_id = 47');
    $db->exec('DELETE FROM wp_posts WHERE ID = 47');
    $db->close();

    $db = open_db($menu_parent_guard_target);
    $db->exec("UPDATE wp_postmeta SET meta_value = '47 ' WHERE post_id = 48 AND meta_key = '_menu_item_menu_item_parent'");
    $db->close();

    $menu_parent_guard_result = cow_merge_branch_state(
        $menu_parent_guard_base,
        $menu_parent_guard_source,
        $menu_parent_guard_target,
        $menu_parent_guard_metadata,
        'feature-wp-menu-parent-metadata-owner-guard-source',
        'main'
    );

    assert_same($menu_parent_guard_result['status'], 'completed_with_conflicts', 'WordPress menu-parent metadata owner delete with target-edited value refs stays reviewable');
    assert_same((int)scalar($menu_parent_guard_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 47'), 1, 'WordPress menu-parent owner guard keeps the referenced menu item before review');
    assert_same(scalar($menu_parent_guard_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 48 AND meta_key = '_menu_item_menu_item_parent'"), '47 ', 'WordPress menu-parent owner guard preserves target-edited parent metadata');

    $menu_parent_guard_audit = cow_merge_audit_report($menu_parent_guard_metadata, (int)$menu_parent_guard_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($menu_parent_guard_audit['conflicts']), 1, 'WordPress menu-parent owner guard records one row constraint conflict');
    $menu_parent_guard_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $menu_parent_guard_audit['conflicts']));
    assert_true(str_contains($menu_parent_guard_preview, '47'), 'WordPress menu-parent owner guard audit includes the guarded menu item ID');

    $featured_base_root = $tmp . '/featured-media-base';
    $featured_source_root = $tmp . '/featured-media-source';
    $featured_target_root = $tmp . '/featured-media-target';
    $featured_base = $featured_base_root . '/wp-content/database/.ht.sqlite';
    $featured_source = $featured_source_root . '/wp-content/database/.ht.sqlite';
    $featured_target = $featured_target_root . '/wp-content/database/.ht.sqlite';
    $featured_metadata = $tmp . '/.forkpress/cow/merge/wp-featured-media-validator-metadata.sqlite';
    $featured_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-featured-media-validator.json';

    mkdir($featured_base_root . '/wp-content/database', 0777, true);
    create_wp_featured_media_db($featured_base);
    write_test_file($featured_base_root . '/wp-content/uploads/2026/05/featured-image.jpg', 'featured image bytes');
    write_test_file($featured_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT meta_id, post_id, meta_value FROM wp_postmeta WHERE meta_key = '_thumbnail_id'");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $attachment_id = (int)$row['meta_value'];
    if ($attachment_id <= 0) {
        continue;
    }
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-featured-media',
            'object' => 'postmeta:' . $row['meta_id'],
            'reason' => 'featured image references a missing attachment',
            'type' => 'plugin-wp-featured-image-missing-attachment',
            'tables' => ['wp_postmeta', 'wp_posts'],
            'validator' => 'forkpress-wp-featured-media@1',
            'candidate' => [
                'post_id' => (int)$row['post_id'],
                'meta_id' => (int)$row['meta_id'],
                'field' => '_thumbnail_id',
                'missing_object_id' => $attachment_id,
                'object_type' => 'attachment',
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($featured_base_root, $featured_source_root);
    copy_tree_for_test($featured_base_root, $featured_target_root);
    cow_merge_capture_file_base($featured_base_root, $featured_file_base);
    cow_merge_allocate_autoincrement_bands($featured_source, $featured_metadata, 'feature-wp-featured-media-source');
    cow_merge_allocate_autoincrement_bands($featured_target, $featured_metadata, 'main');

    $db = open_db($featured_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 61');
    $db->close();
    unlink($featured_source_root . '/wp-content/uploads/2026/05/featured-image.jpg');

    $db = open_db($featured_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted featured image' WHERE ID = 60");
    $db->close();

    $featured_result = cow_merge_branch_state(
        $featured_base,
        $featured_source,
        $featured_target,
        $featured_metadata,
        'feature-wp-featured-media-source',
        'main',
        $featured_file_base,
        $featured_source_root,
        $featured_target_root
    );

    assert_same($featured_result['status'], 'completed_with_conflicts', 'WordPress featured image validator holds missing attachments for review');
    assert_same((int)($featured_result['plugin_validators'] ?? 0), 1, 'WordPress featured image validator is discovered from mu-plugins during merge');
    assert_same((int)($featured_result['plugin_validator_conflicts'] ?? 0), 1, 'WordPress featured image validator records the missing attachment');
    assert_same((int)scalar($featured_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 61'), 0, 'WordPress featured image validator leaves the source attachment deletion staged for review');
    assert_true(!file_exists($featured_target_root . '/wp-content/uploads/2026/05/featured-image.jpg'), 'WordPress featured image validator leaves the source upload deletion staged for review');
    assert_same(scalar($featured_target, 'SELECT post_title FROM wp_posts WHERE ID = 60'), 'Target page still using deleted featured image', 'WordPress featured image validator preserves the target page edit');
    assert_same(scalar($featured_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 60 AND meta_key = '_thumbnail_id'"), '61', 'WordPress featured image validator keeps the stale thumbnail reference visible for review');

    $featured_audit = cow_merge_audit_report($featured_metadata, (int)$featured_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-featured-image-missing-attachment',
    ]);
    assert_same(count($featured_audit['conflicts']), 1, 'WordPress featured image validator exposes the missing attachment as a plugin-scoped audit conflict');
    $featured_preview = (string)($featured_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($featured_preview, '"missing_object_id":61'), 'WordPress featured image audit includes the missing attachment ID');
    assert_true(str_contains($featured_preview, '"field":"_thumbnail_id"'), 'WordPress featured image audit includes the thumbnail field');

    $featured_guard_base_root = $tmp . '/featured-media-owner-guard-base';
    $featured_guard_source_root = $tmp . '/featured-media-owner-guard-source';
    $featured_guard_target_root = $tmp . '/featured-media-owner-guard-target';
    $featured_guard_base = $featured_guard_base_root . '/wp-content/database/.ht.sqlite';
    $featured_guard_source = $featured_guard_source_root . '/wp-content/database/.ht.sqlite';
    $featured_guard_target = $featured_guard_target_root . '/wp-content/database/.ht.sqlite';
    $featured_guard_metadata = $tmp . '/.forkpress/cow/merge/wp-featured-media-owner-guard-metadata.sqlite';

    mkdir($featured_guard_base_root . '/wp-content/database', 0777, true);
    create_wp_featured_media_db($featured_guard_base);
    copy_tree_for_test($featured_guard_base_root, $featured_guard_source_root);
    copy_tree_for_test($featured_guard_base_root, $featured_guard_target_root);
    cow_merge_allocate_autoincrement_bands($featured_guard_source, $featured_guard_metadata, 'feature-wp-featured-media-owner-guard-source');
    cow_merge_allocate_autoincrement_bands($featured_guard_target, $featured_guard_metadata, 'main');

    $db = open_db($featured_guard_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 61');
    $db->close();

    $db = open_db($featured_guard_target);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (62, 'Target page newly using featured image', '<!-- wp:paragraph --><p>Target featured page</p><!-- /wp:paragraph -->', 'publish', 'page', 'target-featured-page', '')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (6001, 62, '_thumbnail_id', '61')");
    $db->close();

    $featured_guard_result = cow_merge_branch_state(
        $featured_guard_base,
        $featured_guard_source,
        $featured_guard_target,
        $featured_guard_metadata,
        'feature-wp-featured-media-owner-guard-source',
        'main'
    );

    assert_same($featured_guard_result['status'], 'completed_with_conflicts', 'WordPress featured-image owner delete with target-added thumbnail metadata stays reviewable');
    assert_same((int)($featured_guard_result['plugin_validator_conflicts'] ?? 0), 0, 'WordPress featured-image owner guard prevents missing-thumbnail validator fallout');
    assert_same((int)scalar($featured_guard_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 61'), 1, 'WordPress featured-image owner guard keeps the referenced attachment before review');
    assert_same(scalar($featured_guard_target, "SELECT meta_value FROM wp_postmeta WHERE meta_id = 6001"), '61', 'WordPress featured-image owner guard preserves target-added thumbnail metadata');

    $featured_guard_audit = cow_merge_audit_report($featured_guard_metadata, (int)$featured_guard_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($featured_guard_audit['conflicts']), 1, 'WordPress featured-image owner guard records one row constraint conflict');
    $featured_guard_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $featured_guard_audit['conflicts']));
    assert_true(str_contains($featured_guard_preview, '61'), 'WordPress featured-image owner guard audit includes the guarded attachment ID');
    $featured_guard_reason = (string)scalar($featured_guard_metadata, "SELECT reason FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-wins' ORDER BY id DESC LIMIT 1");
    assert_true(str_contains($featured_guard_reason, 'target has changed wp_postmeta rows'), 'WordPress featured-image owner guard audit explains the changed thumbnail metadata blocker');

    $scalar_option_guard_base_root = $tmp . '/scalar-option-owner-guard-base';
    $scalar_option_guard_source_root = $tmp . '/scalar-option-owner-guard-source';
    $scalar_option_guard_target_root = $tmp . '/scalar-option-owner-guard-target';
    $scalar_option_guard_base = $scalar_option_guard_base_root . '/wp-content/database/.ht.sqlite';
    $scalar_option_guard_source = $scalar_option_guard_source_root . '/wp-content/database/.ht.sqlite';
    $scalar_option_guard_target = $scalar_option_guard_target_root . '/wp-content/database/.ht.sqlite';
    $scalar_option_guard_metadata = $tmp . '/.forkpress/cow/merge/wp-scalar-option-owner-guard-metadata.sqlite';

    mkdir($scalar_option_guard_base_root . '/wp-content/database', 0777, true);
    create_wp_scalar_option_owner_reference_db($scalar_option_guard_base);
    copy_tree_for_test($scalar_option_guard_base_root, $scalar_option_guard_source_root);
    copy_tree_for_test($scalar_option_guard_base_root, $scalar_option_guard_target_root);
    cow_merge_allocate_autoincrement_bands($scalar_option_guard_source, $scalar_option_guard_metadata, 'feature-wp-scalar-option-owner-guard-source');
    cow_merge_allocate_autoincrement_bands($scalar_option_guard_target, $scalar_option_guard_metadata, 'main');

    $db = open_db($scalar_option_guard_source);
    $db->exec('DELETE FROM wp_posts WHERE ID IN (130, 131, 132)');
    $db->close();

    $db = open_db($scalar_option_guard_target);
    $db->exec("UPDATE wp_options SET option_value = '130' WHERE option_name = 'page_on_front'");
    $db->exec("UPDATE wp_options SET option_value = '131' WHERE option_name = 'page_for_posts'");
    $db->exec("UPDATE wp_options SET option_value = '132' WHERE option_name = 'site_icon'");
    $db->close();

    $scalar_option_guard_result = cow_merge_branch_state(
        $scalar_option_guard_base,
        $scalar_option_guard_source,
        $scalar_option_guard_target,
        $scalar_option_guard_metadata,
        'feature-wp-scalar-option-owner-guard-source',
        'main'
    );

    assert_same($scalar_option_guard_result['status'], 'completed_with_conflicts', 'WordPress scalar option owner deletes with target-edited option references stay reviewable');
    assert_same((int)scalar($scalar_option_guard_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID IN (130, 131, 132)'), 3, 'WordPress scalar option owner guard keeps referenced pages and site icon before review');
    assert_same(scalar($scalar_option_guard_target, "SELECT option_value FROM wp_options WHERE option_name = 'page_on_front'"), '130', 'WordPress scalar option owner guard preserves target front-page option edit');
    assert_same(scalar($scalar_option_guard_target, "SELECT option_value FROM wp_options WHERE option_name = 'page_for_posts'"), '131', 'WordPress scalar option owner guard preserves target posts-page option edit');
    assert_same(scalar($scalar_option_guard_target, "SELECT option_value FROM wp_options WHERE option_name = 'site_icon'"), '132', 'WordPress scalar option owner guard preserves target site-icon option edit');

    $scalar_option_guard_audit = cow_merge_audit_report($scalar_option_guard_metadata, (int)$scalar_option_guard_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($scalar_option_guard_audit['conflicts']), 3, 'WordPress scalar option owner guard records one row constraint per guarded option owner');
    $scalar_option_guard_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $scalar_option_guard_audit['conflicts']));
    foreach (['130', '131', '132'] as $needle) {
        assert_true(str_contains($scalar_option_guard_preview, $needle), 'WordPress scalar option owner guard audit includes ' . $needle);
    }
    $scalar_option_guard_reasons = (string)scalar($scalar_option_guard_metadata, "SELECT group_concat(reason, '\n') FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-wins'");
    assert_true(str_contains($scalar_option_guard_reasons, 'target has changed wp_options rows'), 'WordPress scalar option owner guard audit explains the changed option blockers');

    $serialized_option_guard_base_root = $tmp . '/serialized-option-owner-guard-base';
    $serialized_option_guard_source_root = $tmp . '/serialized-option-owner-guard-source';
    $serialized_option_guard_target_root = $tmp . '/serialized-option-owner-guard-target';
    $serialized_option_guard_base = $serialized_option_guard_base_root . '/wp-content/database/.ht.sqlite';
    $serialized_option_guard_source = $serialized_option_guard_source_root . '/wp-content/database/.ht.sqlite';
    $serialized_option_guard_target = $serialized_option_guard_target_root . '/wp-content/database/.ht.sqlite';
    $serialized_option_guard_metadata = $tmp . '/.forkpress/cow/merge/wp-serialized-option-owner-guard-metadata.sqlite';

    mkdir($serialized_option_guard_base_root . '/wp-content/database', 0777, true);
    create_wp_serialized_option_owner_reference_db($serialized_option_guard_base);
    copy_tree_for_test($serialized_option_guard_base_root, $serialized_option_guard_source_root);
    copy_tree_for_test($serialized_option_guard_base_root, $serialized_option_guard_target_root);
    cow_merge_allocate_autoincrement_bands($serialized_option_guard_source, $serialized_option_guard_metadata, 'feature-wp-serialized-option-owner-guard-source');
    cow_merge_allocate_autoincrement_bands($serialized_option_guard_target, $serialized_option_guard_metadata, 'main');

    $db = open_db($serialized_option_guard_source);
    $db->exec('DELETE FROM wp_posts WHERE ID IN (140, 141, 142, 145)');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 143');
    $db->exec('DELETE FROM wp_users WHERE ID = 144');
    $db->close();

    $db = open_db($serialized_option_guard_target);
    $set_option_value = function (string $option_name, string $option_value) use ($db): void {
        $stmt = $db->prepare('UPDATE wp_options SET option_value = :value WHERE option_name = :name');
        $stmt->bindValue(':value', $option_value, SQLITE3_TEXT);
        $stmt->bindValue(':name', $option_name, SQLITE3_TEXT);
        $stmt->execute();
    };
    $set_option_value('sticky_posts', serialize(['140']));
    $set_option_value('theme_mods_forkpress_active', serialize([
        'custom_logo' => 142,
        'nav_menu_locations' => ['primary' => 143],
        'forkpress_accent' => 'target',
    ]));
    $set_option_value('widget_nav_menu', serialize([
        2 => ['title' => 'Target menu widget', 'nav_menu' => '143'],
        '_multiwidget' => 1,
    ]));
    $set_option_value('nav_menu_options', serialize(['auto_add' => ['143']]));
    $set_option_value('widget_media_image', serialize([
        3 => ['attachment_id' => '142', 'caption' => 'Target image widget'],
        '_multiwidget' => 1,
    ]));
    $set_option_value('widget_media_audio', serialize([
        4 => ['attachment_id' => 142, 'caption' => 'Target audio widget'],
        '_multiwidget' => 1,
    ]));
    $set_option_value('widget_media_video', serialize([
        5 => ['attachment_id' => 142, 'caption' => 'Target video widget'],
        '_multiwidget' => 1,
    ]));
    $set_option_value('widget_media_gallery', serialize([
        6 => ['ids' => '142', 'caption' => 'Target gallery widget'],
        '_multiwidget' => 1,
    ]));
    $set_option_value('widget_pages', serialize([
        7 => ['exclude' => ['141'], 'title' => 'Target pages widget'],
        '_multiwidget' => 1,
    ]));
    $set_option_value('widget_block', serialize([
        8 => ['content' => '<!-- wp:avatar {"userId":144} /-->', 'title' => 'Target block widget'],
        '_multiwidget' => 1,
    ]));
    $set_option_value('widget_text', serialize([
        9 => ['text' => '<!-- wp:image {"id":145} --><figure class="wp-block-image"><img class="wp-image-145"/></figure><!-- /wp:image -->', 'title' => 'Target text widget'],
        '_multiwidget' => 1,
    ]));
    $set_option_value('widget_custom_html', serialize([
        10 => ['content' => '<!-- wp:gallery {"ids":[145]} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->', 'title' => 'Target custom HTML widget'],
        '_multiwidget' => 1,
    ]));
    $db->close();

    $serialized_option_guard_result = cow_merge_branch_state(
        $serialized_option_guard_base,
        $serialized_option_guard_source,
        $serialized_option_guard_target,
        $serialized_option_guard_metadata,
        'feature-wp-serialized-option-owner-guard-source',
        'main'
    );

    assert_same($serialized_option_guard_result['status'], 'completed_with_conflicts', 'WordPress serialized option owner deletes with target-edited references stay reviewable');
    assert_same((int)scalar($serialized_option_guard_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID IN (140, 141, 142, 145)'), 4, 'WordPress serialized option owner guard keeps referenced posts before review');
    assert_same((int)scalar($serialized_option_guard_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 143'), 1, 'WordPress serialized option owner guard keeps referenced nav menu term before review');
    assert_same((int)scalar($serialized_option_guard_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 144'), 1, 'WordPress serialized option owner guard keeps referenced widget user before review');

    $target_theme_mods = unserialize((string)scalar($serialized_option_guard_target, "SELECT option_value FROM wp_options WHERE option_name = 'theme_mods_forkpress_active'"), ['allowed_classes' => false]);
    $target_gallery_widget = unserialize((string)scalar($serialized_option_guard_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_media_gallery'"), ['allowed_classes' => false]);
    $target_pages_widget = unserialize((string)scalar($serialized_option_guard_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_pages'"), ['allowed_classes' => false]);
    $target_block_widget = unserialize((string)scalar($serialized_option_guard_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_block'"), ['allowed_classes' => false]);
    $target_text_widget = unserialize((string)scalar($serialized_option_guard_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_text'"), ['allowed_classes' => false]);
    $target_custom_html_widget = unserialize((string)scalar($serialized_option_guard_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_custom_html'"), ['allowed_classes' => false]);
    assert_same($target_theme_mods['forkpress_accent'] ?? null, 'target', 'WordPress serialized option owner guard preserves target theme-mod edit');
    assert_same($target_gallery_widget[6]['caption'] ?? null, 'Target gallery widget', 'WordPress serialized option owner guard preserves target media gallery edit');
    assert_same($target_pages_widget[7]['title'] ?? null, 'Target pages widget', 'WordPress serialized option owner guard preserves target pages widget edit');
    assert_same($target_block_widget[8]['title'] ?? null, 'Target block widget', 'WordPress serialized option owner guard preserves target block widget edit');
    assert_same($target_text_widget[9]['title'] ?? null, 'Target text widget', 'WordPress serialized option owner guard preserves target text widget edit');
    assert_same($target_custom_html_widget[10]['title'] ?? null, 'Target custom HTML widget', 'WordPress serialized option owner guard preserves target custom HTML widget edit');

    $serialized_option_guard_audit = cow_merge_audit_report($serialized_option_guard_metadata, (int)$serialized_option_guard_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($serialized_option_guard_audit['conflicts']), 6, 'WordPress serialized option owner guard records one row constraint per guarded owner');
    $serialized_option_guard_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $serialized_option_guard_audit['conflicts']));
    foreach (['140', '141', '142', '143', '144', '145'] as $needle) {
        assert_true(str_contains($serialized_option_guard_preview, $needle), 'WordPress serialized option owner guard audit includes ' . $needle);
    }
    $serialized_option_guard_reasons = (string)scalar($serialized_option_guard_metadata, "SELECT group_concat(reason, '\n') FROM merge_decisions WHERE decision = 'target-wins'");
    assert_true(str_contains($serialized_option_guard_reasons, 'target has changed wp_options rows'), 'WordPress serialized option owner guard audit explains the changed option blockers');

    $attachment_upload_base_root = $tmp . '/attachment-upload-base';
    $attachment_upload_source_root = $tmp . '/attachment-upload-source';
    $attachment_upload_target_root = $tmp . '/attachment-upload-target';
    $attachment_upload_base = $attachment_upload_base_root . '/wp-content/database/.ht.sqlite';
    $attachment_upload_source = $attachment_upload_source_root . '/wp-content/database/.ht.sqlite';
    $attachment_upload_target = $attachment_upload_target_root . '/wp-content/database/.ht.sqlite';
    $attachment_upload_metadata = $tmp . '/.forkpress/cow/merge/wp-attachment-upload-validator-metadata.sqlite';
    $attachment_upload_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-attachment-upload-validator.json';

    mkdir($attachment_upload_base_root . '/wp-content/database', 0777, true);
    create_wp_attachment_upload_metadata_db($attachment_upload_base);
    foreach ([
        'generated-image.jpg',
        'generated-image-150x150.jpg',
        'generated-image-300x200.jpg',
        'generated-image-original.jpg',
        'generated-image-backup.jpg',
    ] as $filename) {
        write_test_file($attachment_upload_base_root . '/wp-content/uploads/2026/05/' . $filename, $filename . ' bytes');
    }
    copy_tree_for_test($attachment_upload_base_root, $attachment_upload_source_root);
    copy_tree_for_test($attachment_upload_base_root, $attachment_upload_target_root);
    cow_merge_capture_file_base($attachment_upload_base_root, $attachment_upload_file_base);
    cow_merge_allocate_autoincrement_bands($attachment_upload_source, $attachment_upload_metadata, 'feature-wp-attachment-upload-source');
    cow_merge_allocate_autoincrement_bands($attachment_upload_target, $attachment_upload_metadata, 'main');

    unlink($attachment_upload_source_root . '/wp-content/uploads/2026/05/generated-image-150x150.jpg');
    $has_attachment_upload_symlink = create_test_symlink(
        'generated-image.jpg',
        $attachment_upload_source_root . '/wp-content/uploads/2026/05/generated-image-symlink.jpg'
    );
    $db = open_db($attachment_upload_source);
    $metadata_value = (string)$db->querySingle("SELECT meta_value FROM wp_postmeta WHERE post_id = 63 AND meta_key = '_wp_attachment_metadata'");
    $metadata_array = unserialize($metadata_value, ['allowed_classes' => false]);
    $metadata_array['sizes']['escaped-managed-db'] = [
        'file' => '../../../../database/.ht.sqlite',
        'width' => 64,
        'height' => 64,
    ];
    if ($has_attachment_upload_symlink) {
        $metadata_array['sizes']['symlinked-generated'] = [
            'file' => 'generated-image-symlink.jpg',
            'width' => 64,
            'height' => 64,
        ];
    }
    $stmt = $db->prepare("UPDATE wp_postmeta SET meta_value = :metadata WHERE post_id = 63 AND meta_key = '_wp_attachment_metadata'");
    $stmt->bindValue(':metadata', serialize($metadata_array), SQLITE3_TEXT);
    $stmt->execute();
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name, guid) VALUES ('Source duplicate upload owner', '', 'inherit', 'attachment', 'source-duplicate-upload-owner', 'wp-content/uploads/2026/05/generated-image.jpg')");
    $duplicate_upload_attachment_id = (int)$db->lastInsertRowID();
    $duplicate_upload_metadata = serialize([
        'file' => '2026/05/generated-image.jpg',
        'width' => 1200,
        'height' => 800,
        'sizes' => [],
    ]);
    $stmt = $db->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:post_id, '_wp_attached_file', '2026/05/generated-image.jpg'), (:post_id, '_wp_attachment_metadata', :metadata)");
    $stmt->bindValue(':post_id', $duplicate_upload_attachment_id, SQLITE3_INTEGER);
    $stmt->bindValue(':metadata', $duplicate_upload_metadata, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $db = open_db($attachment_upload_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target attachment keeps generated metadata' WHERE ID = 63");
    $db->close();

    $attachment_upload_result = cow_merge_branch_state(
        $attachment_upload_base,
        $attachment_upload_source,
        $attachment_upload_target,
        $attachment_upload_metadata,
        'feature-wp-attachment-upload-source',
        'main',
        $attachment_upload_file_base,
        $attachment_upload_source_root,
        $attachment_upload_target_root
    );

    assert_same($attachment_upload_result['status'], 'completed_with_conflicts', 'built-in WordPress attachment upload validator holds missing generated files for review');
    $expected_attachment_upload_conflicts = $has_attachment_upload_symlink ? 4 : 3;
    assert_same((int)($attachment_upload_result['wordpress_semantic_validator_conflicts'] ?? 0), $expected_attachment_upload_conflicts, 'built-in WordPress attachment upload validator records generated-file, unsafe-path, non-regular-entry, and duplicate-owner conflicts');
    assert_same((int)($attachment_upload_result['plugin_validator_conflicts'] ?? 0), $expected_attachment_upload_conflicts, 'built-in WordPress attachment upload validator contributes to plugin-scoped conflict totals');
    assert_true(!file_exists($attachment_upload_target_root . '/wp-content/uploads/2026/05/generated-image-150x150.jpg'), 'built-in WordPress attachment upload validator leaves the source generated-file deletion staged for review');
    assert_true(is_file($attachment_upload_target_root . '/wp-content/uploads/2026/05/generated-image.jpg'), 'built-in WordPress attachment upload validator preserves the original upload file');
    assert_true(is_file($attachment_upload_target_root . '/wp-content/uploads/2026/05/generated-image-300x200.jpg'), 'built-in WordPress attachment upload validator preserves unrelated generated files');
    assert_same(scalar($attachment_upload_target, 'SELECT post_title FROM wp_posts WHERE ID = 63'), 'Target attachment keeps generated metadata', 'built-in WordPress attachment upload validator preserves the target attachment edit');

    $attachment_upload_audit = cow_merge_audit_report($attachment_upload_metadata, (int)$attachment_upload_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'semantic_scope' => 'wordpress',
        'conflict_type' => 'plugin-wp-attachment-upload-missing-file',
        'plugin_file' => 'wp-content/uploads/2026/05/generated-image-150x150.jpg',
    ]);
    assert_same(count($attachment_upload_audit['conflicts']), 1, 'built-in WordPress attachment upload validator exposes missing generated files as WordPress-scoped audit conflicts');
    $attachment_upload_preview = (string)($attachment_upload_audit['conflicts'][0]['chosen_preview'] ?? '');
    $attachment_upload_payload = cow_merge_audit_decode_payload(json_decode((string)($attachment_upload_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
    assert_true(str_contains($attachment_upload_preview, '"attachment_id":63'), 'built-in WordPress attachment upload audit includes the attachment ID');
    assert_same($attachment_upload_payload['candidate']['role'] ?? null, 'generated-size', 'built-in WordPress attachment upload audit identifies generated-size files');
    assert_same($attachment_upload_payload['candidate']['missing_file'] ?? null, 'wp-content/uploads/2026/05/generated-image-150x150.jpg', 'built-in WordPress attachment upload audit includes the missing upload path');
    assert_same($attachment_upload_audit['conflicts'][0]['plugin_files'] ?? null, ['wp-content/uploads/2026/05/generated-image-150x150.jpg'], 'built-in WordPress attachment upload audit exposes the missing generated file filter');

    $attachment_upload_invalid_path_audit = cow_merge_audit_report($attachment_upload_metadata, (int)$attachment_upload_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'semantic_scope' => 'wordpress',
        'conflict_type' => 'plugin-wp-attachment-upload-invalid-path',
    ]);
    assert_same(count($attachment_upload_invalid_path_audit['conflicts']), 1, 'built-in WordPress attachment upload validator rejects metadata paths that normalize outside uploads');
    $attachment_upload_invalid_path_payload = cow_merge_audit_decode_payload(json_decode((string)($attachment_upload_invalid_path_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
    assert_same($attachment_upload_invalid_path_payload['candidate']['role'] ?? null, 'generated-size', 'built-in WordPress attachment upload invalid-path audit identifies generated-size files');
    assert_same($attachment_upload_invalid_path_payload['candidate']['generated_file'] ?? null, '../../../../database/.ht.sqlite', 'built-in WordPress attachment upload invalid-path audit keeps the unsafe raw metadata path');
    assert_same($attachment_upload_invalid_path_audit['conflicts'][0]['plugin_files'] ?? null, [], 'built-in WordPress attachment upload invalid-path audit does not expose managed DB paths as plugin files');

    if ($has_attachment_upload_symlink) {
        $attachment_upload_invalid_entry_audit = cow_merge_audit_report($attachment_upload_metadata, (int)$attachment_upload_result['run_id'], 10, [
            'scope' => 'plugin',
            'records' => 'conflicts',
            'semantic_scope' => 'wordpress',
            'conflict_type' => 'plugin-wp-attachment-upload-invalid-entry',
            'plugin_file' => 'wp-content/uploads/2026/05/generated-image-symlink.jpg',
        ]);
        assert_same(count($attachment_upload_invalid_entry_audit['conflicts']), 1, 'built-in WordPress attachment upload validator rejects generated files that are symlinks');
        $attachment_upload_invalid_entry_payload = cow_merge_audit_decode_payload(json_decode((string)($attachment_upload_invalid_entry_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
        assert_same($attachment_upload_invalid_entry_payload['candidate']['entry_type'] ?? null, 'symlink', 'built-in WordPress attachment upload invalid-entry audit records symlink entry type');
        assert_same($attachment_upload_invalid_entry_payload['candidate']['invalid_file'] ?? null, 'wp-content/uploads/2026/05/generated-image-symlink.jpg', 'built-in WordPress attachment upload invalid-entry audit includes the symlink upload path');
        assert_same($attachment_upload_invalid_entry_audit['conflicts'][0]['plugin_files'] ?? null, ['wp-content/uploads/2026/05/generated-image-symlink.jpg'], 'built-in WordPress attachment upload invalid-entry audit exposes the symlink upload path filter');
    }

    $attachment_upload_duplicate_owner_audit = cow_merge_audit_report($attachment_upload_metadata, (int)$attachment_upload_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'semantic_scope' => 'wordpress',
        'conflict_type' => 'plugin-wp-attachment-upload-duplicate-owner',
        'plugin_file' => 'wp-content/uploads/2026/05/generated-image.jpg',
    ]);
    assert_same(count($attachment_upload_duplicate_owner_audit['conflicts']), 1, 'built-in WordPress attachment upload validator rejects duplicate upload ownership');
    $attachment_upload_duplicate_owner_payload = cow_merge_audit_decode_payload(json_decode((string)($attachment_upload_duplicate_owner_audit['conflicts'][0]['chosen_payload'] ?? ''), true));
    assert_same($attachment_upload_duplicate_owner_payload['candidate']['upload_file'] ?? null, 'wp-content/uploads/2026/05/generated-image.jpg', 'built-in WordPress attachment upload duplicate-owner audit includes the shared upload path');
    assert_same($attachment_upload_duplicate_owner_payload['candidate']['attachment_ids'] ?? null, [63, $duplicate_upload_attachment_id], 'built-in WordPress attachment upload duplicate-owner audit includes both attachment IDs');
    assert_same($attachment_upload_duplicate_owner_audit['conflicts'][0]['plugin_files'] ?? null, ['wp-content/uploads/2026/05/generated-image.jpg'], 'built-in WordPress attachment upload duplicate-owner audit exposes the shared upload path filter');

    $existing_attachment_upload_base_root = $tmp . '/existing-attachment-upload-base';
    $existing_attachment_upload_source_root = $tmp . '/existing-attachment-upload-source';
    $existing_attachment_upload_target_root = $tmp . '/existing-attachment-upload-target';
    $existing_attachment_upload_base = $existing_attachment_upload_base_root . '/wp-content/database/.ht.sqlite';
    $existing_attachment_upload_source = $existing_attachment_upload_source_root . '/wp-content/database/.ht.sqlite';
    $existing_attachment_upload_target = $existing_attachment_upload_target_root . '/wp-content/database/.ht.sqlite';
    $existing_attachment_upload_metadata = $tmp . '/.forkpress/cow/merge/wp-existing-attachment-upload-validator-metadata.sqlite';
    $existing_attachment_upload_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-existing-attachment-upload-validator.json';

    mkdir($existing_attachment_upload_base_root . '/wp-content/database', 0777, true);
    create_wp_attachment_upload_metadata_db($existing_attachment_upload_base);
    foreach ([
        'generated-image.jpg',
        'generated-image-300x200.jpg',
        'generated-image-original.jpg',
        'generated-image-backup.jpg',
    ] as $filename) {
        write_test_file($existing_attachment_upload_base_root . '/wp-content/uploads/2026/05/' . $filename, $filename . ' bytes');
    }
    copy_tree_for_test($existing_attachment_upload_base_root, $existing_attachment_upload_source_root);
    copy_tree_for_test($existing_attachment_upload_base_root, $existing_attachment_upload_target_root);
    cow_merge_capture_file_base($existing_attachment_upload_base_root, $existing_attachment_upload_file_base);
    cow_merge_allocate_autoincrement_bands($existing_attachment_upload_source, $existing_attachment_upload_metadata, 'feature-wp-existing-attachment-upload-source');
    cow_merge_allocate_autoincrement_bands($existing_attachment_upload_target, $existing_attachment_upload_metadata, 'main');

    $db = open_db($existing_attachment_upload_source);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_name, guid) VALUES ('Unrelated source page', '<!-- wp:paragraph --><p>Unrelated</p><!-- /wp:paragraph -->', 'publish', 'page', 'unrelated-source-page', '')");
    $db->close();

    $existing_attachment_upload_result = cow_merge_branch_state(
        $existing_attachment_upload_base,
        $existing_attachment_upload_source,
        $existing_attachment_upload_target,
        $existing_attachment_upload_metadata,
        'feature-wp-existing-attachment-upload-source',
        'main',
        $existing_attachment_upload_file_base,
        $existing_attachment_upload_source_root,
        $existing_attachment_upload_target_root
    );

    assert_same($existing_attachment_upload_result['status'], 'completed', 'built-in WordPress attachment upload validator ignores preexisting missing generated files');
    assert_same((int)($existing_attachment_upload_result['wordpress_semantic_validator_conflicts'] ?? 0), 0, 'built-in WordPress attachment upload validator only records newly introduced missing generated files');

    $image_block_base_root = $tmp . '/image-block-base';
    $image_block_source_root = $tmp . '/image-block-source';
    $image_block_target_root = $tmp . '/image-block-target';
    $image_block_base = $image_block_base_root . '/wp-content/database/.ht.sqlite';
    $image_block_source = $image_block_source_root . '/wp-content/database/.ht.sqlite';
    $image_block_target = $image_block_target_root . '/wp-content/database/.ht.sqlite';
    $image_block_metadata = $tmp . '/.forkpress/cow/merge/wp-image-block-validator-metadata.sqlite';
    $image_block_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-image-block-validator.json';

    mkdir($image_block_base_root . '/wp-content/database', 0777, true);
    create_wp_image_block_db($image_block_base);
    write_test_file($image_block_base_root . '/wp-content/uploads/2026/05/block-image.jpg', 'image block bytes');
    write_test_file($image_block_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT ID, post_content FROM wp_posts WHERE post_type NOT IN ('attachment', 'revision') AND post_content <> ''");
$findings = [];
$reported = [];
$record_missing_attachment = function (array $row, int $attachment_id, string $field, string $block_name, string $reason) use ($db, &$findings, &$reported): void {
    if ($attachment_id <= 0) {
        return;
    }
    $key = (string)$row['ID'] . ':' . (string)$attachment_id;
    if (isset($reported[$key])) {
        return;
    }
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'");
    if ($exists !== 0) {
        return;
    }
    $reported[$key] = true;
    $findings[] = [
        'plugin' => 'forkpress-wp-image-block-refs',
        'object' => 'post:' . $row['ID'],
        'reason' => $reason,
        'type' => 'plugin-wp-image-block-missing-attachment',
        'tables' => ['wp_posts'],
        'validator' => 'forkpress-wp-image-block-refs@1',
        'candidate' => [
            'post_id' => (int)$row['ID'],
            'block_name' => $block_name,
            'field' => $field,
            'missing_object_id' => $attachment_id,
            'object_type' => 'attachment',
        ],
    ];
};
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $content = (string)$row['post_content'];
    if (preg_match_all('/<!--\s*wp:image\s+(\{.*?\})\s*-->/', $content, $matches)) {
        foreach ($matches[1] as $raw_attrs) {
            $attrs = json_decode($raw_attrs, true);
            if (!is_array($attrs) || empty($attrs['id'])) {
                continue;
            }
            $record_missing_attachment($row, (int)$attrs['id'], 'attrs.id', 'core/image', 'image block references a missing attachment');
        }
    }
    if (preg_match_all('/\b(?:wp-image|wp-att|attachment)[_-](\d+)\b/i', $content, $class_matches)) {
        foreach ($class_matches[1] as $id) {
            $record_missing_attachment($row, (int)$id, 'class.wp-image', 'classic/image', 'classic image content references a missing attachment');
        }
    }
    if (preg_match_all('/\[gallery\b[^\]]*\bids\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))/i', $content, $gallery_matches, PREG_SET_ORDER)) {
        foreach ($gallery_matches as $match) {
            $ids = $match[1] ?? $match[2] ?? $match[3] ?? '';
            foreach (preg_split('/\s*,\s*/', (string)$ids, -1, PREG_SPLIT_NO_EMPTY) as $index => $id) {
                $record_missing_attachment($row, (int)$id, 'shortcode.gallery.ids.' . (string)$index, 'classic/gallery', 'classic gallery shortcode references a missing attachment');
            }
        }
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($image_block_base_root, $image_block_source_root);
    copy_tree_for_test($image_block_base_root, $image_block_target_root);
    cow_merge_capture_file_base($image_block_base_root, $image_block_file_base);
    cow_merge_allocate_autoincrement_bands($image_block_source, $image_block_metadata, 'feature-wp-image-block-source');
    cow_merge_allocate_autoincrement_bands($image_block_target, $image_block_metadata, 'main');

    $db = open_db($image_block_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 71');
    $db->close();
    unlink($image_block_source_root . '/wp-content/uploads/2026/05/block-image.jpg');

    $db = open_db($image_block_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted image block attachment' WHERE ID = 70");
    $db->exec("UPDATE wp_posts SET post_title = 'Target CPT still using deleted image block attachment' WHERE ID = 75");
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted classic image attachment' WHERE ID = 76");
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted classic gallery attachment' WHERE ID = 77");
    $db->close();

    $image_block_result = cow_merge_branch_state(
        $image_block_base,
        $image_block_source,
        $image_block_target,
        $image_block_metadata,
        'feature-wp-image-block-source',
        'main',
        $image_block_file_base,
        $image_block_source_root,
        $image_block_target_root
    );

    assert_same($image_block_result['status'], 'completed_with_conflicts', 'WordPress image block validator holds missing attachments for review');
    assert_same((int)($image_block_result['plugin_validators'] ?? 0), 1, 'WordPress image block validator is discovered from mu-plugins during merge');
    assert_same((int)($image_block_result['plugin_validator_conflicts'] ?? 0), 4, 'WordPress image block validator records missing attachment refs in block, classic image, and gallery content');
    assert_same((int)scalar($image_block_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 71'), 0, 'WordPress image block validator leaves the source attachment deletion staged for review');
    assert_true(!file_exists($image_block_target_root . '/wp-content/uploads/2026/05/block-image.jpg'), 'WordPress image block validator leaves the source upload deletion staged for review');
    assert_same(scalar($image_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 70'), 'Target page still using deleted image block attachment', 'WordPress image block validator preserves the target page edit');
    assert_same(scalar($image_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 75'), 'Target CPT still using deleted image block attachment', 'WordPress image block validator preserves the target custom post type edit');
    assert_same(scalar($image_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 76'), 'Target page still using deleted classic image attachment', 'WordPress image block validator preserves the target classic image edit');
    assert_same(scalar($image_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 77'), 'Target page still using deleted classic gallery attachment', 'WordPress image block validator preserves the target classic gallery edit');
    assert_true(str_contains((string)scalar($image_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 70'), '"id":71'), 'WordPress image block validator keeps the stale block attachment reference visible for review');
    assert_true(str_contains((string)scalar($image_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 75'), '"id":71'), 'WordPress image block validator keeps the stale custom post type block attachment reference visible for review');
    assert_true(str_contains((string)scalar($image_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 76'), 'wp-image-71'), 'WordPress image block validator keeps the stale classic image reference visible for review');
    assert_true(str_contains((string)scalar($image_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 77'), 'ids="71"'), 'WordPress image block validator keeps the stale classic gallery reference visible for review');

    $image_block_audit = cow_merge_audit_report($image_block_metadata, (int)$image_block_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-image-block-missing-attachment',
    ]);
    assert_same(count($image_block_audit['conflicts']), 4, 'WordPress image block validator exposes missing block and classic content attachments as plugin-scoped audit conflicts');
    $image_block_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $image_block_audit['conflicts']));
    assert_true(str_contains($image_block_preview, '"missing_object_id":71'), 'WordPress image block audit includes the missing attachment ID');
    assert_true(str_contains($image_block_preview, '"post_id":75'), 'WordPress image block audit includes the custom post type owner ID');
    assert_true(str_contains($image_block_preview, '"post_id":76'), 'WordPress image block audit includes the classic image owner ID');
    assert_true(str_contains($image_block_preview, '"post_id":77'), 'WordPress image block audit includes the classic gallery owner ID');
    assert_true(str_contains($image_block_preview, '"field":"class.wp-image"'), 'WordPress image block audit includes the classic wp-image field');
    assert_true(str_contains($image_block_preview, '"field":"shortcode.gallery.ids.0"'), 'WordPress image block audit includes the classic gallery shortcode field');
    assert_true(
        str_contains($image_block_preview, '"block_name":"core/image"') || str_contains($image_block_preview, '"block_name":"core\/image"'),
        'WordPress image block audit includes the block name'
    );

    $media_block_base_root = $tmp . '/media-block-base';
    $media_block_source_root = $tmp . '/media-block-source';
    $media_block_target_root = $tmp . '/media-block-target';
    $media_block_base = $media_block_base_root . '/wp-content/database/.ht.sqlite';
    $media_block_source = $media_block_source_root . '/wp-content/database/.ht.sqlite';
    $media_block_target = $media_block_target_root . '/wp-content/database/.ht.sqlite';
    $media_block_metadata = $tmp . '/.forkpress/cow/merge/wp-media-block-validator-metadata.sqlite';
    $media_block_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-media-block-validator.json';
    $media_block_files = [
        'block-audio.mp3',
        'block-cover.jpg',
        'block-file.pdf',
        'block-video.mp4',
        'block-media-text.jpg',
    ];

    mkdir($media_block_base_root . '/wp-content/database', 0777, true);
    create_wp_media_block_db($media_block_base);
    foreach ($media_block_files as $filename) {
        write_test_file($media_block_base_root . '/wp-content/uploads/2026/05/' . $filename, $filename . ' bytes');
    }
    write_test_file($media_block_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT ID, post_content FROM wp_posts WHERE post_type NOT IN ('attachment', 'revision') AND post_content <> ''");
$findings = [];
$media_id_blocks = ['audio', 'cover', 'file', 'video'];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    if (!preg_match_all('/<!--\s*wp:([A-Za-z0-9_\/-]+)\s+(\{.*?\})\s*-->/', (string)$row['post_content'], $matches, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($matches as $match) {
        $block_name = (string)$match[1];
        $attrs = json_decode((string)$match[2], true);
        if (!is_array($attrs)) {
            continue;
        }
        $field = null;
        $attachment_id = 0;
        if (in_array($block_name, $media_id_blocks, true) && isset($attrs['id'])) {
            $field = 'attrs.id';
            $attachment_id = (int)$attrs['id'];
        } elseif ($block_name === 'media-text' && isset($attrs['mediaId'])) {
            $field = 'attrs.mediaId';
            $attachment_id = (int)$attrs['mediaId'];
        }
        if ($field === null || $attachment_id <= 0) {
            continue;
        }
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'");
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-media-block-refs',
                'object' => 'post:' . $row['ID'],
                'reason' => 'media block references a missing attachment',
                'type' => 'plugin-wp-media-block-missing-attachment',
                'tables' => ['wp_posts'],
                'validator' => 'forkpress-wp-media-block-refs@1',
                'candidate' => [
                    'post_id' => (int)$row['ID'],
                    'block_name' => 'core/' . $block_name,
                    'field' => $field,
                    'missing_object_id' => $attachment_id,
                    'object_type' => 'attachment',
                ],
            ];
        }
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($media_block_base_root, $media_block_source_root);
    copy_tree_for_test($media_block_base_root, $media_block_target_root);
    cow_merge_capture_file_base($media_block_base_root, $media_block_file_base);
    cow_merge_allocate_autoincrement_bands($media_block_source, $media_block_metadata, 'feature-wp-media-block-source');
    cow_merge_allocate_autoincrement_bands($media_block_target, $media_block_metadata, 'main');

    $db = open_db($media_block_source);
    $db->exec('DELETE FROM wp_posts WHERE ID IN (182, 183, 184, 185, 186)');
    $db->close();
    foreach ($media_block_files as $filename) {
        unlink($media_block_source_root . '/wp-content/uploads/2026/05/' . $filename);
    }

    $db = open_db($media_block_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted media block attachments' WHERE ID = 181");
    $db->close();

    $media_block_result = cow_merge_branch_state(
        $media_block_base,
        $media_block_source,
        $media_block_target,
        $media_block_metadata,
        'feature-wp-media-block-source',
        'main',
        $media_block_file_base,
        $media_block_source_root,
        $media_block_target_root
    );

    assert_same($media_block_result['status'], 'completed_with_conflicts', 'WordPress media block validator holds missing media attachments for review');
    assert_same((int)($media_block_result['plugin_validators'] ?? 0), 1, 'WordPress media block validator is discovered from mu-plugins during merge');
    assert_same((int)($media_block_result['plugin_validator_conflicts'] ?? 0), 5, 'WordPress media block validator records missing audio, cover, file, video, and media-text attachments');
    assert_same((int)scalar($media_block_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID IN (182, 183, 184, 185, 186)'), 0, 'WordPress media block validator leaves source attachment deletions staged for review');
    foreach ($media_block_files as $filename) {
        assert_true(!file_exists($media_block_target_root . '/wp-content/uploads/2026/05/' . $filename), 'WordPress media block validator leaves deleted upload file staged for review: ' . $filename);
    }
    assert_same(scalar($media_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 181'), 'Target page still using deleted media block attachments', 'WordPress media block validator preserves the target page edit');
    $media_block_content = (string)scalar($media_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 181');
    assert_true(str_contains($media_block_content, '"id":182'), 'WordPress media block validator keeps the stale audio attachment visible for review');
    assert_true(str_contains($media_block_content, '"id":183'), 'WordPress media block validator keeps the stale cover attachment visible for review');
    assert_true(str_contains($media_block_content, '"id":184'), 'WordPress media block validator keeps the stale file attachment visible for review');
    assert_true(str_contains($media_block_content, '"id":185'), 'WordPress media block validator keeps the stale video attachment visible for review');
    assert_true(str_contains($media_block_content, '"mediaId":186'), 'WordPress media block validator keeps the stale media-text attachment visible for review');

    $media_block_audit = cow_merge_audit_report($media_block_metadata, (int)$media_block_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-block-missing-attachment',
    ]);
    assert_same(count($media_block_audit['conflicts']), 5, 'WordPress media block validator exposes media block refs as plugin-scoped audit conflicts');
    $media_block_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $media_block_audit['conflicts']));
    foreach ([182, 183, 184, 185, 186] as $attachment_id) {
        assert_true(str_contains($media_block_preview, '"missing_object_id":' . (string)$attachment_id), 'WordPress media block audit includes missing attachment ID ' . (string)$attachment_id);
    }
    foreach (['core/audio', 'core/cover', 'core/file', 'core/video', 'core/media-text'] as $block_name) {
        $encoded_block_name = str_replace('/', '\\/', $block_name);
        assert_true(
            str_contains($media_block_preview, '"block_name":"' . $block_name . '"') || str_contains($media_block_preview, '"block_name":"' . $encoded_block_name . '"'),
            'WordPress media block audit includes block name ' . $block_name
        );
    }
    assert_true(str_contains($media_block_preview, '"field":"attrs.id"'), 'WordPress media block audit includes generic media block ID fields');
    assert_true(str_contains($media_block_preview, '"field":"attrs.mediaId"'), 'WordPress media block audit includes media-text ID fields');

    $avatar_nav_base_root = $tmp . '/avatar-nav-block-base';
    $avatar_nav_source_root = $tmp . '/avatar-nav-block-source';
    $avatar_nav_target_root = $tmp . '/avatar-nav-block-target';
    $avatar_nav_base = $avatar_nav_base_root . '/wp-content/database/.ht.sqlite';
    $avatar_nav_source = $avatar_nav_source_root . '/wp-content/database/.ht.sqlite';
    $avatar_nav_target = $avatar_nav_target_root . '/wp-content/database/.ht.sqlite';
    $avatar_nav_metadata = $tmp . '/.forkpress/cow/merge/wp-avatar-nav-block-validator-metadata.sqlite';
    $avatar_nav_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-avatar-nav-block-validator.json';

    mkdir($avatar_nav_base_root . '/wp-content/database', 0777, true);
    create_wp_avatar_navigation_link_block_db($avatar_nav_base);
    write_test_file($avatar_nav_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT ID, post_content FROM wp_posts WHERE post_type NOT IN ('attachment', 'revision') AND post_content <> ''");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    if (!preg_match_all('/<!--\s*wp:([A-Za-z0-9_\/-]+)\s+(\{.*?\})\s*\/?-->/', (string)$row['post_content'], $matches, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($matches as $match) {
        $block_name = (string)$match[1];
        $attrs = json_decode((string)$match[2], true);
        if (!is_array($attrs)) {
            continue;
        }
        if ($block_name === 'avatar' && isset($attrs['userId'])) {
            $user_id = (int)$attrs['userId'];
            $exists = $user_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_users WHERE ID = $user_id");
            if ($exists === 0) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-avatar-navigation-link-refs',
                    'object' => 'post:' . $row['ID'],
                    'reason' => 'avatar block references a missing user',
                    'type' => 'plugin-wp-avatar-navigation-link-missing-object',
                    'tables' => ['wp_posts', 'wp_users'],
                    'validator' => 'forkpress-wp-avatar-navigation-link-refs@1',
                    'candidate' => [
                        'post_id' => (int)$row['ID'],
                        'block_name' => 'core/avatar',
                        'field' => 'attrs.userId',
                        'missing_object_id' => $user_id,
                        'object_type' => 'user',
                    ],
                ];
            }
        }
        if (!in_array($block_name, ['navigation-link', 'navigation-submenu'], true) || !isset($attrs['id'])) {
            continue;
        }
        $core_block_name = 'core/' . $block_name;
        $block_label = $block_name === 'navigation-submenu' ? 'navigation submenu block' : 'navigation link block';
        $object_id = (int)$attrs['id'];
        if (($attrs['kind'] ?? null) === 'post-type') {
            $exists = $object_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $object_id");
            if ($exists === 0) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-avatar-navigation-link-refs',
                    'object' => 'post:' . $row['ID'],
                    'reason' => $block_label . ' references a missing post object',
                    'type' => 'plugin-wp-avatar-navigation-link-missing-object',
                    'tables' => ['wp_posts'],
                    'validator' => 'forkpress-wp-avatar-navigation-link-refs@1',
                    'candidate' => [
                        'post_id' => (int)$row['ID'],
                        'block_name' => $core_block_name,
                        'field' => 'attrs.id',
                        'missing_object_id' => $object_id,
                        'object_type' => (string)($attrs['type'] ?? 'post'),
                        'kind' => 'post-type',
                    ],
                ];
            }
        }
        if (($attrs['kind'] ?? null) === 'taxonomy') {
            $taxonomy = (string)($attrs['type'] ?? '');
            $escaped_taxonomy = SQLite3::escapeString($taxonomy);
            $exists = $object_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = '$escaped_taxonomy' WHERE t.term_id = $object_id");
            if ($exists === 0) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-avatar-navigation-link-refs',
                    'object' => 'post:' . $row['ID'],
                    'reason' => $block_label . ' references a missing taxonomy term',
                    'type' => 'plugin-wp-avatar-navigation-link-missing-object',
                    'tables' => ['wp_posts', 'wp_terms', 'wp_term_taxonomy'],
                    'validator' => 'forkpress-wp-avatar-navigation-link-refs@1',
                    'candidate' => [
                        'post_id' => (int)$row['ID'],
                        'block_name' => $core_block_name,
                        'field' => 'attrs.id',
                        'missing_object_id' => $object_id,
                        'object_type' => $taxonomy,
                        'kind' => 'taxonomy',
                    ],
                ];
            }
        }
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($avatar_nav_base_root, $avatar_nav_source_root);
    copy_tree_for_test($avatar_nav_base_root, $avatar_nav_target_root);
    cow_merge_capture_file_base($avatar_nav_base_root, $avatar_nav_file_base);
    cow_merge_allocate_autoincrement_bands($avatar_nav_source, $avatar_nav_metadata, 'feature-wp-avatar-nav-block-source');
    cow_merge_allocate_autoincrement_bands($avatar_nav_target, $avatar_nav_metadata, 'main');

    $db = open_db($avatar_nav_source);
    $db->exec('DELETE FROM wp_users WHERE ID = 188');
    $db->exec('DELETE FROM wp_posts WHERE ID = 189');
    $db->exec('DELETE FROM wp_posts WHERE ID = 191');
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_id = 190');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 190');
    $db->close();

    $db = open_db($avatar_nav_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted avatar and navigation links' WHERE ID = 187");
    $db->close();

    $avatar_nav_result = cow_merge_branch_state(
        $avatar_nav_base,
        $avatar_nav_source,
        $avatar_nav_target,
        $avatar_nav_metadata,
        'feature-wp-avatar-nav-block-source',
        'main',
        $avatar_nav_file_base,
        $avatar_nav_source_root,
        $avatar_nav_target_root
    );

    assert_same($avatar_nav_result['status'], 'completed_with_conflicts', 'WordPress avatar/navigation-link block validator holds missing objects for review');
    assert_same((int)($avatar_nav_result['plugin_validators'] ?? 0), 1, 'WordPress avatar/navigation-link block validator is discovered from mu-plugins during merge');
    assert_same((int)($avatar_nav_result['plugin_validator_conflicts'] ?? 0), 4, 'WordPress avatar/navigation-link block validator records missing user, page, submenu page, and taxonomy refs');
    assert_same((int)scalar($avatar_nav_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 188'), 0, 'WordPress avatar/navigation-link block validator leaves source user deletion staged for review');
    assert_same((int)scalar($avatar_nav_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 189'), 0, 'WordPress avatar/navigation-link block validator leaves source page deletion staged for review');
    assert_same((int)scalar($avatar_nav_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 191'), 0, 'WordPress avatar/navigation-link block validator leaves source submenu page deletion staged for review');
    assert_same((int)scalar($avatar_nav_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 190'), 0, 'WordPress avatar/navigation-link block validator leaves source term deletion staged for review');
    assert_same(scalar($avatar_nav_target, 'SELECT post_title FROM wp_posts WHERE ID = 187'), 'Target page still using deleted avatar and navigation links', 'WordPress avatar/navigation-link block validator preserves the target page edit');
    $avatar_nav_content = (string)scalar($avatar_nav_target, 'SELECT post_content FROM wp_posts WHERE ID = 187');
    assert_true(str_contains($avatar_nav_content, '"userId":188'), 'WordPress avatar/navigation-link block validator keeps the stale avatar user visible for review');
    assert_true(str_contains($avatar_nav_content, '"id":189'), 'WordPress avatar/navigation-link block validator keeps the stale navigation page visible for review');
    assert_true(str_contains($avatar_nav_content, '"id":190'), 'WordPress avatar/navigation-link block validator keeps the stale navigation taxonomy visible for review');
    assert_true(str_contains($avatar_nav_content, '"id":191'), 'WordPress avatar/navigation-link block validator keeps the stale navigation submenu page visible for review');

    $avatar_nav_audit = cow_merge_audit_report($avatar_nav_metadata, (int)$avatar_nav_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-avatar-navigation-link-missing-object',
    ]);
    assert_same(count($avatar_nav_audit['conflicts']), 4, 'WordPress avatar/navigation-link block validator exposes stale refs as plugin-scoped audit conflicts');
    $avatar_nav_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $avatar_nav_audit['conflicts']));
    foreach ([188, 189, 190, 191] as $missing_id) {
        assert_true(str_contains($avatar_nav_preview, '"missing_object_id":' . (string)$missing_id), 'WordPress avatar/navigation-link block audit includes missing object ID ' . (string)$missing_id);
    }
    foreach (['core/avatar', 'core/navigation-link', 'core/navigation-submenu'] as $block_name) {
        $encoded_block_name = str_replace('/', '\\/', $block_name);
        assert_true(
            str_contains($avatar_nav_preview, '"block_name":"' . $block_name . '"') || str_contains($avatar_nav_preview, '"block_name":"' . $encoded_block_name . '"'),
            'WordPress avatar/navigation-link block audit includes block name ' . $block_name
        );
    }
    foreach (['"field":"attrs.userId"', '"field":"attrs.id"', '"kind":"post-type"', '"kind":"taxonomy"'] as $needle) {
        assert_true(str_contains($avatar_nav_preview, $needle), 'WordPress avatar/navigation-link block audit includes ' . $needle);
    }

    $gallery_block_base_root = $tmp . '/gallery-block-base';
    $gallery_block_source_root = $tmp . '/gallery-block-source';
    $gallery_block_target_root = $tmp . '/gallery-block-target';
    $gallery_block_base = $gallery_block_base_root . '/wp-content/database/.ht.sqlite';
    $gallery_block_source = $gallery_block_source_root . '/wp-content/database/.ht.sqlite';
    $gallery_block_target = $gallery_block_target_root . '/wp-content/database/.ht.sqlite';
    $gallery_block_metadata = $tmp . '/.forkpress/cow/merge/wp-gallery-block-validator-metadata.sqlite';
    $gallery_block_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-gallery-block-validator.json';

    mkdir($gallery_block_base_root . '/wp-content/database', 0777, true);
    create_wp_gallery_block_db($gallery_block_base);
    write_test_file($gallery_block_base_root . '/wp-content/uploads/2026/05/gallery-deleted.jpg', 'deleted gallery image bytes');
    write_test_file($gallery_block_base_root . '/wp-content/uploads/2026/05/gallery-kept.jpg', 'kept gallery image bytes');
    write_test_file($gallery_block_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT ID, post_content FROM wp_posts WHERE post_type NOT IN ('attachment', 'revision') AND post_content <> ''");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    if (!preg_match_all('/<!--\s*wp:gallery\s+(\{.*?\})\s*-->/', (string)$row['post_content'], $matches)) {
        continue;
    }
    foreach ($matches[1] as $raw_attrs) {
        $attrs = json_decode($raw_attrs, true);
        if (!is_array($attrs) || !isset($attrs['ids']) || !is_array($attrs['ids'])) {
            continue;
        }
        foreach ($attrs['ids'] as $index => $id) {
            $attachment_id = (int)$id;
            if ($attachment_id <= 0) {
                continue;
            }
            $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'");
            if ($exists === 0) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-gallery-block-refs',
                    'object' => 'post:' . $row['ID'],
                    'reason' => 'gallery block references a missing attachment',
                    'type' => 'plugin-wp-gallery-block-missing-attachment',
                    'tables' => ['wp_posts'],
                    'validator' => 'forkpress-wp-gallery-block-refs@1',
                    'candidate' => [
                        'post_id' => (int)$row['ID'],
                        'block_name' => 'core/gallery',
                        'field' => 'attrs.ids.' . (string)$index,
                        'missing_object_id' => $attachment_id,
                        'object_type' => 'attachment',
                    ],
                ];
            }
        }
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($gallery_block_base_root, $gallery_block_source_root);
    copy_tree_for_test($gallery_block_base_root, $gallery_block_target_root);
    cow_merge_capture_file_base($gallery_block_base_root, $gallery_block_file_base);
    cow_merge_allocate_autoincrement_bands($gallery_block_source, $gallery_block_metadata, 'feature-wp-gallery-block-source');
    cow_merge_allocate_autoincrement_bands($gallery_block_target, $gallery_block_metadata, 'main');

    $db = open_db($gallery_block_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 73');
    $db->close();
    unlink($gallery_block_source_root . '/wp-content/uploads/2026/05/gallery-deleted.jpg');

    $db = open_db($gallery_block_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted gallery attachment' WHERE ID = 72");
    $db->close();

    $gallery_block_result = cow_merge_branch_state(
        $gallery_block_base,
        $gallery_block_source,
        $gallery_block_target,
        $gallery_block_metadata,
        'feature-wp-gallery-block-source',
        'main',
        $gallery_block_file_base,
        $gallery_block_source_root,
        $gallery_block_target_root
    );

    assert_same($gallery_block_result['status'], 'completed_with_conflicts', 'WordPress gallery block validator holds missing attachments for review');
    assert_same((int)($gallery_block_result['plugin_validators'] ?? 0), 1, 'WordPress gallery block validator is discovered from mu-plugins during merge');
    assert_same((int)($gallery_block_result['plugin_validator_conflicts'] ?? 0), 1, 'WordPress gallery block validator records the missing attachment');
    assert_same((int)scalar($gallery_block_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 73'), 0, 'WordPress gallery block validator leaves the source attachment deletion staged for review');
    assert_same((int)scalar($gallery_block_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 74'), 1, 'WordPress gallery block validator preserves unrelated gallery attachments');
    assert_true(!file_exists($gallery_block_target_root . '/wp-content/uploads/2026/05/gallery-deleted.jpg'), 'WordPress gallery block validator leaves the source upload deletion staged for review');
    assert_true(is_file($gallery_block_target_root . '/wp-content/uploads/2026/05/gallery-kept.jpg'), 'WordPress gallery block validator preserves unrelated gallery upload files');
    assert_same(scalar($gallery_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 72'), 'Target page still using deleted gallery attachment', 'WordPress gallery block validator preserves the target page edit');
    assert_true(str_contains((string)scalar($gallery_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 72'), '"ids":[73,74]'), 'WordPress gallery block validator keeps the stale gallery IDs visible for review');

    $gallery_block_audit = cow_merge_audit_report($gallery_block_metadata, (int)$gallery_block_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-gallery-block-missing-attachment',
    ]);
    assert_same(count($gallery_block_audit['conflicts']), 1, 'WordPress gallery block validator exposes the missing attachment as a plugin-scoped audit conflict');
    $gallery_block_preview = (string)($gallery_block_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($gallery_block_preview, '"missing_object_id":73'), 'WordPress gallery block audit includes the missing attachment ID');
    assert_true(str_contains($gallery_block_preview, '"field":"attrs.ids.0"'), 'WordPress gallery block audit includes the stale gallery ID field');
    assert_true(
        str_contains($gallery_block_preview, '"block_name":"core/gallery"') || str_contains($gallery_block_preview, '"block_name":"core\/gallery"'),
        'WordPress gallery block audit includes the block name'
    );

    $query_block_base_root = $tmp . '/query-block-base';
    $query_block_source_root = $tmp . '/query-block-source';
    $query_block_target_root = $tmp . '/query-block-target';
    $query_block_base = $query_block_base_root . '/wp-content/database/.ht.sqlite';
    $query_block_source = $query_block_source_root . '/wp-content/database/.ht.sqlite';
    $query_block_target = $query_block_target_root . '/wp-content/database/.ht.sqlite';
    $query_block_metadata = $tmp . '/.forkpress/cow/merge/wp-query-block-validator-metadata.sqlite';
    $query_block_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-query-block-validator.json';

    mkdir($query_block_base_root . '/wp-content/database', 0777, true);
    create_wp_query_block_db($query_block_base);
    write_test_file($query_block_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT ID, post_content FROM wp_posts WHERE post_type NOT IN ('attachment', 'revision') AND post_content <> ''");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    preg_match_all('/<!--\s*wp:query\s+(\{.*?\})\s*-->/', (string)$row['post_content'], $query_matches);
    foreach ($query_matches[1] as $raw_attrs) {
        $attrs = json_decode($raw_attrs, true);
        $query = is_array($attrs) && isset($attrs['query']) && is_array($attrs['query']) ? $attrs['query'] : [];
        if (isset($query['author'])) {
            $author_id = (int)$query['author'];
            $exists = $author_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_users WHERE ID = $author_id");
            if ($exists === 0) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-query-block-refs',
                    'object' => 'post:' . $row['ID'],
                    'reason' => 'query block references a missing author user',
                    'type' => 'plugin-wp-query-block-missing-object',
                    'tables' => ['wp_posts', 'wp_users'],
                    'validator' => 'forkpress-wp-query-block-refs@1',
                    'candidate' => [
                        'post_id' => (int)$row['ID'],
                        'block_name' => 'core/query',
                        'field' => 'query.author',
                        'missing_object_id' => $author_id,
                        'object_type' => 'user',
                    ],
                ];
            }
        }
        foreach (['categoryIds' => 'category', 'tagIds' => 'post_tag'] as $field => $taxonomy) {
            if (!isset($query[$field]) || !is_array($query[$field])) {
                continue;
            }
            foreach ($query[$field] as $index => $term_id) {
                $term_id = (int)$term_id;
                if ($term_id <= 0) {
                    continue;
                }
                $escaped_taxonomy = SQLite3::escapeString($taxonomy);
                $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = '$escaped_taxonomy' WHERE t.term_id = $term_id");
                if ($exists === 0) {
                    $findings[] = [
                        'plugin' => 'forkpress-wp-query-block-refs',
                        'object' => 'post:' . $row['ID'],
                        'reason' => 'query block references a missing taxonomy term',
                        'type' => 'plugin-wp-query-block-missing-object',
                        'tables' => ['wp_posts', 'wp_terms', 'wp_term_taxonomy'],
                        'validator' => 'forkpress-wp-query-block-refs@1',
                        'candidate' => [
                            'post_id' => (int)$row['ID'],
                            'block_name' => 'core/query',
                            'field' => 'query.' . $field . '.' . (string)$index,
                            'missing_object_id' => $term_id,
                            'object_type' => 'term',
                            'taxonomy' => $taxonomy,
                        ],
                    ];
                }
            }
        }
        if (isset($query['taxQuery']) && is_array($query['taxQuery'])) {
            foreach ($query['taxQuery'] as $taxonomy => $term_ids) {
                if (!is_array($term_ids)) {
                    continue;
                }
                foreach ($term_ids as $index => $term_id) {
                    $term_id = (int)$term_id;
                    if ($term_id <= 0) {
                        continue;
                    }
                    $escaped_taxonomy = SQLite3::escapeString((string)$taxonomy);
                    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = '$escaped_taxonomy' WHERE t.term_id = $term_id");
                    if ($exists === 0) {
                        $findings[] = [
                            'plugin' => 'forkpress-wp-query-block-refs',
                            'object' => 'post:' . $row['ID'],
                            'reason' => 'query block references a missing taxonomy term',
                            'type' => 'plugin-wp-query-block-missing-object',
                            'tables' => ['wp_posts', 'wp_terms', 'wp_term_taxonomy'],
                            'validator' => 'forkpress-wp-query-block-refs@1',
                            'candidate' => [
                                'post_id' => (int)$row['ID'],
                                'block_name' => 'core/query',
                                'field' => 'query.taxQuery.' . (string)$taxonomy . '.' . (string)$index,
                                'missing_object_id' => $term_id,
                                'object_type' => 'term',
                                'taxonomy' => (string)$taxonomy,
                            ],
                        ];
                    }
                }
            }
        }
    }
    preg_match_all('/<!--\s*wp:latest-posts\s+(\{.*?\})\s*\/?-->/', (string)$row['post_content'], $latest_posts_matches);
    foreach ($latest_posts_matches[1] as $raw_attrs) {
        $attrs = json_decode($raw_attrs, true);
        if (!is_array($attrs)) {
            continue;
        }
        if (isset($attrs['selectedAuthor'])) {
            $author_id = (int)$attrs['selectedAuthor'];
            $exists = $author_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_users WHERE ID = $author_id");
            if ($exists === 0) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-query-block-refs',
                    'object' => 'post:' . $row['ID'],
                    'reason' => 'latest posts block references a missing author user',
                    'type' => 'plugin-wp-query-block-missing-object',
                    'tables' => ['wp_posts', 'wp_users'],
                    'validator' => 'forkpress-wp-query-block-refs@1',
                    'candidate' => [
                        'post_id' => (int)$row['ID'],
                        'block_name' => 'core/latest-posts',
                        'field' => 'selectedAuthor',
                        'missing_object_id' => $author_id,
                        'object_type' => 'user',
                    ],
                ];
            }
        }
        if (isset($attrs['categories']) && is_array($attrs['categories'])) {
            foreach ($attrs['categories'] as $index => $term_id) {
                $term_id = (int)$term_id;
                if ($term_id <= 0) {
                    continue;
                }
                $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = 'category' WHERE t.term_id = $term_id");
                if ($exists === 0) {
                    $findings[] = [
                        'plugin' => 'forkpress-wp-query-block-refs',
                        'object' => 'post:' . $row['ID'],
                        'reason' => 'latest posts block references a missing category term',
                        'type' => 'plugin-wp-query-block-missing-object',
                        'tables' => ['wp_posts', 'wp_terms', 'wp_term_taxonomy'],
                        'validator' => 'forkpress-wp-query-block-refs@1',
                        'candidate' => [
                            'post_id' => (int)$row['ID'],
                            'block_name' => 'core/latest-posts',
                            'field' => 'categories.' . (string)$index,
                            'missing_object_id' => $term_id,
                            'object_type' => 'term',
                            'taxonomy' => 'category',
                        ],
                    ];
                }
            }
        }
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($query_block_base_root, $query_block_source_root);
    copy_tree_for_test($query_block_base_root, $query_block_target_root);
    cow_merge_capture_file_base($query_block_base_root, $query_block_file_base);
    cow_merge_allocate_autoincrement_bands($query_block_source, $query_block_metadata, 'feature-wp-query-block-source');
    cow_merge_allocate_autoincrement_bands($query_block_target, $query_block_metadata, 'main');

    $db = open_db($query_block_source);
    $db->exec('DELETE FROM wp_users WHERE ID = 75');
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_id IN (76, 78, 83, 85)');
    $db->exec('DELETE FROM wp_terms WHERE term_id IN (76, 78, 83, 85)');
    $db->close();

    $db = open_db($query_block_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted query refs' WHERE ID = 79");
    $db->close();

    $query_block_result = cow_merge_branch_state(
        $query_block_base,
        $query_block_source,
        $query_block_target,
        $query_block_metadata,
        'feature-wp-query-block-source',
        'main',
        $query_block_file_base,
        $query_block_source_root,
        $query_block_target_root
    );

    assert_same($query_block_result['status'], 'completed_with_conflicts', 'WordPress query block validator holds missing query refs for review');
    assert_same((int)($query_block_result['plugin_validators'] ?? 0), 1, 'WordPress query block validator is discovered from mu-plugins during merge');
    assert_same((int)($query_block_result['plugin_validator_conflicts'] ?? 0), 7, 'WordPress query block validator records missing query and latest-posts refs');
    assert_same((int)scalar($query_block_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 75'), 0, 'WordPress query block validator leaves the source author deletion staged for review');
    assert_same((int)scalar($query_block_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id IN (76, 78, 83, 85)'), 0, 'WordPress query block validator leaves the source term deletions staged for review');
    assert_same(scalar($query_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 79'), 'Target page still using deleted query refs', 'WordPress query block validator preserves the target page edit');
    $query_block_content = (string)scalar($query_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 79');
    assert_true(str_contains($query_block_content, '"author":75'), 'WordPress query block validator keeps the stale query author visible for review');
    assert_true(str_contains($query_block_content, '"categoryIds":[76]'), 'WordPress query block validator keeps the stale query category visible for review');
    assert_true(str_contains($query_block_content, '"tagIds":[78]'), 'WordPress query block validator keeps the stale query tag visible for review');
    assert_true(str_contains($query_block_content, '"taxQuery":{"category":[83],"post_tag":[85]}'), 'WordPress query block validator keeps stale taxQuery terms visible for review');
    assert_true(str_contains($query_block_content, '"selectedAuthor":75'), 'WordPress query block validator keeps the stale latest posts author visible for review');
    assert_true(str_contains($query_block_content, '"categories":[83]'), 'WordPress query block validator keeps the stale latest posts category visible for review');

    $query_block_audit = cow_merge_audit_report($query_block_metadata, (int)$query_block_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-query-block-missing-object',
    ]);
    assert_same(count($query_block_audit['conflicts']), 7, 'WordPress query block validator exposes missing query and latest-posts refs as plugin-scoped audit conflicts');
    $query_block_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $query_block_audit['conflicts']));
    assert_true(str_contains($query_block_preview, '"missing_object_id":75'), 'WordPress query block audit includes the missing author ID');
    assert_true(str_contains($query_block_preview, '"missing_object_id":76'), 'WordPress query block audit includes the missing category ID');
    assert_true(str_contains($query_block_preview, '"missing_object_id":78'), 'WordPress query block audit includes the missing tag ID');
    assert_true(str_contains($query_block_preview, '"missing_object_id":83'), 'WordPress query block audit includes the missing taxQuery category ID');
    assert_true(str_contains($query_block_preview, '"missing_object_id":85'), 'WordPress query block audit includes the missing taxQuery tag ID');
    assert_true(str_contains($query_block_preview, '"field":"query.author"'), 'WordPress query block audit includes the stale author field');
    assert_true(str_contains($query_block_preview, '"field":"query.categoryIds.0"'), 'WordPress query block audit includes the stale category field');
    assert_true(str_contains($query_block_preview, '"field":"query.tagIds.0"'), 'WordPress query block audit includes the stale tag field');
    assert_true(str_contains($query_block_preview, '"field":"query.taxQuery.category.0"'), 'WordPress query block audit includes the stale taxQuery category field');
    assert_true(str_contains($query_block_preview, '"field":"query.taxQuery.post_tag.0"'), 'WordPress query block audit includes the stale taxQuery tag field');
    assert_true(str_contains($query_block_preview, '"field":"selectedAuthor"'), 'WordPress query block audit includes the stale latest-posts author field');
    assert_true(str_contains($query_block_preview, '"field":"categories.0"'), 'WordPress query block audit includes the stale latest-posts category field');
    assert_true(
        str_contains($query_block_preview, '"block_name":"core/query"') || str_contains($query_block_preview, '"block_name":"core\/query"'),
        'WordPress query block audit includes the block name'
    );
    assert_true(
        str_contains($query_block_preview, '"block_name":"core/latest-posts"') || str_contains($query_block_preview, '"block_name":"core\/latest-posts"'),
        'WordPress query block audit includes the latest posts block name'
    );

    $term_base_root = $tmp . '/term-ref-base';
    $term_source_root = $tmp . '/term-ref-source';
    $term_target_root = $tmp . '/term-ref-target';
    $term_base = $term_base_root . '/wp-content/database/.ht.sqlite';
    $term_source = $term_source_root . '/wp-content/database/.ht.sqlite';
    $term_target = $term_target_root . '/wp-content/database/.ht.sqlite';
    $term_metadata = $tmp . '/.forkpress/cow/merge/wp-term-ref-validator-metadata.sqlite';
    $term_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-term-ref-validator.json';

    mkdir($term_base_root . '/wp-content/database', 0777, true);
    create_wp_term_relationship_db($term_base);
    write_test_file($term_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT object_id, term_taxonomy_id FROM wp_term_relationships");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $object_id = (int)$row['object_id'];
    $term_taxonomy_id = (int)$row['term_taxonomy_id'];
    $taxonomy = $db->querySingle("SELECT taxonomy FROM wp_term_taxonomy WHERE term_taxonomy_id = $term_taxonomy_id");
    $term_id = $db->querySingle("SELECT term_id FROM wp_term_taxonomy WHERE term_taxonomy_id = $term_taxonomy_id");
    $term_exists = $term_id === null ? 0 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms WHERE term_id = " . (int)$term_id);
    if ($taxonomy === null || $term_exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-term-refs',
            'object' => 'term_relationship:' . $object_id . ':' . $term_taxonomy_id,
            'reason' => 'term relationship references a missing taxonomy term',
            'type' => 'plugin-wp-term-relationship-missing-term',
            'tables' => ['wp_term_relationships', 'wp_term_taxonomy', 'wp_terms'],
            'validator' => 'forkpress-wp-term-refs@1',
            'candidate' => [
                'object_id' => $object_id,
                'term_taxonomy_id' => $term_taxonomy_id,
                'taxonomy' => $taxonomy,
                'missing_object_id' => $term_taxonomy_id,
                'object_type' => 'term_taxonomy',
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($term_base_root, $term_source_root);
    copy_tree_for_test($term_base_root, $term_target_root);
    cow_merge_capture_file_base($term_base_root, $term_file_base);
    cow_merge_allocate_autoincrement_bands($term_source, $term_metadata, 'feature-wp-term-ref-source');
    cow_merge_allocate_autoincrement_bands($term_target, $term_metadata, 'main');

    $db = open_db($term_source);
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_taxonomy_id = 82');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 81');
    $db->close();

    $db = open_db($term_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page assigned to deleted term' WHERE ID = 80");
    $db->exec('INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (80, 82)');
    $db->close();

    $term_result = cow_merge_branch_state(
        $term_base,
        $term_source,
        $term_target,
        $term_metadata,
        'feature-wp-term-ref-source',
        'main',
        $term_file_base,
        $term_source_root,
        $term_target_root
    );

    assert_same($term_result['status'], 'completed_with_conflicts', 'WordPress term relationship validator holds missing taxonomy terms for review');
    assert_same((int)($term_result['plugin_validators'] ?? 0), 1, 'WordPress term relationship validator is discovered from mu-plugins during merge');
    assert_same((int)($term_result['plugin_validator_conflicts'] ?? 0), 1, 'WordPress term relationship validator records the missing taxonomy term');
    assert_same((int)scalar($term_target, 'SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_taxonomy_id = 82'), 0, 'WordPress term relationship validator leaves the source taxonomy deletion staged for review');
    assert_same((int)scalar($term_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 81'), 0, 'WordPress term relationship validator leaves the source term deletion staged for review');
    assert_same((int)scalar($term_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 80 AND term_taxonomy_id = 82'), 1, 'WordPress term relationship validator preserves the target relationship assignment for review');
    assert_same(scalar($term_target, 'SELECT post_title FROM wp_posts WHERE ID = 80'), 'Target page assigned to deleted term', 'WordPress term relationship validator preserves the target page edit');

    $term_audit = cow_merge_audit_report($term_metadata, (int)$term_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-term-relationship-missing-term',
    ]);
    assert_same(count($term_audit['conflicts']), 1, 'WordPress term relationship validator exposes the missing taxonomy term as a plugin-scoped audit conflict');
    $term_preview = (string)($term_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($term_preview, '"term_taxonomy_id":82'), 'WordPress term relationship audit includes the missing term taxonomy ID');

    $term_taxonomy_base_root = $tmp . '/term-taxonomy-base';
    $term_taxonomy_source_root = $tmp . '/term-taxonomy-source';
    $term_taxonomy_target_root = $tmp . '/term-taxonomy-target';
    $term_taxonomy_base = $term_taxonomy_base_root . '/wp-content/database/.ht.sqlite';
    $term_taxonomy_source = $term_taxonomy_source_root . '/wp-content/database/.ht.sqlite';
    $term_taxonomy_target = $term_taxonomy_target_root . '/wp-content/database/.ht.sqlite';
    $term_taxonomy_metadata = $tmp . '/.forkpress/cow/merge/wp-term-taxonomy-validator-metadata.sqlite';
    $term_taxonomy_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-term-taxonomy-validator.json';

    mkdir($term_taxonomy_base_root . '/wp-content/database', 0777, true);
    create_wp_term_taxonomy_reference_db($term_taxonomy_base);
    write_test_file($term_taxonomy_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT term_taxonomy_id, term_id, taxonomy FROM wp_term_taxonomy WHERE term_id > 0");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $term_id = (int)$row['term_id'];
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms WHERE term_id = $term_id");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-term-taxonomy-refs',
            'object' => 'term_taxonomy:' . $row['term_taxonomy_id'],
            'reason' => 'term taxonomy references a missing term',
            'type' => 'plugin-wp-term-taxonomy-missing-term',
            'tables' => ['wp_term_taxonomy', 'wp_terms'],
            'validator' => 'forkpress-wp-term-taxonomy-refs@1',
            'candidate' => [
                'term_taxonomy_id' => (int)$row['term_taxonomy_id'],
                'taxonomy' => (string)$row['taxonomy'],
                'field' => 'term_id',
                'missing_term_id' => $term_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($term_taxonomy_base_root, $term_taxonomy_source_root);
    copy_tree_for_test($term_taxonomy_base_root, $term_taxonomy_target_root);
    cow_merge_capture_file_base($term_taxonomy_base_root, $term_taxonomy_file_base);
    cow_merge_allocate_autoincrement_bands($term_taxonomy_source, $term_taxonomy_metadata, 'feature-wp-term-taxonomy-source');
    cow_merge_allocate_autoincrement_bands($term_taxonomy_target, $term_taxonomy_metadata, 'main');

    $db = open_db($term_taxonomy_source);
    $db->exec('DELETE FROM wp_terms WHERE term_id = 89');
    $db->close();

    $db = open_db($term_taxonomy_target);
    $db->exec("UPDATE wp_term_taxonomy SET description = 'Target category taxonomy still pointing at deleted term' WHERE term_taxonomy_id = 90");
    $db->exec("UPDATE wp_term_taxonomy SET description = 'Target tag taxonomy still pointing at deleted term' WHERE term_taxonomy_id = 91");
    $db->close();

    $term_taxonomy_result = cow_merge_branch_state(
        $term_taxonomy_base,
        $term_taxonomy_source,
        $term_taxonomy_target,
        $term_taxonomy_metadata,
        'feature-wp-term-taxonomy-source',
        'main',
        $term_taxonomy_file_base,
        $term_taxonomy_source_root,
        $term_taxonomy_target_root
    );

    assert_same($term_taxonomy_result['status'], 'completed_with_conflicts', 'WordPress term-taxonomy owner delete with target-edited taxonomies stays reviewable');
    assert_same((int)($term_taxonomy_result['plugin_validators'] ?? 0), 1, 'WordPress term-taxonomy validator is discovered from mu-plugins during merge');
    assert_same((int)($term_taxonomy_result['plugin_validator_conflicts'] ?? 0), 0, 'WordPress term-taxonomy owner delete guard prevents missing-owner validator fallout');
    assert_same((int)scalar($term_taxonomy_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 89'), 1, 'WordPress term-taxonomy owner delete guard keeps the parent term before review');
    assert_same(scalar($term_taxonomy_target, 'SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id = 90'), 'Target category taxonomy still pointing at deleted term', 'WordPress term-taxonomy validator preserves the target category taxonomy edit');
    assert_same(scalar($term_taxonomy_target, 'SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id = 91'), 'Target tag taxonomy still pointing at deleted term', 'WordPress term-taxonomy validator preserves the target tag taxonomy edit');

    $term_taxonomy_audit = cow_merge_audit_report($term_taxonomy_metadata, (int)$term_taxonomy_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($term_taxonomy_audit['conflicts']), 1, 'WordPress term-taxonomy owner delete guard records one row constraint conflict');
    $term_taxonomy_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $term_taxonomy_audit['conflicts']));
    assert_true(str_contains($term_taxonomy_preview, '89'), 'WordPress term-taxonomy audit includes the guarded term ID');
    $term_taxonomy_blocker_reason = (string)scalar($term_taxonomy_metadata, "SELECT reason FROM merge_decisions WHERE table_name = 'wp_terms' AND decision = 'target-wins' ORDER BY id DESC LIMIT 1");
    assert_true(str_contains($term_taxonomy_blocker_reason, 'target has changed wp_term_taxonomy rows'), 'WordPress term-taxonomy audit explains the changed dependent taxonomy blocker');

    $term_parent_base_root = $tmp . '/term-parent-base';
    $term_parent_source_root = $tmp . '/term-parent-source';
    $term_parent_target_root = $tmp . '/term-parent-target';
    $term_parent_base = $term_parent_base_root . '/wp-content/database/.ht.sqlite';
    $term_parent_source = $term_parent_source_root . '/wp-content/database/.ht.sqlite';
    $term_parent_target = $term_parent_target_root . '/wp-content/database/.ht.sqlite';
    $term_parent_metadata = $tmp . '/.forkpress/cow/merge/wp-term-parent-validator-metadata.sqlite';
    $term_parent_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-term-parent-validator.json';

    mkdir($term_parent_base_root . '/wp-content/database', 0777, true);
    create_wp_term_parent_reference_db($term_parent_base);
    write_test_file($term_parent_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT term_taxonomy_id, term_id, taxonomy, parent FROM wp_term_taxonomy WHERE parent > 0");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $parent_term_id = (int)$row['parent'];
    $taxonomy = (string)$row['taxonomy'];
    $escaped_taxonomy = SQLite3::escapeString($taxonomy);
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = '$escaped_taxonomy' WHERE t.term_id = $parent_term_id");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-term-parent-refs',
            'object' => 'term_taxonomy:' . $row['term_taxonomy_id'],
            'reason' => 'term taxonomy parent references a missing parent term',
            'type' => 'plugin-wp-term-parent-missing-reference',
            'tables' => ['wp_term_taxonomy', 'wp_terms'],
            'validator' => 'forkpress-wp-term-parent-refs@1',
            'candidate' => [
                'term_taxonomy_id' => (int)$row['term_taxonomy_id'],
                'term_id' => (int)$row['term_id'],
                'taxonomy' => $taxonomy,
                'field' => 'parent',
                'missing_parent_term_id' => $parent_term_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($term_parent_base_root, $term_parent_source_root);
    copy_tree_for_test($term_parent_base_root, $term_parent_target_root);
    cow_merge_capture_file_base($term_parent_base_root, $term_parent_file_base);
    cow_merge_allocate_autoincrement_bands($term_parent_source, $term_parent_metadata, 'feature-wp-term-parent-source');
    cow_merge_allocate_autoincrement_bands($term_parent_target, $term_parent_metadata, 'main');

    $db = open_db($term_parent_source);
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_taxonomy_id = 84');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 83');
    $db->close();

    $db = open_db($term_parent_target);
    $db->exec("UPDATE wp_terms SET name = 'Target child category still pointing at deleted parent' WHERE term_id = 85");
    $db->exec("UPDATE wp_term_taxonomy SET description = 'Target child taxonomy still pointing at deleted parent' WHERE term_taxonomy_id = 86");
    $db->close();

    $term_parent_result = cow_merge_branch_state(
        $term_parent_base,
        $term_parent_source,
        $term_parent_target,
        $term_parent_metadata,
        'feature-wp-term-parent-source',
        'main',
        $term_parent_file_base,
        $term_parent_source_root,
        $term_parent_target_root
    );

    assert_same($term_parent_result['status'], 'completed_with_conflicts', 'WordPress term-parent validator holds missing parent terms for review');
    assert_same((int)($term_parent_result['plugin_validators'] ?? 0), 1, 'WordPress term-parent validator is discovered from mu-plugins during merge');
    assert_same((int)($term_parent_result['plugin_validator_conflicts'] ?? 0), 1, 'WordPress term-parent validator records the missing parent term');
    assert_same((int)scalar($term_parent_target, 'SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_taxonomy_id = 84'), 0, 'WordPress term-parent validator leaves the source parent taxonomy deletion staged for review');
    assert_same((int)scalar($term_parent_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 83'), 0, 'WordPress term-parent validator leaves the source parent term deletion staged for review');
    assert_same(scalar($term_parent_target, 'SELECT name FROM wp_terms WHERE term_id = 85'), 'Target child category still pointing at deleted parent', 'WordPress term-parent validator preserves the target child term edit');
    assert_same(scalar($term_parent_target, 'SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id = 86'), 'Target child taxonomy still pointing at deleted parent', 'WordPress term-parent validator preserves the target child taxonomy edit');

    $term_parent_audit = cow_merge_audit_report($term_parent_metadata, (int)$term_parent_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-term-parent-missing-reference',
    ]);
    assert_same(count($term_parent_audit['conflicts']), 1, 'WordPress term-parent validator exposes the missing parent term as a plugin-scoped audit conflict');
    $term_parent_preview = (string)($term_parent_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($term_parent_preview, '"missing_parent_term_id":83'), 'WordPress term-parent audit includes the missing parent term ID');
    assert_true(str_contains($term_parent_preview, '"term_taxonomy_id":86'), 'WordPress term-parent audit includes the child term taxonomy ID');
    assert_true(str_contains($term_parent_preview, '"field":"parent"'), 'WordPress term-parent audit includes the stale field name');

    $termmeta_base_root = $tmp . '/termmeta-base';
    $termmeta_source_root = $tmp . '/termmeta-source';
    $termmeta_target_root = $tmp . '/termmeta-target';
    $termmeta_base = $termmeta_base_root . '/wp-content/database/.ht.sqlite';
    $termmeta_source = $termmeta_source_root . '/wp-content/database/.ht.sqlite';
    $termmeta_target = $termmeta_target_root . '/wp-content/database/.ht.sqlite';
    $termmeta_metadata = $tmp . '/.forkpress/cow/merge/wp-termmeta-validator-metadata.sqlite';
    $termmeta_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-termmeta-validator.json';

    mkdir($termmeta_base_root . '/wp-content/database', 0777, true);
    create_wp_termmeta_reference_db($termmeta_base);
    write_test_file($termmeta_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT meta_id, term_id, meta_key FROM wp_termmeta WHERE term_id > 0");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $term_id = (int)$row['term_id'];
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms WHERE term_id = $term_id");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-termmeta-refs',
            'object' => 'termmeta:' . $row['meta_id'],
            'reason' => 'termmeta references a missing term',
            'type' => 'plugin-wp-termmeta-missing-term',
            'tables' => ['wp_termmeta', 'wp_terms'],
            'validator' => 'forkpress-wp-termmeta-refs@1',
            'candidate' => [
                'meta_id' => (int)$row['meta_id'],
                'meta_key' => (string)$row['meta_key'],
                'field' => 'term_id',
                'missing_term_id' => $term_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($termmeta_base_root, $termmeta_source_root);
    copy_tree_for_test($termmeta_base_root, $termmeta_target_root);
    cow_merge_capture_file_base($termmeta_base_root, $termmeta_file_base);
    cow_merge_allocate_autoincrement_bands($termmeta_source, $termmeta_metadata, 'feature-wp-termmeta-source');
    cow_merge_allocate_autoincrement_bands($termmeta_target, $termmeta_metadata, 'main');

    $db = open_db($termmeta_source);
    $db->exec('DELETE FROM wp_terms WHERE term_id = 87');
    $db->close();

    $db = open_db($termmeta_target);
    $db->exec("UPDATE wp_termmeta SET meta_value = 'Target termmeta still pointing at deleted term' WHERE meta_id = 88");
    $db->exec("UPDATE wp_termmeta SET meta_value = '{\"favorite\":\"target\"}' WHERE meta_id = 89");
    $db->close();

    $termmeta_result = cow_merge_branch_state(
        $termmeta_base,
        $termmeta_source,
        $termmeta_target,
        $termmeta_metadata,
        'feature-wp-termmeta-source',
        'main',
        $termmeta_file_base,
        $termmeta_source_root,
        $termmeta_target_root
    );

    assert_same($termmeta_result['status'], 'completed_with_conflicts', 'WordPress termmeta owner delete with target-edited metadata stays reviewable');
    assert_same((int)($termmeta_result['plugin_validators'] ?? 0), 1, 'WordPress termmeta validator is discovered from mu-plugins during merge');
    assert_same((int)($termmeta_result['plugin_validator_conflicts'] ?? 0), 0, 'WordPress termmeta owner delete guard prevents missing-owner validator fallout');
    assert_same((int)scalar($termmeta_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 87'), 1, 'WordPress termmeta owner delete guard keeps the parent term before review');
    assert_same(scalar($termmeta_target, 'SELECT meta_value FROM wp_termmeta WHERE meta_id = 88'), 'Target termmeta still pointing at deleted term', 'WordPress termmeta validator preserves the target scalar termmeta edit');
    assert_same(scalar($termmeta_target, 'SELECT meta_value FROM wp_termmeta WHERE meta_id = 89'), '{"favorite":"target"}', 'WordPress termmeta validator preserves the target JSON termmeta edit');

    $termmeta_audit = cow_merge_audit_report($termmeta_metadata, (int)$termmeta_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($termmeta_audit['conflicts']), 1, 'WordPress termmeta owner delete guard records one row constraint conflict');
    $termmeta_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $termmeta_audit['conflicts']));
    assert_true(str_contains($termmeta_preview, '87'), 'WordPress termmeta audit includes the guarded term ID');
    $termmeta_blocker_reason = (string)scalar($termmeta_metadata, "SELECT reason FROM merge_decisions WHERE table_name = 'wp_terms' AND decision = 'target-wins' ORDER BY id DESC LIMIT 1");
    assert_true(str_contains($termmeta_blocker_reason, 'target has changed wp_termmeta rows'), 'WordPress termmeta audit explains the changed dependent metadata blocker');

    $comment_base_root = $tmp . '/comment-ref-base';
    $comment_source_root = $tmp . '/comment-ref-source';
    $comment_target_root = $tmp . '/comment-ref-target';
    $comment_base = $comment_base_root . '/wp-content/database/.ht.sqlite';
    $comment_source = $comment_source_root . '/wp-content/database/.ht.sqlite';
    $comment_target = $comment_target_root . '/wp-content/database/.ht.sqlite';
    $comment_metadata = $tmp . '/.forkpress/cow/merge/wp-comment-ref-validator-metadata.sqlite';
    $comment_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-comment-ref-validator.json';

    mkdir($comment_base_root . '/wp-content/database', 0777, true);
    create_wp_comment_reference_db($comment_base);
    write_test_file($comment_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$findings = [];
$comments = $db->query('SELECT comment_ID, comment_post_ID, comment_parent, user_id FROM wp_comments ORDER BY comment_ID');
while ($row = $comments->fetchArray(SQLITE3_ASSOC)) {
    $comment_id = (int)$row['comment_ID'];
    $post_id = (int)$row['comment_post_ID'];
    $post_exists = $post_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $post_id");
    if ($post_exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-comment-refs',
            'object' => 'comment:' . $comment_id,
            'reason' => 'comment references a missing post',
            'type' => 'plugin-wp-comment-missing-post',
            'tables' => ['wp_comments', 'wp_posts'],
            'validator' => 'forkpress-wp-comment-refs@1',
            'candidate' => [
                'comment_id' => $comment_id,
                'field' => 'comment_post_ID',
                'missing_object_id' => $post_id,
                'object_type' => 'post',
            ],
        ];
    }
    $user_id = (int)$row['user_id'];
    $user_exists = $user_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_users WHERE ID = $user_id");
    if ($user_exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-comment-refs',
            'object' => 'comment:' . $comment_id,
            'reason' => 'comment references a missing user',
            'type' => 'plugin-wp-comment-missing-user',
            'tables' => ['wp_comments', 'wp_users'],
            'validator' => 'forkpress-wp-comment-refs@1',
            'candidate' => [
                'comment_id' => $comment_id,
                'field' => 'user_id',
                'missing_object_id' => $user_id,
                'object_type' => 'user',
            ],
        ];
    }
    $parent_id = (int)$row['comment_parent'];
    $parent_exists = $parent_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_comments WHERE comment_ID = $parent_id");
    if ($parent_exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-comment-refs',
            'object' => 'comment:' . $comment_id,
            'reason' => 'comment references a missing parent comment',
            'type' => 'plugin-wp-comment-missing-parent',
            'tables' => ['wp_comments'],
            'validator' => 'forkpress-wp-comment-refs@1',
            'candidate' => [
                'comment_id' => $comment_id,
                'field' => 'comment_parent',
                'missing_object_id' => $parent_id,
                'object_type' => 'comment',
            ],
        ];
    }
}
$meta = $db->query('SELECT meta_id, comment_id FROM wp_commentmeta ORDER BY meta_id');
while ($row = $meta->fetchArray(SQLITE3_ASSOC)) {
    $comment_id = (int)$row['comment_id'];
    $exists = $comment_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_comments WHERE comment_ID = $comment_id");
    if ($exists !== 0) {
        continue;
    }
    $findings[] = [
        'plugin' => 'forkpress-wp-comment-refs',
        'object' => 'commentmeta:' . $row['meta_id'],
        'reason' => 'comment metadata references a missing comment',
        'type' => 'plugin-wp-commentmeta-missing-comment',
        'tables' => ['wp_commentmeta', 'wp_comments'],
        'validator' => 'forkpress-wp-comment-refs@1',
        'candidate' => [
            'meta_id' => (int)$row['meta_id'],
            'field' => 'comment_id',
            'missing_object_id' => $comment_id,
            'object_type' => 'comment',
        ],
    ];
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($comment_base_root, $comment_source_root);
    copy_tree_for_test($comment_base_root, $comment_target_root);
    cow_merge_capture_file_base($comment_base_root, $comment_file_base);
    cow_merge_allocate_autoincrement_bands($comment_source, $comment_metadata, 'feature-wp-comment-ref-source');
    cow_merge_allocate_autoincrement_bands($comment_target, $comment_metadata, 'main');

    $db = open_db($comment_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 120');
    $db->exec('DELETE FROM wp_users WHERE ID = 121');
    $db->exec('DELETE FROM wp_comments WHERE comment_ID IN (123, 125)');
    $db->close();

    $db = open_db($comment_target);
    $db->exec("UPDATE wp_comments SET comment_content = 'Target comment still pointing at deleted post and user' WHERE comment_ID = 122");
    $db->exec("UPDATE wp_comments SET comment_content = 'Target child comment still pointing at deleted parent' WHERE comment_ID = 126");
    $db->exec("UPDATE wp_commentmeta SET meta_value = 'target metadata still pointing at deleted comment' WHERE meta_id = 124");
    $db->close();

    $comment_result = cow_merge_branch_state(
        $comment_base,
        $comment_source,
        $comment_target,
        $comment_metadata,
        'feature-wp-comment-ref-source',
        'main',
        $comment_file_base,
        $comment_source_root,
        $comment_target_root
    );

    assert_same($comment_result['status'], 'completed_with_conflicts', 'WordPress comment-reference owner deletes with target-edited dependents stay reviewable');
    assert_same((int)($comment_result['plugin_validators'] ?? 0), 1, 'WordPress comment-reference validator is discovered from mu-plugins during merge');
    assert_same((int)($comment_result['plugin_validator_conflicts'] ?? 0), 0, 'WordPress comment-reference owner delete guards prevent missing-reference validator fallout');
    assert_same((int)scalar($comment_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 120'), 1, 'WordPress comment-reference owner delete guard keeps the referenced post before review');
    assert_same((int)scalar($comment_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 121'), 1, 'WordPress comment-reference owner delete guard keeps the referenced user before review');
    assert_same((int)scalar($comment_target, 'SELECT COUNT(*) FROM wp_comments WHERE comment_ID = 123'), 1, 'WordPress comment-reference owner delete guard keeps the comment before review');
    assert_same((int)scalar($comment_target, 'SELECT COUNT(*) FROM wp_comments WHERE comment_ID = 125'), 1, 'WordPress comment-reference owner delete guard keeps the parent comment before review');
    assert_same(scalar($comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 122'), 'Target comment still pointing at deleted post and user', 'WordPress comment-reference validator preserves target comment edits');
    assert_same(scalar($comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 126'), 'Target child comment still pointing at deleted parent', 'WordPress comment-reference validator preserves target child comment edits');
    assert_same(scalar($comment_target, 'SELECT meta_value FROM wp_commentmeta WHERE meta_id = 124'), 'target metadata still pointing at deleted comment', 'WordPress comment-reference validator preserves target commentmeta edits');

    $comment_audit = cow_merge_audit_report($comment_metadata, (int)$comment_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    assert_same(count($comment_audit['conflicts']), 4, 'WordPress comment-reference owner delete guards record one row constraint per guarded owner');
    $comment_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $comment_audit['conflicts']));
    foreach (['120', '121', '123', '125'] as $needle) {
        assert_true(str_contains($comment_preview, $needle), 'WordPress comment-reference audit includes ' . $needle);
    }
    $comment_blocker_reasons = (string)scalar($comment_metadata, "SELECT group_concat(reason, '\n') FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_users', 'wp_comments') AND decision = 'target-wins'");
    assert_true(str_contains($comment_blocker_reasons, 'target has changed wp_comments rows'), 'WordPress comment-reference audit explains the changed dependent comment blockers');
    assert_true(str_contains($comment_blocker_reasons, 'target has changed wp_commentmeta rows'), 'WordPress comment-reference audit explains the changed dependent comment metadata blocker');

    $option_base_root = $tmp . '/option-ref-base';
    $option_source_root = $tmp . '/option-ref-source';
    $option_target_root = $tmp . '/option-ref-target';
    $option_base = $option_base_root . '/wp-content/database/.ht.sqlite';
    $option_source = $option_source_root . '/wp-content/database/.ht.sqlite';
    $option_target = $option_target_root . '/wp-content/database/.ht.sqlite';
    $option_metadata = $tmp . '/.forkpress/cow/merge/wp-option-ref-validator-metadata.sqlite';
    $option_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-option-ref-validator.json';

    mkdir($option_base_root . '/wp-content/database', 0777, true);
    create_wp_option_reference_db($option_base);
    write_test_file($option_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'theme_mods_%' OR option_name IN ('widget_nav_menu', 'nav_menu_options', 'widget_media_image', 'widget_media_audio', 'widget_media_video', 'widget_media_gallery', 'widget_pages', 'widget_block', 'widget_text', 'widget_custom_html', 'sidebars_widgets', 'site_icon', 'page_on_front', 'page_for_posts', 'sticky_posts')");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $option_name = (string)$row['option_name'];
    if ($option_name === 'page_on_front' || $option_name === 'page_for_posts') {
        $page_id = (int)$row['option_value'];
        $exists = $page_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $page_id AND post_type = 'page'") : 1;
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'front page option references a missing page',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => $option_name,
                    'missing_object_id' => $page_id,
                    'object_type' => 'page',
                ],
            ];
        }
        continue;
    }
    if ($option_name === 'site_icon') {
        $attachment_id = (int)$row['option_value'];
        $exists = $attachment_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'") : 1;
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'site icon option references a missing attachment',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'site_icon',
                    'missing_object_id' => $attachment_id,
                    'object_type' => 'attachment',
                ],
            ];
        }
        continue;
    }
    if ($option_name === 'sticky_posts') {
        $sticky_posts = @unserialize((string)$row['option_value']);
        if (is_array($sticky_posts)) {
            foreach ($sticky_posts as $index => $post_id) {
                $post_id = (int)$post_id;
                $exists = $post_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $post_id AND post_type = 'post'") : 1;
                if ($exists !== 0) {
                    continue;
                }
                $findings[] = [
                    'plugin' => 'forkpress-wp-option-refs',
                    'object' => 'option:' . $option_name,
                    'reason' => 'sticky posts option references a missing post',
                    'type' => 'plugin-wp-option-missing-object',
                    'tables' => ['wp_options', 'wp_posts'],
                    'validator' => 'forkpress-wp-option-refs@1',
                    'candidate' => [
                        'option_name' => $option_name,
                        'field' => 'sticky_posts.' . (string)$index,
                        'missing_object_id' => $post_id,
                        'object_type' => 'post',
                    ],
                ];
            }
        }
        continue;
    }

    $decoded = @unserialize((string)$row['option_value']);
    if (!is_array($decoded)) {
        continue;
    }
    if ($option_name === 'sidebars_widgets') {
        foreach ($decoded as $sidebar_id => $widget_ids) {
            if (!is_array($widget_ids)) {
                continue;
            }
            foreach ($widget_ids as $index => $widget_id) {
                if (!is_string($widget_id) || !preg_match('/^(.+)-([0-9]+)$/', $widget_id, $matches)) {
                    continue;
                }
                $widget_option_name = 'widget_' . $matches[1];
                $widget_number = (int)$matches[2];
                $escaped_option = SQLite3::escapeString($widget_option_name);
                $widget_value = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name = '$escaped_option'");
                $instances = is_string($widget_value) ? @unserialize($widget_value) : null;
                if (is_array($instances) && isset($instances[$widget_number])) {
                    continue;
                }
                $findings[] = [
                    'plugin' => 'forkpress-wp-option-refs',
                    'object' => 'option:' . $option_name,
                    'reason' => 'sidebar references a missing widget instance',
                    'type' => 'plugin-wp-option-missing-object',
                    'tables' => ['wp_options'],
                    'validator' => 'forkpress-wp-option-refs@1',
                    'candidate' => [
                        'option_name' => $option_name,
                        'field' => (string)$sidebar_id . '.' . (string)$index,
                        'missing_object_id' => $widget_id,
                        'object_type' => 'widget',
                        'widget_option_name' => $widget_option_name,
                    ],
                ];
            }
        }
        continue;
    }
    if (isset($decoded['forkpress_featured_page'])) {
        $page_id = (int)$decoded['forkpress_featured_page'];
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $page_id AND post_type = 'page'");
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'theme option references a missing page',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'forkpress_featured_page',
                    'missing_object_id' => $page_id,
                    'object_type' => 'page',
                ],
            ];
        }
    }
    if (isset($decoded['custom_logo'])) {
        $attachment_id = (int)$decoded['custom_logo'];
        $exists = $attachment_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'") : 1;
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'theme option references a missing custom logo attachment',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'custom_logo',
                    'missing_object_id' => $attachment_id,
                    'object_type' => 'attachment',
                ],
            ];
        }
    }
    if ($option_name === 'widget_nav_menu') {
        foreach ($decoded as $widget_id => $widget) {
            if (!is_array($widget) || !isset($widget['nav_menu'])) {
                continue;
            }
            $term_id = (int)$widget['nav_menu'];
            $exists = $term_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = 'nav_menu' WHERE t.term_id = $term_id") : 1;
            if ($exists !== 0) {
                continue;
            }
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'nav menu widget references a missing nav menu',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_terms', 'wp_term_taxonomy'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'widget.' . (string)$widget_id . '.nav_menu',
                    'missing_object_id' => $term_id,
                    'object_type' => 'nav_menu',
                ],
            ];
        }
    }
    if ($option_name === 'nav_menu_options') {
        foreach (($decoded['auto_add'] ?? []) as $index => $term_id) {
            $term_id = (int)$term_id;
            $exists = $term_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = 'nav_menu' WHERE t.term_id = $term_id") : 1;
            if ($exists !== 0) {
                continue;
            }
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'nav menu auto-add option references a missing nav menu',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_terms', 'wp_term_taxonomy'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'auto_add.' . (string)$index,
                    'missing_object_id' => $term_id,
                    'object_type' => 'nav_menu',
                ],
            ];
        }
    }
    if (in_array($option_name, ['widget_media_image', 'widget_media_audio', 'widget_media_video'], true)) {
        foreach ($decoded as $widget_id => $widget) {
            if (!is_array($widget) || !isset($widget['attachment_id'])) {
                continue;
            }
            $attachment_id = (int)$widget['attachment_id'];
            $exists = $attachment_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'") : 1;
            if ($exists !== 0) {
                continue;
            }
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'media image widget references a missing attachment',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'widget.' . (string)$widget_id . '.attachment_id',
                    'missing_object_id' => $attachment_id,
                    'object_type' => 'attachment',
                ],
            ];
        }
    }
    if ($option_name === 'widget_media_gallery') {
        foreach ($decoded as $widget_id => $widget) {
            if (!is_array($widget) || !isset($widget['ids'])) {
                continue;
            }
            $ids = is_array($widget['ids']) ? $widget['ids'] : explode(',', (string)$widget['ids']);
            foreach ($ids as $index => $attachment_id) {
                $attachment_id = (int)$attachment_id;
                $exists = $attachment_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'") : 1;
                if ($exists !== 0) {
                    continue;
                }
                $findings[] = [
                    'plugin' => 'forkpress-wp-option-refs',
                    'object' => 'option:' . $option_name,
                    'reason' => 'media gallery widget references a missing attachment',
                    'type' => 'plugin-wp-option-missing-object',
                    'tables' => ['wp_options', 'wp_posts'],
                    'validator' => 'forkpress-wp-option-refs@1',
                    'candidate' => [
                        'option_name' => $option_name,
                        'field' => 'widget.' . (string)$widget_id . '.ids.' . (string)$index,
                        'missing_object_id' => $attachment_id,
                        'object_type' => 'attachment',
                    ],
                ];
            }
        }
    }
    if ($option_name === 'widget_pages') {
        foreach ($decoded as $widget_id => $widget) {
            if (!is_array($widget) || !isset($widget['exclude'])) {
                continue;
            }
            $excluded_ids = is_array($widget['exclude']) ? $widget['exclude'] : explode(',', (string)$widget['exclude']);
            foreach ($excluded_ids as $index => $page_id) {
                $page_id = (int)$page_id;
                $exists = $page_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $page_id AND post_type = 'page'") : 1;
                if ($exists !== 0) {
                    continue;
                }
                $findings[] = [
                    'plugin' => 'forkpress-wp-option-refs',
                    'object' => 'option:' . $option_name,
                    'reason' => 'pages widget references a missing page',
                    'type' => 'plugin-wp-option-missing-object',
                    'tables' => ['wp_options', 'wp_posts'],
                    'validator' => 'forkpress-wp-option-refs@1',
                    'candidate' => [
                        'option_name' => $option_name,
                        'field' => 'widget.' . (string)$widget_id . '.exclude.' . (string)$index,
                        'missing_object_id' => $page_id,
                        'object_type' => 'page',
                    ],
                ];
            }
        }
    }
    $block_content_widget_fields = [
        'widget_block' => 'content',
        'widget_custom_html' => 'content',
        'widget_text' => 'text',
    ];
    $block_content_field = $block_content_widget_fields[$option_name] ?? null;
    if ($block_content_field !== null) {
        foreach ($decoded as $widget_id => $widget) {
            if (!is_array($widget) || !isset($widget[$block_content_field]) || !is_string($widget[$block_content_field])) {
                continue;
            }
            if (!preg_match_all('/<!--\s+wp:image\s+\{[^}]*"id"\s*:\s*(\d+)/', $widget[$block_content_field], $image_matches)) {
                continue;
            }
            foreach ($image_matches[1] as $image_id) {
                $attachment_id = (int)$image_id;
                $exists = $attachment_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'") : 1;
                if ($exists !== 0) {
                    continue;
                }
                $findings[] = [
                    'plugin' => 'forkpress-wp-option-refs',
                    'object' => 'option:' . $option_name,
                    'reason' => 'block-content widget references a missing attachment',
                    'type' => 'plugin-wp-option-missing-object',
                    'tables' => ['wp_options', 'wp_posts'],
                    'validator' => 'forkpress-wp-option-refs@1',
                    'candidate' => [
                        'option_name' => $option_name,
                        'field' => 'widget.' . (string)$widget_id . '.' . $block_content_field . '.wp:image.id',
                        'missing_object_id' => $attachment_id,
                        'object_type' => 'attachment',
                    ],
                ];
            }
        }
    }
    foreach (($decoded['nav_menu_locations'] ?? []) as $location => $term_id) {
        $term_id = (int)$term_id;
        $exists = $term_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = 'nav_menu' WHERE t.term_id = $term_id") : 1;
        if ($exists !== 0) {
            continue;
        }
        $findings[] = [
            'plugin' => 'forkpress-wp-option-refs',
            'object' => 'option:' . $option_name,
            'reason' => 'theme option references a missing nav menu',
            'type' => 'plugin-wp-option-missing-object',
            'tables' => ['wp_options', 'wp_terms', 'wp_term_taxonomy'],
            'validator' => 'forkpress-wp-option-refs@1',
            'candidate' => [
                'option_name' => $option_name,
                'field' => 'nav_menu_locations.' . (string)$location,
                'missing_object_id' => $term_id,
                'object_type' => 'nav_menu',
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($option_base_root, $option_source_root);
    copy_tree_for_test($option_base_root, $option_target_root);
    cow_merge_capture_file_base($option_base_root, $option_file_base);
    cow_merge_allocate_autoincrement_bands($option_source, $option_metadata, 'feature-wp-option-ref-source');
    cow_merge_allocate_autoincrement_bands($option_target, $option_metadata, 'main');

    $db = open_db($option_source);
    $db->exec('DELETE FROM wp_posts WHERE ID IN (90, 91, 92, 93)');
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_id = 94');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 94');
    $db->exec("DELETE FROM wp_options WHERE option_name = 'widget_rss'");
    $db->close();

    $db = open_db($option_target);
    $theme_mods_target = serialize([
        'forkpress_featured_page' => 90,
        'nav_menu_locations' => [
            'primary' => 94,
        ],
        'custom_logo' => 93,
        'forkpress_accent' => 'target',
    ]);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'theme_mods_forkpress_active'");
    $stmt->bindValue(':value', $theme_mods_target, SQLITE3_TEXT);
    $stmt->execute();
    $widget_nav_target = serialize([
        2 => [
            'title' => 'Target footer menu',
            'nav_menu' => 94,
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'widget_nav_menu'");
    $stmt->bindValue(':value', $widget_nav_target, SQLITE3_TEXT);
    $stmt->execute();
    $widget_media_target = serialize([
        3 => [
            'attachment_id' => 93,
            'url' => 'http://example.test/wp-content/uploads/2026/05/option-logo.jpg',
            'caption' => 'Target media image widget',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'widget_media_image'");
    $stmt->bindValue(':value', $widget_media_target, SQLITE3_TEXT);
    $stmt->execute();
    $sidebars_target = serialize([
        'sidebar-1' => ['nav_menu-2', 'media_image-3', 'text-4', 'custom_html-10', 'rss-12'],
        'wp_inactive_widgets' => [],
        'array_version' => 3,
    ]);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'sidebars_widgets'");
    $stmt->bindValue(':value', $sidebars_target, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $option_result = cow_merge_branch_state(
        $option_base,
        $option_source,
        $option_target,
        $option_metadata,
        'feature-wp-option-ref-source',
        'main',
        $option_file_base,
        $option_source_root,
        $option_target_root
    );

    assert_same($option_result['status'], 'completed_with_conflicts', 'WordPress option reference validator holds missing option objects for review');
    assert_same((int)($option_result['plugin_validators'] ?? 0), 1, 'WordPress option reference validator is discovered from mu-plugins during merge');
    assert_same((int)($option_result['plugin_validator_conflicts'] ?? 0), 9, 'WordPress option reference validator records only unguarded missing pages, posts, widgets, and option refs');
    assert_same((int)scalar($option_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID IN (90, 91, 92)'), 0, 'WordPress option reference validator leaves unguarded source object deletions staged for review');
    assert_same((int)scalar($option_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 93'), 1, 'WordPress option owner guard keeps target-edited attachment option refs before validation');
    assert_same((int)scalar($option_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 94'), 1, 'WordPress option owner guard keeps target-edited nav menu option refs before validation');

    $option_theme_mods_value = scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'theme_mods_forkpress_active'");
    $option_theme_mods = is_string($option_theme_mods_value) ? unserialize($option_theme_mods_value) : null;
    assert_same($option_theme_mods['forkpress_accent'] ?? null, 'target', 'WordPress option reference validator preserves the target theme mods edit');
    assert_same($option_theme_mods['forkpress_featured_page'] ?? null, 90, 'WordPress option reference validator keeps the stale featured page reference visible for review');
    assert_same($option_theme_mods['nav_menu_locations']['primary'] ?? null, 94, 'WordPress option reference validator keeps the stale nav menu location visible for review');
    assert_same($option_theme_mods['custom_logo'] ?? null, 93, 'WordPress option reference validator keeps the stale custom logo reference visible for review');
    assert_same(scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'site_icon'"), '93', 'WordPress option reference validator keeps the stale site icon option visible for review');
    assert_same(scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'page_on_front'"), '90', 'WordPress option reference validator keeps the stale front page option visible for review');
    assert_same(scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'page_for_posts'"), '91', 'WordPress option reference validator keeps the stale posts page option visible for review');
    $option_sticky_posts = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'sticky_posts'"));
    assert_same($option_sticky_posts[0] ?? null, 92, 'WordPress option reference validator keeps the stale sticky post reference visible for review');
    $option_widget_nav = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_nav_menu'"));
    assert_same($option_widget_nav[2]['title'] ?? null, 'Target footer menu', 'WordPress option reference validator preserves the target nav widget edit');
    assert_same($option_widget_nav[2]['nav_menu'] ?? null, 94, 'WordPress option reference validator keeps the stale nav widget menu visible for review');
    $option_nav_menu_options = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'nav_menu_options'"));
    assert_same($option_nav_menu_options['auto_add'][0] ?? null, 94, 'WordPress option reference validator keeps the stale nav menu auto-add option visible for review');
    $option_widget_media = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_media_image'"));
    assert_same($option_widget_media[3]['caption'] ?? null, 'Target media image widget', 'WordPress option reference validator preserves the target media widget edit');
    assert_same($option_widget_media[3]['attachment_id'] ?? null, 93, 'WordPress option reference validator keeps the stale media widget attachment visible for review');
    $option_widget_audio = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_media_audio'"));
    assert_same($option_widget_audio[6]['attachment_id'] ?? null, 93, 'WordPress option reference validator keeps the stale media audio widget attachment visible for review');
    $option_widget_video = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_media_video'"));
    assert_same($option_widget_video[7]['attachment_id'] ?? null, 93, 'WordPress option reference validator keeps the stale media video widget attachment visible for review');
    $option_widget_gallery = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_media_gallery'"));
    assert_same($option_widget_gallery[8]['ids'][0] ?? null, 93, 'WordPress option reference validator keeps the stale media gallery widget attachment visible for review');
    $option_widget_pages = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_pages'"));
    assert_same($option_widget_pages[9]['exclude'] ?? null, '90', 'WordPress option reference validator keeps the stale pages widget exclusion visible for review');
    $option_widget_block = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_block'"));
    assert_true(str_contains((string)($option_widget_block[5]['content'] ?? ''), '"id":93'), 'WordPress option reference validator keeps the stale block widget attachment visible for review');
    $option_widget_text = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_text'"));
    assert_true(str_contains((string)($option_widget_text[4]['text'] ?? ''), '"id":93'), 'WordPress option reference validator keeps the stale text widget attachment visible for review');
    $option_widget_custom_html = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_custom_html'"));
    assert_true(str_contains((string)($option_widget_custom_html[10]['content'] ?? ''), '"id":93'), 'WordPress option reference validator keeps the stale custom HTML widget attachment visible for review');
    $option_sidebars = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'sidebars_widgets'"));
    assert_same($option_sidebars['sidebar-1'][2] ?? null, 'text-4', 'WordPress option reference validator keeps the stale sidebar widget instance visible for review');

    $option_audit = cow_merge_audit_report($option_metadata, (int)$option_result['run_id'], 20, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-option-missing-object',
    ]);
    assert_same(count($option_audit['conflicts']), 9, 'WordPress option reference validator exposes remaining missing option objects as plugin-scoped audit conflicts');
    $option_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $option_audit['conflicts']));
    foreach (['"missing_object_id":90', '"missing_object_id":91', '"missing_object_id":92', '"missing_object_id":94'] as $needle) {
        assert_true(str_contains($option_preview, $needle), 'WordPress option reference audit includes ' . $needle);
    }
    foreach (['"object_type":"page"', '"object_type":"post"', '"object_type":"nav_menu"', '"object_type":"widget"'] as $needle) {
        assert_true(str_contains($option_preview, $needle), 'WordPress option reference audit includes ' . $needle);
    }
    foreach (['theme_mods_forkpress_active', 'nav_menu_options', 'widget_pages', 'sidebars_widgets', 'widget_rss', 'page_on_front', 'page_for_posts', 'sticky_posts'] as $needle) {
        assert_true(str_contains($option_preview, $needle), 'WordPress option reference audit includes ' . $needle);
    }
    $option_owner_guard_audit = cow_merge_audit_report($option_metadata, (int)$option_result['run_id'], 10, [
        'records' => 'conflicts',
        'conflict_type' => 'row-target-constraint',
    ]);
    $option_owner_guard_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $option_owner_guard_audit['conflicts']));
    assert_true(str_contains($option_owner_guard_preview, '93'), 'WordPress option owner guard audit includes the guarded attachment ID');
    assert_true(str_contains($option_owner_guard_preview, '94'), 'WordPress option owner guard audit includes the guarded nav menu term ID');

    $plugin_cpt_base_root = $tmp . '/wp-plugin-cpt-validator-files-base';
    $plugin_cpt_source_root = $tmp . '/wp-plugin-cpt-validator-files-source';
    $plugin_cpt_target_root = $tmp . '/wp-plugin-cpt-validator-files-target';
    $plugin_cpt_base = $plugin_cpt_base_root . '/wp-content/database/.ht.sqlite';
    $plugin_cpt_source = $plugin_cpt_source_root . '/wp-content/database/.ht.sqlite';
    $plugin_cpt_target = $plugin_cpt_target_root . '/wp-content/database/.ht.sqlite';
    $plugin_cpt_metadata = $tmp . '/.forkpress/cow/merge/wp-plugin-cpt-validator-metadata.sqlite';
    $plugin_cpt_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-plugin-cpt-validator.json';
    mkdir($plugin_cpt_base_root . '/wp-content/database', 0777, true);
    $db = open_db($plugin_cpt_base);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        post_parent INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL DEFAULT "yes")');
    $db->exec('CREATE TABLE plugin_forkpress_notes (note_key TEXT PRIMARY KEY, note_id INTEGER NOT NULL, owner_page_id INTEGER NOT NULL, label TEXT NOT NULL, config_json TEXT NOT NULL, config_serialized TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (100, 'Plugin CPT owner page', '<!-- wp:paragraph --><p>Owner page</p><!-- /wp:paragraph -->', 'publish', 'page', 'plugin-cpt-owner-page'),
        (101, 'Plugin Note CPT', 'Base plugin CPT body', 'publish', 'forkpress_note', 'plugin-note-cpt')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
        (102, 101, '_forkpress_note_graph', '{\"note_id\":101,\"owner_page_id\":100,\"branch\":\"base\"}'),
        (103, 101, '_forkpress_note_serialized_graph', 'a:3:{s:7:\"note_id\";i:101;s:13:\"owner_page_id\";i:100;s:6:\"branch\";s:4:\"base\";}')");
    $base_plugin_cpt_config_json = json_encode(['note_id' => 101, 'owner_page_id' => 100, 'branch' => 'base'], JSON_UNESCAPED_SLASHES);
    $base_plugin_cpt_config_serialized = serialize(['note_id' => 101, 'owner_page_id' => 100, 'branch' => 'base']);
    $stmt = $db->prepare('INSERT INTO plugin_forkpress_notes (note_key, note_id, owner_page_id, label, config_json, config_serialized) VALUES (:key, 101, 100, :label, :json, :serialized)');
    $stmt->bindValue(':key', 'shared-note', SQLITE3_TEXT);
    $stmt->bindValue(':label', 'Base plugin note', SQLITE3_TEXT);
    $stmt->bindValue(':json', $base_plugin_cpt_config_json, SQLITE3_TEXT);
    $stmt->bindValue(':serialized', $base_plugin_cpt_config_serialized, SQLITE3_TEXT);
    $stmt->execute();
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('forkpress_note_index', :value, 'yes')");
    $stmt->bindValue(':value', serialize(['featured_note' => 101, 'owner_page_id' => 100, 'label' => 'Base plugin note']), SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
    write_test_file($plugin_cpt_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$findings = [];
$check_note = static function (int $note_id, string $object, string $field, array $candidate) use ($db, &$findings): void {
    $exists = $note_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $note_id AND post_type = 'forkpress_note'") : 1;
    if ($exists !== 0) {
        return;
    }
    $findings[] = [
        'plugin' => 'forkpress-plugin-cpt',
        'object' => $object,
        'reason' => 'plugin custom post type graph references a missing forkpress_note post',
        'type' => 'plugin-wp-cpt-missing-post',
        'tables' => ['wp_posts', 'wp_postmeta', 'wp_options', 'plugin_forkpress_notes'],
        'validator' => 'forkpress-plugin-cpt@1',
        'candidate' => $candidate + [
            'graph_object' => $object,
            'field' => $field,
            'missing_object_id' => $note_id,
            'object_type' => 'forkpress_note',
        ],
    ];
};
$rows = $db->query('SELECT note_key, note_id, owner_page_id, label, config_json, config_serialized FROM plugin_forkpress_notes ORDER BY note_key');
while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
    $note_id = (int)$row['note_id'];
    $decoded_json = json_decode((string)$row['config_json'], true);
    $decoded_serialized = @unserialize((string)$row['config_serialized']);
    $check_note($note_id, 'plugin_forkpress_notes:' . $row['note_key'], 'note_id', [
        'note_key' => (string)$row['note_key'],
        'label' => (string)$row['label'],
        'owner_page_id' => (int)$row['owner_page_id'],
        'json_note_id' => is_array($decoded_json) ? ($decoded_json['note_id'] ?? null) : null,
        'serialized_note_id' => is_array($decoded_serialized) ? ($decoded_serialized['note_id'] ?? null) : null,
    ]);
}
$option_value = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name = 'forkpress_note_index'");
if (is_string($option_value)) {
    $decoded = @unserialize($option_value);
    if (is_array($decoded) && isset($decoded['featured_note'])) {
        $check_note((int)$decoded['featured_note'], 'option:forkpress_note_index', 'featured_note', [
            'option_name' => 'forkpress_note_index',
            'owner_page_id' => $decoded['owner_page_id'] ?? null,
            'label' => $decoded['label'] ?? null,
        ]);
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($plugin_cpt_base_root, $plugin_cpt_source_root);
    copy_tree_for_test($plugin_cpt_base_root, $plugin_cpt_target_root);
    cow_merge_capture_file_base($plugin_cpt_base_root, $plugin_cpt_file_base);
    cow_merge_allocate_autoincrement_bands($plugin_cpt_source, $plugin_cpt_metadata, 'feature-wp-plugin-cpt-source');
    cow_merge_allocate_autoincrement_bands($plugin_cpt_target, $plugin_cpt_metadata, 'main');

    $db = open_db($plugin_cpt_source);
    $db->exec('DELETE FROM wp_postmeta WHERE post_id = 101');
    $db->exec('DELETE FROM wp_posts WHERE ID = 101');
    $db->exec("DELETE FROM plugin_forkpress_notes WHERE note_key = 'shared-note'");
    $db->exec("DELETE FROM wp_options WHERE option_name = 'forkpress_note_index'");
    $db->close();

    $db = open_db($plugin_cpt_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target owner page still showing note widget' WHERE ID = 100");
    $target_plugin_cpt_config_json = json_encode(['note_id' => 101, 'owner_page_id' => 100, 'branch' => 'target'], JSON_UNESCAPED_SLASHES);
    $target_plugin_cpt_config_serialized = serialize(['note_id' => 101, 'owner_page_id' => 100, 'branch' => 'target']);
    $stmt = $db->prepare("UPDATE plugin_forkpress_notes SET label = 'Target edited plugin note card', config_json = :json, config_serialized = :serialized WHERE note_key = 'shared-note'");
    $stmt->bindValue(':json', $target_plugin_cpt_config_json, SQLITE3_TEXT);
    $stmt->bindValue(':serialized', $target_plugin_cpt_config_serialized, SQLITE3_TEXT);
    $stmt->execute();
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'forkpress_note_index'");
    $stmt->bindValue(':value', serialize(['featured_note' => 101, 'owner_page_id' => 100, 'label' => 'Target edited plugin note card']), SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $plugin_cpt_result = cow_merge_branch_state(
        $plugin_cpt_base,
        $plugin_cpt_source,
        $plugin_cpt_target,
        $plugin_cpt_metadata,
        'feature-wp-plugin-cpt-source',
        'main',
        $plugin_cpt_file_base,
        $plugin_cpt_source_root,
        $plugin_cpt_target_root
    );

    assert_same($plugin_cpt_result['status'], 'completed_with_conflicts', 'plugin CPT validator holds stale plugin graph references for review');
    assert_same((int)($plugin_cpt_result['plugin_validators'] ?? 0), 1, 'plugin CPT validator is discovered from mu-plugins during merge');
    assert_same((int)($plugin_cpt_result['plugin_validator_conflicts'] ?? 0), 2, 'plugin CPT validator records plugin table and option references to the missing CPT row');
    assert_same((int)scalar($plugin_cpt_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 101'), 0, 'plugin CPT source deletion removes the custom post type row before validation');
    assert_same(scalar($plugin_cpt_target, 'SELECT post_title FROM wp_posts WHERE ID = 100'), 'Target owner page still showing note widget', 'plugin CPT validator preserves the target owner page edit');
    assert_same(scalar($plugin_cpt_target, "SELECT label FROM plugin_forkpress_notes WHERE note_key = 'shared-note'"), 'Target edited plugin note card', 'plugin CPT validator keeps the stale plugin table row visible for review');
    $plugin_cpt_option = unserialize((string)scalar($plugin_cpt_target, "SELECT option_value FROM wp_options WHERE option_name = 'forkpress_note_index'"));
    assert_same($plugin_cpt_option['featured_note'] ?? null, 101, 'plugin CPT validator keeps the stale option reference visible for review');
    $plugin_cpt_audit = cow_merge_audit_report($plugin_cpt_metadata, (int)$plugin_cpt_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-cpt-missing-post',
    ]);
    assert_same(count($plugin_cpt_audit['conflicts']), 2, 'plugin CPT validator exposes stale graph references as plugin-scoped audit conflicts');
    $plugin_cpt_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $plugin_cpt_audit['conflicts']));
    assert_true(str_contains($plugin_cpt_preview, '"missing_object_id":101'), 'plugin CPT audit includes the missing custom post type ID');
    assert_true(str_contains($plugin_cpt_preview, 'plugin_forkpress_notes:shared-note'), 'plugin CPT audit includes the plugin table graph object');
    assert_true(str_contains($plugin_cpt_preview, 'option:forkpress_note_index'), 'plugin CPT audit includes the option graph object');
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW WordPress semantic validator focused tests passed ($pass assertions).\n";

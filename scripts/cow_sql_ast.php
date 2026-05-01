<?php
/**
 * AST-backed SQL helpers for ForkPress COW DDL rewriting.
 *
 * The WordPress SQLite integration carries a MySQL lexer/parser with byte
 * ranges for every node. COW only needs to identify DDL targets and replace
 * those exact identifier ranges; the original SQL clauses stay untouched.
 */

function cow_sql_analyze_ddl(string $sql): ?array {
    $ast = cow_sql_parse_single_mysql_query($sql);
    if ($ast !== null) {
        $analysis = cow_sql_analyze_ast($ast);
        if ($analysis !== null) {
            return $analysis;
        }
    }

    return cow_sql_analyze_sqlite_token_ddl($sql);
}

function cow_sql_is_allowed_ddl(string $sql): bool {
    return cow_sql_analyze_ddl($sql) !== null;
}

function cow_sql_rewrite_create_table_name(string $ddl, string $old_name, string $new_name): string {
    $ast = cow_sql_parse_single_mysql_query($ddl);
    $create_table = $ast ? $ast->get_first_descendant_node('createTable') : null;
    if ($create_table !== null) {
        $table_name = $create_table->get_first_child_node('tableName');
        $target = $table_name ? cow_sql_object_reference_from_node($table_name) : null;
        $has_if_not_exists = $create_table->has_child_node('ifNotExists');
    } else {
        $target = cow_sql_analyze_create_table_tokens($ddl);
        $has_if_not_exists = (bool)($target['if_not_exists'] ?? false);
    }

    if ($target === null) {
        throw new RuntimeException("could not parse CREATE TABLE DDL for $old_name");
    }
    if (strcasecmp($target['name'], $old_name) !== 0) {
        throw new RuntimeException("could not find CREATE TABLE target $old_name");
    }

    $rewritten = cow_sql_replace_range(
        $ddl,
        $target['start'],
        $target['length'],
        cow_quote_identifier($new_name)
    );

    if (!$has_if_not_exists) {
        $rewritten = substr($rewritten, 0, $target['start'])
            . 'IF NOT EXISTS '
            . substr($rewritten, $target['start']);
    }

    return $rewritten;
}

function cow_sql_rewrite_create_index(string $ddl, string $old_index, string $new_index,
                                      string $old_table, string $new_table): string {
    $analysis = cow_sql_analyze_ddl($ddl);
    if ($analysis === null || $analysis['type'] !== 'create_index') {
        throw new RuntimeException("DDL is not CREATE INDEX for $old_index");
    }
    if (strcasecmp($analysis['index'], $old_index) !== 0) {
        throw new RuntimeException("could not find CREATE INDEX target $old_index");
    }
    if (strcasecmp($analysis['table'], $old_table) !== 0) {
        throw new RuntimeException("could not retarget CREATE INDEX $old_index from $old_table");
    }

    $rewritten = cow_sql_replace_range(
        $ddl,
        $analysis['table_start'],
        $analysis['table_length'],
        cow_quote_identifier($new_table)
    );
    $rewritten = cow_sql_replace_range(
        $rewritten,
        $analysis['index_start'],
        $analysis['index_length'],
        cow_quote_identifier($new_index)
    );

    if (empty($analysis['if_not_exists'])) {
        $rewritten = substr($rewritten, 0, $analysis['index_start'])
            . 'IF NOT EXISTS '
            . substr($rewritten, $analysis['index_start']);
    }

    return $rewritten;
}

function cow_sql_analyze_create_table_tokens(string $ddl): ?array {
    $tokens = cow_sql_significant_tokens($ddl);
    if ($tokens === null || empty($tokens)) {
        return null;
    }

    $i = 0;
    if (!isset($tokens[$i]) || $tokens[$i]->id !== WP_MySQL_Lexer::CREATE_SYMBOL) {
        return null;
    }
    $i++;
    if (isset($tokens[$i]) && $tokens[$i]->id === WP_MySQL_Lexer::TEMPORARY_SYMBOL) {
        $i++;
    }
    if (!isset($tokens[$i]) || $tokens[$i]->id !== WP_MySQL_Lexer::TABLE_SYMBOL) {
        return null;
    }
    $i++;

    $if_not_exists = cow_sql_consume_if_not_exists($tokens, $i);
    $table = cow_sql_consume_identifier_reference($tokens, $i);
    if ($table === null) {
        return null;
    }

    $table['if_not_exists'] = $if_not_exists;
    return $table;
}

function cow_sql_rewrite_table_reference(string $sql, array $analysis, string $new_table): string {
    if (!isset($analysis['table_start'], $analysis['table_length'])) {
        throw new RuntimeException('SQL analysis does not include a table reference');
    }
    return cow_sql_replace_range(
        $sql,
        (int)$analysis['table_start'],
        (int)$analysis['table_length'],
        cow_quote_identifier($new_table)
    );
}

function cow_sql_replace_range(string $sql, int $start, int $length, string $replacement): string {
    return substr($sql, 0, $start) . $replacement . substr($sql, $start + $length);
}

function cow_sql_parse_single_mysql_query(string $sql): ?WP_Parser_Node {
    cow_sql_load_mysql_parser();

    $lexer = new WP_MySQL_Lexer(cow_sql_mysql_lexer_input($sql), 80038, []);
    $parser = new WP_MySQL_Parser(cow_sql_mysql_grammar(), $lexer->remaining_tokens());

    set_error_handler(static function (): bool {
        return true;
    });
    try {
        if (!$parser->next_query()) {
            return null;
        }
        $ast = $parser->get_query_ast();
        if ($ast === null || $parser->next_query()) {
            return null;
        }
        return $ast;
    } finally {
        restore_error_handler();
    }
}

function cow_sql_analyze_ast(WP_Parser_Node $ast): ?array {
    $alter = $ast->get_first_descendant_node('alterStatement');
    if ($alter !== null) {
        return cow_sql_analyze_alter_statement($alter);
    }

    $create = $ast->get_first_descendant_node('createStatement');
    if ($create !== null) {
        return cow_sql_analyze_create_statement($create);
    }

    $drop = $ast->get_first_descendant_node('dropStatement');
    if ($drop !== null) {
        return cow_sql_analyze_drop_statement($drop);
    }

    return null;
}

function cow_sql_analyze_alter_statement(WP_Parser_Node $alter): ?array {
    $alter_table = $alter->get_first_child_node('alterTable');
    if ($alter_table === null) {
        return null;
    }

    $table_ref = $alter_table->get_first_child_node('tableRef');
    $table = $table_ref ? cow_sql_object_reference_from_node($table_ref) : null;
    if ($table === null) {
        return null;
    }

    return [
        'type' => 'alter_table',
        'table' => $table['name'],
        'table_start' => $table['start'],
        'table_length' => $table['length'],
        'alter_kind' => cow_sql_classify_alter_table($alter_table),
    ];
}

function cow_sql_analyze_create_statement(WP_Parser_Node $create): ?array {
    $create_index = $create->get_first_child_node('createIndex');
    if ($create_index === null) {
        return null;
    }

    $index_ref = $create_index->get_first_child_node('indexName');
    $target = $create_index->get_first_child_node('createIndexTarget');
    $table_ref = $target ? $target->get_first_child_node('tableRef') : null;
    $index = $index_ref ? cow_sql_object_reference_from_node($index_ref) : null;
    $table = $table_ref ? cow_sql_object_reference_from_node($table_ref) : null;
    if ($index === null || $table === null) {
        return null;
    }

    return [
        'type' => 'create_index',
        'index' => $index['name'],
        'index_start' => $index['start'],
        'index_length' => $index['length'],
        'table' => $table['name'],
        'table_start' => $table['start'],
        'table_length' => $table['length'],
        'if_not_exists' => false,
    ];
}

function cow_sql_analyze_drop_statement(WP_Parser_Node $drop): ?array {
    $drop_index = $drop->get_first_child_node('dropIndex');
    if ($drop_index === null) {
        return null;
    }

    $index_ref = $drop_index->get_first_child_node('indexRef');
    $table_ref = $drop_index->get_first_child_node('tableRef');
    $index = $index_ref ? cow_sql_object_reference_from_node($index_ref) : null;
    $table = $table_ref ? cow_sql_object_reference_from_node($table_ref) : null;
    if ($index === null) {
        return null;
    }

    $analysis = [
        'type' => 'drop_index',
        'index' => $index['name'],
        'index_start' => $index['start'],
        'index_length' => $index['length'],
        'if_exists' => false,
    ];
    if ($table !== null) {
        $analysis['table'] = $table['name'];
        $analysis['table_start'] = $table['start'];
        $analysis['table_length'] = $table['length'];
    }
    return $analysis;
}

function cow_sql_classify_alter_table(WP_Parser_Node $alter_table): string {
    $items = $alter_table->get_descendant_nodes('alterListItem');
    if (count($items) !== 1) {
        return 'UNKNOWN';
    }

    $item = $items[0];
    $first = $item->get_first_child_token();
    if ($first === null) {
        return 'UNKNOWN';
    }

    switch ($first->id) {
        case WP_MySQL_Lexer::ADD_SYMBOL:
            return $item->get_first_child_node('fieldDefinition') !== null
                ? 'ADD_COLUMN'
                : 'UNKNOWN';
        case WP_MySQL_Lexer::DROP_SYMBOL:
            return $item->get_first_child_node('fieldIdentifier') !== null
                ? 'DROP_COLUMN'
                : 'UNKNOWN';
        case WP_MySQL_Lexer::RENAME_SYMBOL:
            if ($item->get_first_child_node('fieldIdentifier') !== null) {
                return 'RENAME_COLUMN';
            }
            if ($item->get_first_child_node('tableName') !== null) {
                return 'RENAME_TO';
            }
            return 'UNKNOWN';
        default:
            return 'UNKNOWN';
    }
}

function cow_sql_object_reference_from_node(WP_Parser_Node $node): ?array {
    $identifiers = $node->get_descendant_nodes('identifier');
    if (empty($identifiers)) {
        return null;
    }

    $last = $identifiers[count($identifiers) - 1];
    $token = $last->get_first_descendant_token();
    if (!$token instanceof WP_MySQL_Token) {
        return null;
    }

    return [
        'name' => $token->get_value(),
        'start' => $node->get_start(),
        'length' => $node->get_length(),
    ];
}

function cow_sql_analyze_sqlite_token_ddl(string $sql): ?array {
    $tokens = cow_sql_significant_tokens($sql);
    if ($tokens === null || empty($tokens)) {
        return null;
    }

    if ($tokens[0]->id === WP_MySQL_Lexer::CREATE_SYMBOL) {
        return cow_sql_analyze_sqlite_create_index_tokens($tokens);
    }

    if ($tokens[0]->id === WP_MySQL_Lexer::ALTER_SYMBOL) {
        return cow_sql_analyze_sqlite_alter_table_tokens($tokens);
    }

    if ($tokens[0]->id === WP_MySQL_Lexer::DROP_SYMBOL) {
        return cow_sql_analyze_sqlite_drop_index_tokens($tokens);
    }

    return null;
}

function cow_sql_analyze_sqlite_create_index_tokens(array $tokens): ?array {
    $i = 1;
    $unique = false;
    if (isset($tokens[$i]) && $tokens[$i]->id === WP_MySQL_Lexer::UNIQUE_SYMBOL) {
        $unique = true;
        $i++;
    }
    if (!isset($tokens[$i]) || $tokens[$i]->id !== WP_MySQL_Lexer::INDEX_SYMBOL) {
        return null;
    }
    $i++;

    $if_not_exists = cow_sql_consume_if_not_exists($tokens, $i);
    $index = cow_sql_consume_identifier_reference($tokens, $i);
    if ($index === null || !isset($tokens[$i]) || $tokens[$i]->id !== WP_MySQL_Lexer::ON_SYMBOL) {
        return null;
    }
    $i++;
    $table = cow_sql_consume_identifier_reference($tokens, $i);
    if ($table === null || !isset($tokens[$i]) || $tokens[$i]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
        return null;
    }

    return [
        'type' => 'create_index',
        'unique' => $unique,
        'index' => $index['name'],
        'index_start' => $index['start'],
        'index_length' => $index['length'],
        'table' => $table['name'],
        'table_start' => $table['start'],
        'table_length' => $table['length'],
        'if_not_exists' => $if_not_exists,
    ];
}

function cow_sql_analyze_sqlite_alter_table_tokens(array $tokens): ?array {
    $i = 1;
    if (!isset($tokens[$i]) || $tokens[$i]->id !== WP_MySQL_Lexer::TABLE_SYMBOL) {
        return null;
    }
    $i++;

    $table = cow_sql_consume_identifier_reference($tokens, $i);
    if ($table === null || !isset($tokens[$i])) {
        return null;
    }

    return [
        'type' => 'alter_table',
        'table' => $table['name'],
        'table_start' => $table['start'],
        'table_length' => $table['length'],
        'alter_kind' => cow_sql_classify_sqlite_alter_tokens($tokens, $i),
    ];
}

function cow_sql_analyze_sqlite_drop_index_tokens(array $tokens): ?array {
    $i = 1;
    if (!isset($tokens[$i]) || $tokens[$i]->id !== WP_MySQL_Lexer::INDEX_SYMBOL) {
        return null;
    }
    $i++;

    $if_exists = cow_sql_consume_if_exists($tokens, $i);
    $index = cow_sql_consume_identifier_reference($tokens, $i);
    if ($index === null) {
        return null;
    }

    $analysis = [
        'type' => 'drop_index',
        'index' => $index['name'],
        'index_start' => $index['start'],
        'index_length' => $index['length'],
        'if_exists' => $if_exists,
    ];

    if (isset($tokens[$i]) && $tokens[$i]->id === WP_MySQL_Lexer::ON_SYMBOL) {
        $i++;
        $table = cow_sql_consume_identifier_reference($tokens, $i);
        if ($table === null) {
            return null;
        }
        $analysis['table'] = $table['name'];
        $analysis['table_start'] = $table['start'];
        $analysis['table_length'] = $table['length'];
    }

    return isset($tokens[$i]) ? null : $analysis;
}

function cow_sql_classify_sqlite_alter_tokens(array $tokens, int $i): string {
    $token = $tokens[$i] ?? null;
    if (!$token instanceof WP_MySQL_Token) {
        return 'UNKNOWN';
    }

    if ($token->id === WP_MySQL_Lexer::ADD_SYMBOL) {
        return 'ADD_COLUMN';
    }
    if ($token->id === WP_MySQL_Lexer::DROP_SYMBOL) {
        return 'DROP_COLUMN';
    }
    if ($token->id === WP_MySQL_Lexer::RENAME_SYMBOL) {
        $next = $tokens[$i + 1] ?? null;
        if ($next instanceof WP_MySQL_Token && $next->id === WP_MySQL_Lexer::TO_SYMBOL) {
            return 'RENAME_TO';
        }
        return 'RENAME_COLUMN';
    }

    return 'UNKNOWN';
}

function cow_sql_significant_tokens(string $sql): ?array {
    cow_sql_load_mysql_parser();

    $lexer = new WP_MySQL_Lexer(cow_sql_mysql_lexer_input($sql), 80038, []);
    $tokens = $lexer->remaining_tokens();
    if (empty($tokens)) {
        return null;
    }

    $out = [];
    $saw_eof = false;
    $saw_semicolon = false;
    foreach ($tokens as $token) {
        if ($token->id === WP_MySQL_Lexer::EOF) {
            $saw_eof = true;
            break;
        }
        if ($saw_semicolon) {
            return null;
        }
        if ($token->id === WP_MySQL_Lexer::SEMICOLON_SYMBOL) {
            $saw_semicolon = true;
            continue;
        }
        $out[] = $token;
    }

    return $saw_eof ? $out : null;
}

function cow_sql_consume_if_not_exists(array $tokens, int &$i): bool {
    if (isset($tokens[$i], $tokens[$i + 1], $tokens[$i + 2])
        && $tokens[$i]->id === WP_MySQL_Lexer::IF_SYMBOL
        && $tokens[$i + 1]->id === WP_MySQL_Lexer::NOT_SYMBOL
        && $tokens[$i + 2]->id === WP_MySQL_Lexer::EXISTS_SYMBOL) {
        $i += 3;
        return true;
    }
    return false;
}

function cow_sql_consume_if_exists(array $tokens, int &$i): bool {
    if (isset($tokens[$i], $tokens[$i + 1])
        && $tokens[$i]->id === WP_MySQL_Lexer::IF_SYMBOL
        && $tokens[$i + 1]->id === WP_MySQL_Lexer::EXISTS_SYMBOL) {
        $i += 2;
        return true;
    }
    return false;
}

function cow_sql_consume_identifier_reference(array $tokens, int &$i): ?array {
    $first = cow_sql_identifier_token($tokens[$i] ?? null);
    if ($first === null) {
        return null;
    }

    $last = $first;
    $i++;
    while (isset($tokens[$i], $tokens[$i + 1])
        && $tokens[$i]->id === WP_MySQL_Lexer::DOT_SYMBOL) {
        $next = cow_sql_identifier_token($tokens[$i + 1]);
        if ($next === null) {
            break;
        }
        $last = $next;
        $i += 2;
    }

    return [
        'name' => $last->get_value(),
        'start' => $first->start,
        'length' => $last->start + $last->length - $first->start,
    ];
}

function cow_sql_identifier_token($token): ?WP_MySQL_Token {
    if (!$token instanceof WP_MySQL_Token) {
        return null;
    }
    if (in_array($token->id, [
        WP_MySQL_Lexer::IDENTIFIER,
        WP_MySQL_Lexer::BACK_TICK_QUOTED_ID,
        WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT,
    ], true)) {
        return $token;
    }
    return null;
}

/** Let the MySQL lexer parse SQLite's [identifier] quoting without moving byte offsets. */
function cow_sql_mysql_lexer_input(string $sql): string {
    $out = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($c === "'") {
            $out .= $c;
            while (++$i < $len) {
                $out .= $sql[$i];
                if ($sql[$i] !== "'") {
                    continue;
                }
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $out .= $sql[++$i];
                    continue;
                }
                break;
            }
            continue;
        }

        if ($c === '"' || $c === '`') {
            $quote = $c;
            $out .= $c;
            while (++$i < $len) {
                $out .= $sql[$i];
                if ($sql[$i] !== $quote) {
                    continue;
                }
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $out .= $sql[++$i];
                    continue;
                }
                break;
            }
            continue;
        }

        if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            $out .= $c . $sql[++$i];
            while (++$i < $len) {
                $out .= $sql[$i];
                if ($sql[$i] === "\n") {
                    break;
                }
            }
            continue;
        }

        if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $out .= $c . $sql[++$i];
            while (++$i < $len) {
                $out .= $sql[$i];
                if ($sql[$i] === '*' && $i + 1 < $len && $sql[$i + 1] === '/') {
                    $out .= $sql[++$i];
                    break;
                }
            }
            continue;
        }

        if ($c === '[') {
            $out .= '`';
            while (++$i < $len) {
                if ($sql[$i] === ']') {
                    $out .= '`';
                    break;
                }
                $out .= $sql[$i];
            }
            continue;
        }

        $out .= $c;
    }

    return $out;
}

function cow_sql_mysql_grammar(): WP_Parser_Grammar {
    static $grammar = null;
    if ($grammar === null) {
        $grammar = new WP_Parser_Grammar(
            require __DIR__ . '/../vendor/sqlite-database-integration/wp-includes/database/mysql/mysql-grammar.php'
        );
    }
    return $grammar;
}

function cow_sql_load_mysql_parser(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $base = __DIR__ . '/../vendor/sqlite-database-integration/wp-includes/database';
    require_once $base . '/parser/class-wp-parser-grammar.php';
    require_once $base . '/parser/class-wp-parser.php';
    require_once $base . '/parser/class-wp-parser-node.php';
    require_once $base . '/parser/class-wp-parser-token.php';
    require_once $base . '/mysql/class-wp-mysql-token.php';
    require_once $base . '/mysql/class-wp-mysql-lexer.php';
    require_once $base . '/mysql/class-wp-mysql-parser.php';
    $loaded = true;
}

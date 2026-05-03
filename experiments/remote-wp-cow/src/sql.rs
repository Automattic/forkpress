use std::collections::BTreeSet;

pub fn is_write_sql(sql: &str) -> bool {
    matches!(
        first_keyword(sql).as_deref(),
        Some("INSERT")
            | Some("UPDATE")
            | Some("DELETE")
            | Some("REPLACE")
            | Some("ALTER")
            | Some("CREATE")
            | Some("DROP")
            | Some("TRUNCATE")
            | Some("RENAME")
            | Some("LOAD")
            | Some("LOCK")
            | Some("UNLOCK")
            | Some("GRANT")
            | Some("REVOKE")
            | Some("OPTIMIZE")
            | Some("ANALYZE")
            | Some("REPAIR")
    )
}

pub fn is_safe_read_sql(sql: &str) -> bool {
    match first_keyword(sql).as_deref() {
        Some("SELECT") => !select_has_remote_side_effect_clause(sql),
        Some("SHOW") | Some("DESCRIBE") | Some("DESC") | Some("EXPLAIN") => true,
        _ => false,
    }
}

#[allow(dead_code)]
pub fn extract_tables(sql: &str) -> Vec<String> {
    let mut tables = BTreeSet::new();
    let tokens = tokenize(sql);
    let table_markers = ["FROM", "JOIN", "UPDATE", "INTO", "TABLE"];
    let mut i = 0;
    while i < tokens.len() {
        if table_markers.contains(&tokens[i].to_ascii_uppercase().as_str()) {
            if let Some(next) = tokens.get(i + 1) {
                if !is_keyword(next) {
                    tables.insert(next.trim_matches('`').to_string());
                }
            }
        }
        i += 1;
    }
    tables.into_iter().collect()
}

pub fn expand_wordpress_groups(table_prefix: &str, tables: &[String]) -> Vec<String> {
    let content_group = [
        "posts",
        "postmeta",
        "terms",
        "term_taxonomy",
        "term_relationships",
    ];
    let mut out: BTreeSet<String> = tables.iter().cloned().collect();

    let touches_content_group = tables.iter().any(|table| {
        content_group
            .iter()
            .any(|suffix| table == &format!("{}{}", table_prefix, suffix))
    });

    if touches_content_group {
        for suffix in content_group {
            out.insert(format!("{}{}", table_prefix, suffix));
        }
    }

    out.into_iter().collect()
}

fn first_keyword(sql: &str) -> Option<String> {
    let stripped = strip_leading_comments(sql);
    stripped
        .split(|ch: char| ch.is_whitespace() || ch == '(')
        .find(|part| !part.is_empty())
        .map(|part| part.trim_matches('`').to_ascii_uppercase())
}

fn select_has_remote_side_effect_clause(sql: &str) -> bool {
    let tokens = tokenize(strip_leading_comments(sql))
        .into_iter()
        .map(|token| token.to_ascii_uppercase())
        .collect::<Vec<_>>();

    tokens.iter().any(|token| token == "INTO")
        || tokens.windows(2).any(|window| window == ["FOR", "UPDATE"])
        || tokens
            .windows(4)
            .any(|window| window == ["LOCK", "IN", "SHARE", "MODE"])
}

fn strip_leading_comments(mut sql: &str) -> &str {
    loop {
        let trimmed = sql.trim_start();
        if let Some(rest) = trimmed.strip_prefix("--") {
            if let Some(pos) = rest.find('\n') {
                sql = &rest[pos + 1..];
                continue;
            }
            return "";
        }
        if let Some(rest) = trimmed.strip_prefix('#') {
            if let Some(pos) = rest.find('\n') {
                sql = &rest[pos + 1..];
                continue;
            }
            return "";
        }
        if let Some(rest) = trimmed.strip_prefix("/*") {
            if let Some(pos) = rest.find("*/") {
                sql = &rest[pos + 2..];
                continue;
            }
            return "";
        }
        return trimmed;
    }
}

#[allow(dead_code)]
fn tokenize(sql: &str) -> Vec<String> {
    let mut tokens = Vec::new();
    let mut current = String::new();
    let mut quote = None;
    let mut chars = sql.chars().peekable();

    while let Some(ch) = chars.next() {
        if let Some(q) = quote {
            if ch == '\\' {
                let _ = chars.next();
                continue;
            }
            if ch == q {
                if q == '\'' && chars.peek() == Some(&'\'') {
                    let _ = chars.next();
                    continue;
                }
                quote = None;
            }
            continue;
        }

        if ch == '\'' || ch == '"' {
            if !current.is_empty() {
                tokens.push(current.trim_matches('`').to_string());
                current.clear();
            }
            quote = Some(ch);
            continue;
        }
        if ch == '-' && chars.peek() == Some(&'-') {
            let _ = chars.next();
            if !current.is_empty() {
                tokens.push(current.trim_matches('`').to_string());
                current.clear();
            }
            for skipped in chars.by_ref() {
                if skipped == '\n' {
                    break;
                }
            }
            continue;
        }
        if ch == '#' {
            if !current.is_empty() {
                tokens.push(current.trim_matches('`').to_string());
                current.clear();
            }
            for skipped in chars.by_ref() {
                if skipped == '\n' {
                    break;
                }
            }
            continue;
        }
        if ch == '/' && chars.peek() == Some(&'*') {
            let _ = chars.next();
            if !current.is_empty() {
                tokens.push(current.trim_matches('`').to_string());
                current.clear();
            }
            let mut prev = '\0';
            for skipped in chars.by_ref() {
                if prev == '*' && skipped == '/' {
                    break;
                }
                prev = skipped;
            }
            continue;
        }

        if ch.is_ascii_alphanumeric() || ch == '_' || ch == '$' || ch == '`' {
            current.push(ch);
        } else if !current.is_empty() {
            tokens.push(current.trim_matches('`').to_string());
            current.clear();
        }
    }
    if !current.is_empty() {
        tokens.push(current.trim_matches('`').to_string());
    }
    tokens
}

#[allow(dead_code)]
fn is_keyword(token: &str) -> bool {
    matches!(
        token.to_ascii_uppercase().as_str(),
        "SELECT" | "WHERE" | "SET" | "ON" | "USING" | "VALUES" | "INNER" | "LEFT" | "RIGHT"
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn classifies_sql() {
        assert!(is_safe_read_sql(" /* ok */ SELECT * FROM wp_posts"));
        assert!(is_write_sql(
            "UPDATE wp_posts SET post_title = 'x' WHERE ID = 1"
        ));
        assert!(is_write_sql("LOAD DATA INFILE 'x' INTO TABLE wp_posts"));
        assert!(!is_safe_read_sql(
            "SELECT * FROM wp_posts INTO OUTFILE '/tmp/wp-cow-leak'"
        ));
        assert!(!is_safe_read_sql(
            "SELECT post_title FROM wp_posts WHERE ID = 1 FOR UPDATE"
        ));
        assert!(!is_safe_read_sql(
            "SELECT post_title FROM wp_posts WHERE ID = 1 LOCK IN SHARE MODE"
        ));
        assert!(is_safe_read_sql(
            "SELECT * FROM wp_posts WHERE post_title = 'FOR UPDATE'"
        ));
        assert!(is_safe_read_sql(
            "SELECT * FROM wp_posts /* FOR UPDATE */ WHERE ID = 1"
        ));
    }

    #[test]
    fn expands_wordpress_content_group() {
        let tables = vec!["wp_posts".to_string()];
        let expanded = expand_wordpress_groups("wp_", &tables);
        assert!(expanded.contains(&"wp_postmeta".to_string()));
        assert!(expanded.contains(&"wp_term_relationships".to_string()));
    }

    #[test]
    fn extract_tables_preserves_wordpress_table_case_for_proxy_cow() {
        assert_eq!(
            extract_tables("UPDATE wp_posts SET post_title = 'x' WHERE ID = 1"),
            vec!["wp_posts".to_string()]
        );
        assert_eq!(
            extract_tables("INSERT INTO `wp_postmeta` (`post_id`) VALUES (1)"),
            vec!["wp_postmeta".to_string()]
        );
        assert_eq!(
            extract_tables(
                "SELECT * FROM wp_posts JOIN wp_postmeta ON wp_postmeta.post_id = wp_posts.ID"
            ),
            vec!["wp_postmeta".to_string(), "wp_posts".to_string()]
        );
    }
}

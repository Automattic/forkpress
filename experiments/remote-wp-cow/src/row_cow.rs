use anyhow::{anyhow, Result};
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use std::collections::{BTreeMap, BTreeSet};

pub type Row = Map<String, Value>;

#[derive(Debug, Clone, PartialEq, Eq, PartialOrd, Ord, Hash, Serialize, Deserialize)]
pub struct PkValue(pub String);

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum RowCowPlan {
    RowLevel(RowCowOp),
    PromoteTable { tables: Vec<String>, reason: String },
    Unsupported { reason: String },
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum RowCowOp {
    Select(RowSelect),
    Update(RowWrite),
    Delete(RowWrite),
    Insert(RowInsert),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RowSelect {
    pub table: String,
    pub pk_column: String,
    pub pk_values: Vec<PkValue>,
    pub projection: Projection,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RowWrite {
    pub table: String,
    pub pk_column: String,
    pub pk_values: Vec<PkValue>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RowInsert {
    pub table: String,
    pub pk_column: Option<String>,
    pub pk_values: Vec<PkValue>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Projection {
    All,
    Columns(Vec<String>),
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct CowQueryResult {
    pub ok: bool,
    pub error: String,
    pub rows: Vec<Row>,
    pub fields: Vec<String>,
    pub affected: i64,
}

impl CowQueryResult {
    pub fn ok(rows: Vec<Row>, fields: Vec<String>) -> Self {
        Self {
            affected: rows.len() as i64,
            ok: true,
            error: String::new(),
            rows,
            fields,
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub enum RowCowExecution {
    Select(CowQueryResult),
    PreparedLocalWrite {
        table: String,
        pk_column: Option<String>,
        pk_values: Vec<PkValue>,
        copied_rows: usize,
    },
    LocalOnlyInsert {
        table: String,
    },
    Fallback(RowCowPlan),
}

pub trait RowCowBackend {
    fn remote_select_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<CowQueryResult>;

    fn local_select_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<CowQueryResult>;

    fn local_upsert_rows(&mut self, table: &str, rows: &[Row]) -> Result<usize>;

    fn local_delete_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<usize>;

    fn local_tombstone_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<usize>;

    fn local_clear_tombstone_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<usize>;

    fn local_reserve_insert_pk(&mut self, _table: &str, _pk_column: Option<&str>) -> Result<()> {
        Ok(())
    }

    fn local_tombstones_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<BTreeSet<PkValue>>;
}

pub fn execute_row_cow<B: RowCowBackend>(
    backend: &mut B,
    sql_text: &str,
) -> Result<RowCowExecution> {
    match plan_sql(sql_text) {
        RowCowPlan::RowLevel(RowCowOp::Select(select)) => {
            Ok(RowCowExecution::Select(execute_select(backend, &select)?))
        }
        RowCowPlan::RowLevel(RowCowOp::Update(write)) => {
            let tombstones =
                backend.local_tombstones_by_pk(&write.table, &write.pk_column, &write.pk_values)?;
            let local =
                backend.local_select_by_pk(&write.table, &write.pk_column, &write.pk_values)?;
            let local_pks = local
                .rows
                .iter()
                .filter_map(|row| row_pk_value(row, &write.pk_column))
                .collect::<BTreeSet<_>>();
            let copy_values = write
                .pk_values
                .iter()
                .filter(|value| !tombstones.contains(*value) && !local_pks.contains(*value))
                .cloned()
                .collect::<Vec<_>>();
            let remote_rows =
                backend.remote_select_by_pk(&write.table, &write.pk_column, &copy_values)?;
            let rows = rows_matching_pks(remote_rows.rows, &write.pk_column, &copy_values);
            let copied_rows = backend.local_upsert_rows(&write.table, &rows)?;
            Ok(RowCowExecution::PreparedLocalWrite {
                table: write.table,
                pk_column: Some(write.pk_column),
                pk_values: write.pk_values,
                copied_rows,
            })
        }
        RowCowPlan::RowLevel(RowCowOp::Delete(write)) => {
            backend.local_tombstone_by_pk(&write.table, &write.pk_column, &write.pk_values)?;
            backend.local_delete_by_pk(&write.table, &write.pk_column, &write.pk_values)?;
            Ok(RowCowExecution::PreparedLocalWrite {
                table: write.table,
                pk_column: Some(write.pk_column),
                pk_values: write.pk_values,
                copied_rows: 0,
            })
        }
        RowCowPlan::RowLevel(RowCowOp::Insert(insert)) => {
            if insert.pk_values.is_empty() {
                backend.local_reserve_insert_pk(&insert.table, insert.pk_column.as_deref())?;
            }
            if let Some(pk_column) = &insert.pk_column {
                backend.local_clear_tombstone_by_pk(&insert.table, pk_column, &insert.pk_values)?;
            }
            Ok(RowCowExecution::LocalOnlyInsert {
                table: insert.table,
            })
        }
        fallback => Ok(RowCowExecution::Fallback(fallback)),
    }
}

fn execute_select<B: RowCowBackend>(backend: &mut B, select: &RowSelect) -> Result<CowQueryResult> {
    let tombstones =
        backend.local_tombstones_by_pk(&select.table, &select.pk_column, &select.pk_values)?;
    let local = backend.local_select_by_pk(&select.table, &select.pk_column, &select.pk_values)?;
    let local_pks = local
        .rows
        .iter()
        .filter_map(|row| row_pk_value(row, &select.pk_column))
        .collect::<BTreeSet<_>>();
    let missing_values = select
        .pk_values
        .iter()
        .filter(|value| !tombstones.contains(*value) && !local_pks.contains(*value))
        .cloned()
        .collect::<Vec<_>>();

    let remote = if missing_values.is_empty() {
        CowQueryResult::ok(Vec::new(), Vec::new())
    } else {
        let remote =
            backend.remote_select_by_pk(&select.table, &select.pk_column, &missing_values)?;
        let rows_to_materialize = remote
            .rows
            .iter()
            .filter(|row| {
                row_pk_value(row, &select.pk_column)
                    .map(|pk| !tombstones.contains(&pk) && !local_pks.contains(&pk))
                    .unwrap_or(false)
            })
            .cloned()
            .collect::<Vec<_>>();
        backend.local_upsert_rows(&select.table, &rows_to_materialize)?;
        remote
    };

    let mut merged = BTreeMap::<PkValue, Row>::new();
    for row in remote.rows {
        if let Some(pk) = row_pk_value(&row, &select.pk_column) {
            if !tombstones.contains(&pk) {
                merged.insert(pk, row);
            }
        }
    }
    for row in local.rows {
        if let Some(pk) = row_pk_value(&row, &select.pk_column) {
            merged.insert(pk, row);
        }
    }

    let mut rows = Vec::new();
    for value in &select.pk_values {
        if let Some(row) = merged.remove(value) {
            rows.push(row);
        }
    }
    rows.extend(merged.into_values());

    Ok(project_rows(rows, &select.projection))
}

fn rows_matching_pks(rows: Vec<Row>, pk_column: &str, pk_values: &[PkValue]) -> Vec<Row> {
    let allowed = pk_values.iter().collect::<BTreeSet<_>>();
    rows.into_iter()
        .filter(|row| {
            row_pk_value(row, pk_column)
                .as_ref()
                .map(|value| allowed.contains(value))
                .unwrap_or(false)
        })
        .collect()
}

fn project_rows(rows: Vec<Row>, projection: &Projection) -> CowQueryResult {
    match projection {
        Projection::All => {
            let mut fields = Vec::new();
            for row in &rows {
                for key in row.keys() {
                    if !fields.iter().any(|field| field == key) {
                        fields.push(key.clone());
                    }
                }
            }
            CowQueryResult::ok(rows, fields)
        }
        Projection::Columns(columns) => {
            let rows = rows
                .into_iter()
                .map(|row| {
                    let mut projected = Row::new();
                    for column in columns {
                        if let Some(value) = row_value_ci(&row, column) {
                            projected.insert(column.clone(), value.clone());
                        }
                    }
                    projected
                })
                .collect::<Vec<_>>();
            CowQueryResult::ok(rows, columns.clone())
        }
    }
}

pub fn plan_sql(sql_text: &str) -> RowCowPlan {
    let Some(tokens) = lex(sql_text) else {
        return RowCowPlan::Unsupported {
            reason: "malformed SQL".to_string(),
        };
    };
    if tokens.is_empty() {
        return RowCowPlan::Unsupported {
            reason: "empty SQL".to_string(),
        };
    }

    if token_is(&tokens[0], "SELECT") {
        return plan_select(&tokens);
    }
    if token_is(&tokens[0], "UPDATE") {
        return plan_update(&tokens);
    }
    if token_is(&tokens[0], "DELETE") {
        return plan_delete(&tokens);
    }
    if token_is(&tokens[0], "INSERT") {
        return plan_insert(&tokens);
    }

    RowCowPlan::Unsupported {
        reason: format!("{} is not a row-level COW statement", tokens[0].text),
    }
}

fn plan_select(tokens: &[Token]) -> RowCowPlan {
    let tables = extract_table_refs(tokens);
    if contains_keyword(tokens, "UNION") {
        return promote(tables, "UNION reads need table promotion");
    }
    if contains_keyword(tokens, "JOIN") {
        return promote(tables, "join reads need table promotion");
    }
    if contains_keyword(tokens, "GROUP") || contains_keyword(tokens, "HAVING") {
        return promote(tables, "grouped reads need table promotion");
    }
    if contains_keyword(tokens, "DISTINCT") {
        return promote(tables, "distinct reads need table promotion");
    }

    let Some(from_idx) = find_keyword(tokens, "FROM") else {
        return unsupported("SELECT without FROM");
    };
    if select_has_aggregate(&tokens[1..from_idx]) {
        return promote(tables, "aggregate reads need table promotion");
    }
    let Some((table, alias, next_idx)) = parse_table_ref(tokens, from_idx + 1) else {
        return unsupported("could not parse SELECT table");
    };
    if next_idx < tokens.len()
        && !token_is(&tokens[next_idx], "WHERE")
        && !is_statement_end(&tokens[next_idx])
    {
        return promote(tables, "multi-table SELECT needs table promotion");
    }
    let Some(where_idx) = find_keyword(tokens, "WHERE") else {
        return promote(vec![table], "SELECT without primary-key predicate");
    };
    let (predicate_tokens, trailing_tokens) =
        split_select_predicate_and_trailing(&tokens[where_idx + 1..]);
    let Some(predicate) = parse_pk_predicate(predicate_tokens) else {
        return promote(
            vec![table],
            "SELECT predicate is not primary-key equality or IN",
        );
    };
    if !qualifier_matches_table(predicate.qualifier.as_deref(), &table, alias.as_deref()) {
        return promote(vec![table], "SELECT predicate qualifier is ambiguous");
    }
    let Some(pk_column) = canonical_pk_column(&table, &predicate.column) else {
        return promote(
            vec![table],
            "SELECT predicate does not use a supported primary key",
        );
    };
    let Some(projection) = parse_projection(&tokens[1..from_idx], &table, alias.as_deref()) else {
        return promote(vec![table], "SELECT projection cannot be row-merged safely");
    };
    if !select_trailing_clauses_are_row_safe(trailing_tokens, predicate.values.len()) {
        return promote(vec![table], "ordered or limited reads need table promotion");
    }

    RowCowPlan::RowLevel(RowCowOp::Select(RowSelect {
        table,
        pk_column,
        pk_values: predicate.values,
        projection,
    }))
}

fn split_select_predicate_and_trailing(tokens: &[Token]) -> (&[Token], &[Token]) {
    let mut depth = 0_i32;
    for (idx, token) in tokens.iter().enumerate() {
        match token_symbol(token) {
            Some('(') => depth += 1,
            Some(')') => depth -= 1,
            _ => {}
        }
        if depth == 0 && (token_is(token, "ORDER") || token_is(token, "LIMIT")) {
            return (&tokens[..idx], &tokens[idx..]);
        }
    }
    (tokens, &[])
}

fn select_trailing_clauses_are_row_safe(tokens: &[Token], pk_values_len: usize) -> bool {
    let tokens = trim_statement_semicolons(tokens);
    if tokens.is_empty() {
        return true;
    }
    if pk_values_len != 1 {
        return false;
    }

    let mut idx = 0;
    if token_is(&tokens[idx], "ORDER") {
        idx += 1;
        if !tokens.get(idx).is_some_and(|token| token_is(token, "BY")) {
            return false;
        }
        idx += 1;
        while idx < tokens.len() && !token_is(&tokens[idx], "LIMIT") {
            idx += 1;
        }
    }

    if idx == tokens.len() {
        return true;
    }
    if !token_is(&tokens[idx], "LIMIT") {
        return false;
    }
    idx += 1;

    let Some(first) = tokens.get(idx).and_then(token_usize) else {
        return false;
    };
    idx += 1;
    let safe_limit = if tokens.get(idx).and_then(token_symbol) == Some(',') {
        idx += 1;
        let Some(count) = tokens.get(idx).and_then(token_usize) else {
            return false;
        };
        idx += 1;
        first == 0 && count == 1
    } else {
        first == 1
    };

    safe_limit && trim_statement_semicolons(&tokens[idx..]).is_empty()
}

fn plan_update(tokens: &[Token]) -> RowCowPlan {
    let tables = extract_table_refs(tokens);
    if contains_keyword(tokens, "JOIN") {
        return promote(tables, "join updates need table promotion");
    }
    if contains_keyword(tokens, "SELECT") {
        return promote(tables, "subquery updates need table promotion");
    }

    let mut table_idx = 1;
    while table_idx < tokens.len()
        && (token_is(&tokens[table_idx], "LOW_PRIORITY") || token_is(&tokens[table_idx], "IGNORE"))
    {
        table_idx += 1;
    }
    let Some((table, alias, next_idx)) = parse_table_ref(tokens, table_idx) else {
        return unsupported("could not parse UPDATE table");
    };
    let Some(set_idx) = find_keyword(tokens, "SET") else {
        return unsupported("UPDATE without SET");
    };
    if next_idx < set_idx {
        return promote(vec![table], "multi-table UPDATE needs table promotion");
    }
    let Some(where_idx) = find_keyword(tokens, "WHERE") else {
        return promote(vec![table], "UPDATE without primary-key predicate");
    };
    let predicate_tokens = &tokens[where_idx + 1..];
    let Some(predicate) = parse_pk_predicate(predicate_tokens) else {
        return promote(
            vec![table],
            "UPDATE predicate is not primary-key equality or IN",
        );
    };
    if !qualifier_matches_table(predicate.qualifier.as_deref(), &table, alias.as_deref()) {
        return promote(vec![table], "UPDATE predicate qualifier is ambiguous");
    }
    let Some(pk_column) = canonical_pk_column(&table, &predicate.column) else {
        return promote(
            vec![table],
            "UPDATE predicate does not use a supported primary key",
        );
    };

    RowCowPlan::RowLevel(RowCowOp::Update(RowWrite {
        table,
        pk_column,
        pk_values: predicate.values,
    }))
}

fn plan_delete(tokens: &[Token]) -> RowCowPlan {
    let tables = extract_table_refs(tokens);
    if contains_keyword(tokens, "JOIN") || contains_keyword(tokens, "USING") {
        return promote(tables, "multi-table DELETE needs table promotion");
    }
    if tokens.get(1).is_none_or(|token| !token_is(token, "FROM")) {
        return promote(tables, "multi-table DELETE needs table promotion");
    }
    let Some((table, alias, next_idx)) = parse_table_ref(tokens, 2) else {
        return unsupported("could not parse DELETE table");
    };
    let Some(where_idx) = find_keyword(tokens, "WHERE") else {
        return promote(vec![table], "DELETE without primary-key predicate");
    };
    if next_idx < where_idx {
        return promote(vec![table], "multi-table DELETE needs table promotion");
    }
    let predicate_tokens = &tokens[where_idx + 1..];
    let Some(predicate) = parse_pk_predicate(predicate_tokens) else {
        return promote(
            vec![table],
            "DELETE predicate is not primary-key equality or IN",
        );
    };
    if !qualifier_matches_table(predicate.qualifier.as_deref(), &table, alias.as_deref()) {
        return promote(vec![table], "DELETE predicate qualifier is ambiguous");
    }
    let Some(pk_column) = canonical_pk_column(&table, &predicate.column) else {
        return promote(
            vec![table],
            "DELETE predicate does not use a supported primary key",
        );
    };

    RowCowPlan::RowLevel(RowCowOp::Delete(RowWrite {
        table,
        pk_column,
        pk_values: predicate.values,
    }))
}

fn plan_insert(tokens: &[Token]) -> RowCowPlan {
    let tables = extract_table_refs(tokens);
    if contains_keyword(tokens, "SELECT") {
        return promote(tables, "INSERT ... SELECT needs table promotion");
    }

    let mut idx = 1;
    while idx < tokens.len() && token_is(&tokens[idx], "IGNORE") {
        idx += 1;
    }
    if idx < tokens.len() && token_is(&tokens[idx], "INTO") {
        idx += 1;
    }
    let Some((table, alias, next_idx)) = parse_table_ref(tokens, idx) else {
        return unsupported("could not parse INSERT table");
    };
    if alias.is_some() {
        return unsupported("INSERT aliases are not row-level safe");
    }
    if !insert_has_values_clause(tokens, next_idx) {
        return unsupported("INSERT without VALUES is not row-level safe");
    }
    let (pk_column, pk_values) = expected_pk_for_table(&table)
        .map(|pk_column| {
            (
                Some(pk_column.to_string()),
                parse_insert_pk_values(tokens, next_idx, pk_column),
            )
        })
        .unwrap_or((None, Vec::new()));
    RowCowPlan::RowLevel(RowCowOp::Insert(RowInsert {
        table,
        pk_column,
        pk_values,
    }))
}

fn insert_has_values_clause(tokens: &[Token], mut idx: usize) -> bool {
    if idx >= tokens.len() {
        return false;
    }
    if tokens.get(idx).and_then(token_symbol) == Some('(') {
        let mut depth = 0_i32;
        while idx < tokens.len() {
            match token_symbol(&tokens[idx]) {
                Some('(') => {
                    depth += 1;
                    idx += 1;
                }
                Some(')') => {
                    depth -= 1;
                    idx += 1;
                    if depth == 0 {
                        break;
                    }
                }
                _ => idx += 1,
            }
        }
    }
    while idx < tokens.len() && token_symbol(&tokens[idx]) == Some(';') {
        idx += 1;
    }
    idx < tokens.len() && (token_is(&tokens[idx], "VALUES") || token_is(&tokens[idx], "VALUE"))
}

fn parse_insert_pk_values(tokens: &[Token], mut idx: usize, pk_column: &str) -> Vec<PkValue> {
    if tokens.get(idx).and_then(token_symbol) != Some('(') {
        return Vec::new();
    }
    idx += 1;

    let mut columns = Vec::new();
    loop {
        let Some(column) = tokens.get(idx).and_then(token_identifier) else {
            return Vec::new();
        };
        columns.push(column.to_string());
        idx += 1;
        match tokens.get(idx).and_then(token_symbol) {
            Some(',') => idx += 1,
            Some(')') => {
                idx += 1;
                break;
            }
            _ => return Vec::new(),
        }
    }

    let Some(pk_idx) = columns
        .iter()
        .position(|column| column.eq_ignore_ascii_case(pk_column))
    else {
        return Vec::new();
    };

    while idx < tokens.len()
        && !token_is(&tokens[idx], "VALUES")
        && !token_is(&tokens[idx], "VALUE")
    {
        idx += 1;
    }
    if idx >= tokens.len() {
        return Vec::new();
    }
    idx += 1;

    let mut pk_values = Vec::new();
    while idx < tokens.len() {
        if token_symbol(&tokens[idx]) != Some('(') {
            break;
        }
        idx += 1;
        let mut value_idx = 0;
        loop {
            if value_idx == pk_idx {
                if let Some((value, _next_idx)) = parse_pk_value(tokens, idx) {
                    pk_values.push(value);
                }
            }

            idx = skip_insert_value(tokens, idx);
            match tokens.get(idx).and_then(token_symbol) {
                Some(',') => {
                    value_idx += 1;
                    idx += 1;
                }
                Some(')') => {
                    idx += 1;
                    break;
                }
                _ => return Vec::new(),
            }
        }
        match tokens.get(idx).and_then(token_symbol) {
            Some(',') => idx += 1,
            _ => break,
        }
    }

    dedupe_pk_values(pk_values)
}

fn skip_insert_value(tokens: &[Token], mut idx: usize) -> usize {
    let mut depth = 0_i32;
    while idx < tokens.len() {
        match token_symbol(&tokens[idx]) {
            Some('(') => {
                depth += 1;
                idx += 1;
            }
            Some(')') if depth == 0 => break,
            Some(')') => {
                depth -= 1;
                idx += 1;
            }
            Some(',') if depth == 0 => break,
            _ => idx += 1,
        }
    }
    idx
}

fn parse_projection(tokens: &[Token], table: &str, alias: Option<&str>) -> Option<Projection> {
    if tokens.len() == 1 && token_symbol(&tokens[0]) == Some('*') {
        return Some(Projection::All);
    }
    if tokens
        .iter()
        .any(|token| token_symbol(token) == Some('(') || token_symbol(token) == Some(')'))
    {
        return None;
    }

    let parts = split_top_level_commas(tokens);
    let mut columns = Vec::new();
    for part in &parts {
        if part.len() == 3
            && token_symbol(&part[1]) == Some('.')
            && token_symbol(&part[2]) == Some('*')
        {
            let qualifier = token_identifier(&part[0])?;
            if parts.len() == 1 && qualifier_matches_table(Some(qualifier), table, alias) {
                return Some(Projection::All);
            }
            return None;
        }
        let mut idx = 0;
        let Some((column, next_idx)) = parse_column_ref(part, idx) else {
            return None;
        };
        if !qualifier_matches_table(column.qualifier.as_deref(), table, alias) {
            return None;
        }
        idx = next_idx;
        if idx < part.len() {
            return None;
        }
        columns.push(column.name);
    }
    if columns.is_empty() {
        None
    } else {
        Some(Projection::Columns(columns))
    }
}

fn select_has_aggregate(tokens: &[Token]) -> bool {
    const AGGREGATES: &[&str] = &["COUNT", "SUM", "AVG", "MIN", "MAX", "GROUP_CONCAT"];
    tokens.windows(2).any(|window| {
        AGGREGATES.iter().any(|kw| token_is(&window[0], kw))
            && token_symbol(&window[1]) == Some('(')
    })
}

#[derive(Debug)]
struct PkPredicate {
    qualifier: Option<String>,
    column: String,
    values: Vec<PkValue>,
}

fn parse_pk_predicate(tokens: &[Token]) -> Option<PkPredicate> {
    let tokens = trim_outer_parens(tokens);
    let (column, mut idx) = parse_column_ref(tokens, 0)?;
    if idx >= tokens.len() {
        return None;
    }

    let values = if token_symbol(&tokens[idx]) == Some('=') {
        idx += 1;
        let (value, next_idx) = parse_pk_value(tokens, idx)?;
        idx = next_idx;
        vec![value]
    } else if token_is(&tokens[idx], "IN") {
        idx += 1;
        if tokens.get(idx).and_then(token_symbol) != Some('(') {
            return None;
        }
        idx += 1;
        let mut values = Vec::new();
        loop {
            let (value, next_idx) = parse_pk_value(tokens, idx)?;
            values.push(value);
            idx = next_idx;
            match tokens.get(idx).and_then(token_symbol) {
                Some(',') => idx += 1,
                Some(')') => {
                    idx += 1;
                    break;
                }
                _ => return None,
            }
        }
        values
    } else {
        return None;
    };

    while idx < tokens.len() && token_symbol(&tokens[idx]) == Some(';') {
        idx += 1;
    }
    if idx != tokens.len() || values.is_empty() {
        return None;
    }

    Some(PkPredicate {
        qualifier: column.qualifier,
        column: column.name,
        values: dedupe_pk_values(values),
    })
}

fn dedupe_pk_values(values: Vec<PkValue>) -> Vec<PkValue> {
    let mut deduped = Vec::new();
    let mut seen = BTreeSet::new();
    for value in values {
        if seen.insert(value.clone()) {
            deduped.push(value);
        }
    }
    deduped
}

#[derive(Debug)]
struct ColumnRef {
    qualifier: Option<String>,
    name: String,
}

fn parse_column_ref(tokens: &[Token], idx: usize) -> Option<(ColumnRef, usize)> {
    let first = token_identifier(tokens.get(idx)?)?;
    let next_idx = idx + 1;
    if next_idx + 1 < tokens.len() && token_symbol(&tokens[next_idx]) == Some('.') {
        let second = token_identifier(&tokens[next_idx + 1])?;
        if next_idx + 2 < tokens.len() && token_symbol(&tokens[next_idx + 2]) == Some('.') {
            return None;
        }
        return Some((
            ColumnRef {
                qualifier: Some(first.to_string()),
                name: second.to_string(),
            },
            next_idx + 2,
        ));
    }
    Some((
        ColumnRef {
            qualifier: None,
            name: first.to_string(),
        },
        next_idx,
    ))
}

fn qualifier_matches_table(qualifier: Option<&str>, table: &str, alias: Option<&str>) -> bool {
    let Some(qualifier) = qualifier else {
        return true;
    };
    qualifier.eq_ignore_ascii_case(table)
        || alias
            .map(|alias| qualifier.eq_ignore_ascii_case(alias))
            .unwrap_or(false)
}

fn parse_pk_value(tokens: &[Token], idx: usize) -> Option<(PkValue, usize)> {
    let token = tokens.get(idx)?;
    match &token.kind {
        TokenKind::Number | TokenKind::String => Some((PkValue(token.text.clone()), idx + 1)),
        _ => None,
    }
}

fn trim_outer_parens(mut tokens: &[Token]) -> &[Token] {
    loop {
        if tokens.len() < 2
            || token_symbol(&tokens[0]) != Some('(')
            || token_symbol(&tokens[tokens.len() - 1]) != Some(')')
        {
            return tokens;
        }
        let mut depth = 0_i32;
        let mut wraps = true;
        for (idx, token) in tokens.iter().enumerate() {
            match token_symbol(token) {
                Some('(') => depth += 1,
                Some(')') => {
                    depth -= 1;
                    if depth == 0 && idx != tokens.len() - 1 {
                        wraps = false;
                        break;
                    }
                }
                _ => {}
            }
            if depth < 0 {
                wraps = false;
                break;
            }
        }
        if !wraps || depth != 0 {
            return tokens;
        }
        tokens = &tokens[1..tokens.len() - 1];
    }
}

fn parse_table_ref(tokens: &[Token], idx: usize) -> Option<(String, Option<String>, usize)> {
    let first = token_identifier(tokens.get(idx)?)?;
    let mut table = first.to_string();
    let mut next_idx = idx + 1;
    if next_idx + 1 < tokens.len() && token_symbol(&tokens[next_idx]) == Some('.') {
        let second = token_identifier(&tokens[next_idx + 1])?;
        table = second.to_string();
        next_idx += 2;
    }

    let mut alias = None;
    if next_idx < tokens.len() && token_is(&tokens[next_idx], "AS") {
        next_idx += 1;
        if let Some(value) = tokens.get(next_idx).and_then(token_identifier) {
            alias = Some(value.to_string());
            next_idx += 1;
        }
    } else if next_idx < tokens.len() {
        let token = &tokens[next_idx];
        if token_identifier(token).is_some()
            && !is_table_boundary_keyword(token)
            && !token_is(token, "SET")
        {
            alias = Some(token.text.clone());
            next_idx += 1;
        }
    }

    Some((table, alias, next_idx))
}

fn is_table_boundary_keyword(token: &Token) -> bool {
    [
        "WHERE", "JOIN", "INNER", "LEFT", "RIGHT", "FULL", "CROSS", "ON", "USING", "ORDER",
        "GROUP", "HAVING", "LIMIT", "SET", "VALUES", "VALUE",
    ]
    .iter()
    .any(|kw| token_is(token, kw))
}

fn split_top_level_commas(tokens: &[Token]) -> Vec<&[Token]> {
    let mut out = Vec::new();
    let mut start = 0;
    let mut depth = 0_i32;
    for (idx, token) in tokens.iter().enumerate() {
        match token_symbol(token) {
            Some('(') => depth += 1,
            Some(')') => depth -= 1,
            Some(',') if depth == 0 => {
                out.push(&tokens[start..idx]);
                start = idx + 1;
            }
            _ => {}
        }
    }
    out.push(&tokens[start..]);
    out.into_iter().filter(|part| !part.is_empty()).collect()
}

fn extract_table_refs(tokens: &[Token]) -> Vec<String> {
    let mut tables = Vec::new();
    let mut idx = 0;
    while idx < tokens.len() {
        if token_is(&tokens[idx], "FROM") {
            idx = collect_comma_table_refs(tokens, idx + 1, &mut tables);
            continue;
        }

        let table_idx = if token_is(&tokens[idx], "JOIN")
            || token_is(&tokens[idx], "INTO")
            || token_is(&tokens[idx], "TABLE")
        {
            Some(idx + 1)
        } else if token_is(&tokens[idx], "UPDATE") {
            let mut next = idx + 1;
            while next < tokens.len()
                && (token_is(&tokens[next], "LOW_PRIORITY") || token_is(&tokens[next], "IGNORE"))
            {
                next += 1;
            }
            Some(next)
        } else {
            None
        };

        if let Some(table_idx) = table_idx {
            if let Some((table, _alias, _next_idx)) = parse_table_ref(tokens, table_idx) {
                push_table_ref(&mut tables, table);
            }
        }
        idx += 1;
    }
    tables
}

fn collect_comma_table_refs(tokens: &[Token], mut idx: usize, tables: &mut Vec<String>) -> usize {
    while let Some((table, _alias, next_idx)) = parse_table_ref(tokens, idx) {
        push_table_ref(tables, table);
        idx = next_idx;
        if tokens.get(idx).and_then(token_symbol) == Some(',') {
            idx += 1;
            continue;
        }
        break;
    }
    idx
}

fn push_table_ref(tables: &mut Vec<String>, table: String) {
    if !tables.iter().any(|existing| existing == &table) {
        tables.push(table);
    }
}

pub fn is_supported_pk_column(column: &str) -> bool {
    [
        "ID",
        "option_id",
        "option_name",
        "umeta_id",
        "meta_id",
        "term_id",
        "term_taxonomy_id",
        "object_id",
        "comment_ID",
        "link_id",
    ]
    .iter()
    .any(|candidate| candidate.eq_ignore_ascii_case(column))
}

pub fn expected_pk_for_table(table: &str) -> Option<&'static str> {
    let lower = table.to_ascii_lowercase();
    if lower == "posts" || lower.ends_with("_posts") {
        return Some("ID");
    }
    if lower == "users" || lower.ends_with("_users") {
        return Some("ID");
    }
    if lower == "options" || lower.ends_with("_options") {
        return Some("option_id");
    }
    if lower == "usermeta" || lower.ends_with("_usermeta") {
        return Some("umeta_id");
    }
    if lower == "postmeta" || lower.ends_with("_postmeta") {
        return Some("meta_id");
    }
    if lower == "commentmeta" || lower.ends_with("_commentmeta") {
        return Some("meta_id");
    }
    if lower == "termmeta" || lower.ends_with("_termmeta") {
        return Some("meta_id");
    }
    if lower == "terms" || lower.ends_with("_terms") {
        return Some("term_id");
    }
    if lower == "term_taxonomy" || lower.ends_with("_term_taxonomy") {
        return Some("term_taxonomy_id");
    }
    if lower == "term_relationships" || lower.ends_with("_term_relationships") {
        return Some("object_id");
    }
    if lower == "comments" || lower.ends_with("_comments") {
        return Some("comment_ID");
    }
    if lower == "links" || lower.ends_with("_links") {
        return Some("link_id");
    }
    None
}

pub fn auto_increment_pk_for_table(table: &str) -> Option<&'static str> {
    let pk = expected_pk_for_table(table)?;
    let lower = table.to_ascii_lowercase();
    if lower == "term_relationships" || lower.ends_with("_term_relationships") {
        return None;
    }
    Some(pk)
}

pub fn is_auto_increment_pk_for_table(table: &str, pk_column: &str) -> bool {
    auto_increment_pk_for_table(table)
        .map(|expected| expected.eq_ignore_ascii_case(pk_column))
        .unwrap_or(false)
}

fn canonical_pk_column(table: &str, column: &str) -> Option<String> {
    let lower = table.to_ascii_lowercase();
    if (lower == "options" || lower.ends_with("_options"))
        && column.eq_ignore_ascii_case("option_name")
    {
        return Some("option_name".to_string());
    }

    if let Some(expected) = expected_pk_for_table(table) {
        if expected.eq_ignore_ascii_case(column) {
            return Some(expected.to_string());
        }
        return None;
    }

    if !is_supported_pk_column(column) {
        return None;
    }

    [
        "ID",
        "option_id",
        "umeta_id",
        "meta_id",
        "term_id",
        "term_taxonomy_id",
        "object_id",
        "comment_ID",
        "link_id",
    ]
    .iter()
    .find(|candidate| candidate.eq_ignore_ascii_case(column))
    .map(|candidate| (*candidate).to_string())
}

pub fn row_pk_value(row: &Row, pk_column: &str) -> Option<PkValue> {
    row_value_ci(row, pk_column).and_then(value_to_pk)
}

fn row_value_ci<'a>(row: &'a Row, column: &str) -> Option<&'a Value> {
    row.get(column).or_else(|| {
        row.iter()
            .find(|(key, _value)| key.eq_ignore_ascii_case(column))
            .map(|(_key, value)| value)
    })
}

fn value_to_pk(value: &Value) -> Option<PkValue> {
    match value {
        Value::String(value) => Some(PkValue(value.clone())),
        Value::Number(value) => Some(PkValue(value.to_string())),
        _ => None,
    }
}

fn promote(tables: Vec<String>, reason: &str) -> RowCowPlan {
    RowCowPlan::PromoteTable {
        tables,
        reason: reason.to_string(),
    }
}

fn unsupported(reason: &str) -> RowCowPlan {
    RowCowPlan::Unsupported {
        reason: reason.to_string(),
    }
}

fn find_keyword(tokens: &[Token], keyword: &str) -> Option<usize> {
    tokens.iter().position(|token| token_is(token, keyword))
}

fn contains_keyword(tokens: &[Token], keyword: &str) -> bool {
    find_keyword(tokens, keyword).is_some()
}

fn is_statement_end(token: &Token) -> bool {
    token_symbol(token) == Some(';')
}

fn trim_statement_semicolons(mut tokens: &[Token]) -> &[Token] {
    while tokens
        .last()
        .and_then(token_symbol)
        .is_some_and(|symbol| symbol == ';')
    {
        tokens = &tokens[..tokens.len() - 1];
    }
    tokens
}

#[derive(Debug, Clone, PartialEq, Eq)]
enum TokenKind {
    Word,
    Number,
    String,
    Symbol(char),
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct Token {
    text: String,
    kind: TokenKind,
}

fn token_is(token: &Token, keyword: &str) -> bool {
    matches!(token.kind, TokenKind::Word) && token.text.eq_ignore_ascii_case(keyword)
}

fn token_identifier(token: &Token) -> Option<&str> {
    match token.kind {
        TokenKind::Word => Some(token.text.as_str()),
        _ => None,
    }
}

fn token_symbol(token: &Token) -> Option<char> {
    match token.kind {
        TokenKind::Symbol(ch) => Some(ch),
        _ => None,
    }
}

fn token_usize(token: &Token) -> Option<usize> {
    if matches!(token.kind, TokenKind::Number) {
        token.text.parse::<usize>().ok()
    } else {
        None
    }
}

fn lex(sql: &str) -> Option<Vec<Token>> {
    let chars = sql.chars().collect::<Vec<_>>();
    let mut tokens = Vec::new();
    let mut idx = 0;

    while idx < chars.len() {
        let ch = chars[idx];
        if ch.is_whitespace() {
            idx += 1;
            continue;
        }
        if ch == '-' && chars.get(idx + 1) == Some(&'-') {
            idx += 2;
            while idx < chars.len() && chars[idx] != '\n' {
                idx += 1;
            }
            continue;
        }
        if ch == '#' {
            idx += 1;
            while idx < chars.len() && chars[idx] != '\n' {
                idx += 1;
            }
            continue;
        }
        if ch == '/' && chars.get(idx + 1) == Some(&'*') {
            idx += 2;
            while idx + 1 < chars.len() && !(chars[idx] == '*' && chars[idx + 1] == '/') {
                idx += 1;
            }
            if idx + 1 >= chars.len() {
                return None;
            }
            idx = (idx + 2).min(chars.len());
            continue;
        }
        if ch == '`' {
            idx += 1;
            let mut text = String::new();
            let mut closed = false;
            while idx < chars.len() {
                if chars[idx] == '`' {
                    if chars.get(idx + 1) == Some(&'`') {
                        text.push('`');
                        idx += 2;
                        continue;
                    }
                    idx += 1;
                    closed = true;
                    break;
                }
                text.push(chars[idx]);
                idx += 1;
            }
            if !closed {
                return None;
            }
            tokens.push(Token {
                text,
                kind: TokenKind::Word,
            });
            continue;
        }
        if ch == '\'' || ch == '"' {
            let quote = ch;
            idx += 1;
            let mut text = String::new();
            let mut closed = false;
            while idx < chars.len() {
                if chars[idx] == '\\' {
                    if let Some(next) = chars.get(idx + 1) {
                        text.push(*next);
                        idx += 2;
                        continue;
                    }
                    return None;
                }
                if chars[idx] == quote {
                    if chars.get(idx + 1) == Some(&quote) {
                        text.push(quote);
                        idx += 2;
                        continue;
                    }
                    idx += 1;
                    closed = true;
                    break;
                }
                text.push(chars[idx]);
                idx += 1;
            }
            if !closed {
                return None;
            }
            tokens.push(Token {
                text,
                kind: TokenKind::String,
            });
            continue;
        }
        if ch.is_ascii_digit() {
            let start = idx;
            idx += 1;
            while idx < chars.len() && chars[idx].is_ascii_digit() {
                idx += 1;
            }
            tokens.push(Token {
                text: chars[start..idx].iter().collect(),
                kind: TokenKind::Number,
            });
            continue;
        }
        if ch.is_ascii_alphabetic() || ch == '_' || ch == '$' {
            let start = idx;
            idx += 1;
            while idx < chars.len()
                && (chars[idx].is_ascii_alphanumeric() || chars[idx] == '_' || chars[idx] == '$')
            {
                idx += 1;
            }
            tokens.push(Token {
                text: chars[start..idx].iter().collect(),
                kind: TokenKind::Word,
            });
            continue;
        }

        tokens.push(Token {
            text: ch.to_string(),
            kind: TokenKind::Symbol(ch),
        });
        idx += 1;
    }

    Some(tokens)
}

pub fn quote_identifier(identifier: &str) -> Result<String> {
    if identifier.is_empty()
        || !identifier
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '$')
    {
        return Err(anyhow!("unsafe SQL identifier {identifier}"));
    }
    Ok(format!("`{}`", identifier.replace('`', "``")))
}

pub fn pk_values_where_sql(pk_column: &str, pk_values: &[PkValue]) -> Result<String> {
    let column = quote_identifier(pk_column)?;
    if pk_values.is_empty() {
        return Ok("1=0".to_string());
    }
    let values = pk_values
        .iter()
        .map(|value| format!("'{}'", mysql_string_literal(&value.0)))
        .collect::<Vec<_>>()
        .join(", ");
    Ok(format!("{column} IN ({values})"))
}

pub fn select_all_by_pk_sql(table: &str, pk_column: &str, pk_values: &[PkValue]) -> Result<String> {
    Ok(format!(
        "SELECT * FROM {} WHERE {};",
        quote_identifier(table)?,
        pk_values_where_sql(pk_column, pk_values)?
    ))
}

pub fn mysql_string_literal(value: &str) -> String {
    value.replace('\\', "\\\\").replace('\'', "\\'")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(Debug, Clone, PartialEq, Eq)]
    enum RemoteCall {
        Select {
            table: String,
            pk_column: String,
            pk_values: Vec<PkValue>,
        },
    }

    #[derive(Debug, Default)]
    struct FakeCowBackend {
        remote: BTreeMap<String, BTreeMap<PkValue, Row>>,
        local: BTreeMap<String, BTreeMap<PkValue, Row>>,
        tombstones: BTreeSet<(String, String, PkValue)>,
        remote_calls: Vec<RemoteCall>,
        reserved_inserts: Vec<(String, Option<String>)>,
    }

    impl FakeCowBackend {
        fn insert_remote(
            &mut self,
            table: &str,
            pk_column: &str,
            pk: &str,
            pairs: &[(&str, &str)],
        ) {
            let row = row(pk_column, pk, pairs);
            self.remote
                .entry(table.to_string())
                .or_default()
                .insert(PkValue(pk.to_string()), row);
        }

        fn insert_local(&mut self, table: &str, pk_column: &str, pk: &str, pairs: &[(&str, &str)]) {
            let row = row(pk_column, pk, pairs);
            self.local
                .entry(table.to_string())
                .or_default()
                .insert(PkValue(pk.to_string()), row);
        }

        fn assert_no_remote_writes(&self) {
            assert!(self
                .remote_calls
                .iter()
                .all(|call| matches!(call, RemoteCall::Select { .. })));
        }

        fn remote_select_values(&self) -> Vec<Vec<PkValue>> {
            self.remote_calls
                .iter()
                .map(|call| match call {
                    RemoteCall::Select { pk_values, .. } => pk_values.clone(),
                })
                .collect()
        }
    }

    impl RowCowBackend for FakeCowBackend {
        fn remote_select_by_pk(
            &mut self,
            table: &str,
            pk_column: &str,
            pk_values: &[PkValue],
        ) -> Result<CowQueryResult> {
            self.remote_calls.push(RemoteCall::Select {
                table: table.to_string(),
                pk_column: pk_column.to_string(),
                pk_values: pk_values.to_vec(),
            });
            let rows = select_from_table(self.remote.get(table), pk_values);
            Ok(CowQueryResult::ok(rows, Vec::new()))
        }

        fn local_select_by_pk(
            &mut self,
            table: &str,
            _pk_column: &str,
            pk_values: &[PkValue],
        ) -> Result<CowQueryResult> {
            let rows = select_from_table(self.local.get(table), pk_values);
            Ok(CowQueryResult::ok(rows, Vec::new()))
        }

        fn local_upsert_rows(&mut self, table: &str, rows: &[Row]) -> Result<usize> {
            let table_rows = self.local.entry(table.to_string()).or_default();
            for row in rows {
                let pk_column = if (table == "options" || table.ends_with("_options"))
                    && row_value_ci(row, "option_name").is_some()
                {
                    "option_name"
                } else {
                    expected_pk_for_table(table).unwrap()
                };
                let pk = row_pk_value(row, pk_column).unwrap();
                table_rows.insert(pk, row.clone());
            }
            Ok(rows.len())
        }

        fn local_delete_by_pk(
            &mut self,
            table: &str,
            _pk_column: &str,
            pk_values: &[PkValue],
        ) -> Result<usize> {
            let Some(rows) = self.local.get_mut(table) else {
                return Ok(0);
            };
            let mut deleted = 0;
            for pk in pk_values {
                if rows.remove(pk).is_some() {
                    deleted += 1;
                }
            }
            Ok(deleted)
        }

        fn local_tombstone_by_pk(
            &mut self,
            table: &str,
            pk_column: &str,
            pk_values: &[PkValue],
        ) -> Result<usize> {
            let mut added = 0;
            for value in pk_values {
                if self
                    .tombstones
                    .insert((table.to_string(), pk_column.to_string(), value.clone()))
                {
                    added += 1;
                }
            }
            Ok(added)
        }

        fn local_clear_tombstone_by_pk(
            &mut self,
            table: &str,
            pk_column: &str,
            pk_values: &[PkValue],
        ) -> Result<usize> {
            let mut removed = 0;
            for value in pk_values {
                if self.tombstones.remove(&(
                    table.to_string(),
                    pk_column.to_string(),
                    value.clone(),
                )) {
                    removed += 1;
                }
            }
            Ok(removed)
        }

        fn local_reserve_insert_pk(&mut self, table: &str, pk_column: Option<&str>) -> Result<()> {
            self.reserved_inserts
                .push((table.to_string(), pk_column.map(str::to_string)));
            Ok(())
        }

        fn local_tombstones_by_pk(
            &mut self,
            table: &str,
            pk_column: &str,
            pk_values: &[PkValue],
        ) -> Result<BTreeSet<PkValue>> {
            Ok(pk_values
                .iter()
                .filter(|value| {
                    self.tombstones.contains(&(
                        table.to_string(),
                        pk_column.to_string(),
                        (*value).clone(),
                    ))
                })
                .cloned()
                .collect())
        }
    }

    fn select_from_table(
        table: Option<&BTreeMap<PkValue, Row>>,
        pk_values: &[PkValue],
    ) -> Vec<Row> {
        let Some(table) = table else {
            return Vec::new();
        };
        pk_values
            .iter()
            .filter_map(|value| table.get(value).cloned())
            .collect()
    }

    fn row(pk_column: &str, pk: &str, pairs: &[(&str, &str)]) -> Row {
        let mut row = Row::new();
        row.insert(pk_column.to_string(), Value::String(pk.to_string()));
        for (key, value) in pairs {
            row.insert((*key).to_string(), Value::String((*value).to_string()));
        }
        row
    }

    fn assert_not_row_level(sql: &str) {
        assert!(
            !matches!(plan_sql(sql), RowCowPlan::RowLevel(_)),
            "{sql} was incorrectly planned as row-level safe"
        );
    }

    #[test]
    fn plans_supported_wordpress_primary_keys() {
        let cases = [
            ("wp_posts", "ID"),
            ("wp_options", "option_id"),
            ("wp_usermeta", "umeta_id"),
            ("wp_postmeta", "meta_id"),
            ("wp_terms", "term_id"),
            ("wp_term_taxonomy", "term_taxonomy_id"),
            ("wp_term_relationships", "object_id"),
            ("wp_comments", "comment_ID"),
            ("wp_links", "link_id"),
        ];

        for (table, pk) in cases {
            let sql = format!("SELECT * FROM `{table}` WHERE `{pk}` IN (1, 2)");
            let RowCowPlan::RowLevel(RowCowOp::Select(select)) = plan_sql(&sql) else {
                panic!("{sql} was not planned as a row-level select");
            };
            assert_eq!(select.table, table);
            assert_eq!(select.pk_column, pk);
            assert_eq!(
                select.pk_values,
                vec![PkValue("1".to_string()), PkValue("2".to_string())]
            );
        }

        assert_eq!(auto_increment_pk_for_table("wp_posts"), Some("ID"));
        assert_eq!(auto_increment_pk_for_table("wp_term_relationships"), None);
    }

    #[test]
    fn plans_wordpress_options_by_unique_option_name() {
        let RowCowPlan::RowLevel(RowCowOp::Update(write)) =
            plan_sql("UPDATE wp_options SET option_value = 'local' WHERE option_name = 'blogname'")
        else {
            panic!("options writes by option_name should be row-level safe");
        };
        assert_eq!(write.table, "wp_options");
        assert_eq!(write.pk_column, "option_name");
        assert_eq!(write.pk_values, vec![PkValue("blogname".to_string())]);

        let RowCowPlan::RowLevel(RowCowOp::Delete(write)) =
            plan_sql("DELETE FROM wp_options WHERE option_name = '_transient_example'")
        else {
            panic!("options deletes by option_name should be row-level safe");
        };
        assert_eq!(write.pk_column, "option_name");

        let RowCowPlan::RowLevel(RowCowOp::Select(select)) =
            plan_sql("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'")
        else {
            panic!("options reads by option_name should be row-level safe");
        };
        assert_eq!(select.pk_column, "option_name");
        assert_eq!(
            select.projection,
            Projection::Columns(vec!["option_value".to_string()])
        );
    }

    #[test]
    fn accepts_matching_table_or_alias_qualified_primary_keys() {
        let RowCowPlan::RowLevel(RowCowOp::Select(select)) =
            plan_sql("SELECT p.ID FROM wp_posts p WHERE p.ID = 1")
        else {
            panic!("matching alias-qualified predicate should be row-level safe");
        };
        assert_eq!(select.table, "wp_posts");
        assert_eq!(select.pk_column, "ID");
        assert_eq!(
            select.projection,
            Projection::Columns(vec!["ID".to_string()])
        );

        let RowCowPlan::RowLevel(RowCowOp::Update(write)) =
            plan_sql("UPDATE wp_posts AS p SET post_title = 'changed' WHERE p.ID = 1")
        else {
            panic!("matching alias-qualified update should be row-level safe");
        };
        assert_eq!(write.table, "wp_posts");
        assert_eq!(write.pk_values, vec![PkValue("1".to_string())]);
    }

    #[test]
    fn primary_key_single_row_selects_allow_safe_order_and_limit_clauses() {
        for sql in [
            "SELECT * FROM wp_posts WHERE ID = 74 LIMIT 1",
            "SELECT * FROM wp_posts WHERE ID = 74 LIMIT 0, 1",
            "SELECT * FROM wp_posts WHERE ID = 74 ORDER BY post_date DESC LIMIT 1",
        ] {
            let RowCowPlan::RowLevel(RowCowOp::Select(select)) = plan_sql(sql) else {
                panic!("{sql} should be row-level safe");
            };
            assert_eq!(select.table, "wp_posts");
            assert_eq!(select.pk_column, "ID");
            assert_eq!(select.pk_values, vec![PkValue("74".to_string())]);
        }

        assert_not_row_level("SELECT * FROM wp_posts WHERE ID = 74 LIMIT 1, 1");
        assert_not_row_level("SELECT * FROM wp_posts WHERE ID IN (74, 75) LIMIT 1");
    }

    #[test]
    fn update_copy_up_fetches_only_affected_primary_keys() {
        let mut backend = FakeCowBackend::default();
        backend.insert_remote("wp_posts", "ID", "1", &[("post_title", "one")]);
        backend.insert_remote("wp_posts", "ID", "2", &[("post_title", "two")]);
        backend.insert_remote("wp_posts", "ID", "3", &[("post_title", "three")]);

        let execution = execute_row_cow(
            &mut backend,
            "UPDATE wp_posts SET post_title = 'changed' WHERE ID IN (1, 3)",
        )
        .unwrap();

        assert!(matches!(
            execution,
            RowCowExecution::PreparedLocalWrite { copied_rows: 2, .. }
        ));
        backend.assert_no_remote_writes();
        assert_eq!(
            backend.remote_select_values(),
            vec![vec![PkValue("1".to_string()), PkValue("3".to_string())]]
        );
        assert!(backend.local["wp_posts"].contains_key(&PkValue("1".to_string())));
        assert!(!backend.local["wp_posts"].contains_key(&PkValue("2".to_string())));
        assert!(backend.local["wp_posts"].contains_key(&PkValue("3".to_string())));
    }

    #[test]
    fn update_copy_up_preserves_existing_local_overlay_rows() {
        let mut backend = FakeCowBackend::default();
        backend.insert_remote("wp_posts", "ID", "1", &[("post_title", "remote")]);
        backend.insert_remote("wp_posts", "ID", "2", &[("post_title", "remote two")]);
        backend.insert_local("wp_posts", "ID", "1", &[("post_title", "local draft")]);

        execute_row_cow(
            &mut backend,
            "UPDATE wp_posts SET post_status = 'draft' WHERE ID IN (1, 2)",
        )
        .unwrap();

        assert_eq!(
            backend.remote_select_values(),
            vec![vec![PkValue("2".to_string())]],
            "copy-up should fetch only affected rows missing from the local overlay"
        );
        assert_eq!(
            backend.local["wp_posts"][&PkValue("1".to_string())].get("post_title"),
            Some(&Value::String("local draft".to_string())),
            "existing local overlay row must not be replaced by the remote lower row"
        );
    }

    #[test]
    fn options_update_copy_up_fetches_only_named_option() {
        let mut backend = FakeCowBackend::default();
        backend.insert_remote(
            "wp_options",
            "option_name",
            "blogname",
            &[("option_id", "1"), ("option_value", "Remote Name")],
        );
        backend.insert_remote(
            "wp_options",
            "option_name",
            "siteurl",
            &[("option_id", "2"), ("option_value", "https://example.com")],
        );

        let execution = execute_row_cow(
            &mut backend,
            "UPDATE wp_options SET option_value = 'Local Name' WHERE option_name = 'blogname'",
        )
        .unwrap();

        assert!(matches!(
            execution,
            RowCowExecution::PreparedLocalWrite {
                pk_column: Some(pk_column),
                copied_rows: 1,
                ..
            } if pk_column == "option_name"
        ));
        assert_eq!(
            backend.remote_select_values(),
            vec![vec![PkValue("blogname".to_string())]]
        );
        assert!(backend.local["wp_options"].contains_key(&PkValue("blogname".to_string())));
        assert!(!backend.local["wp_options"].contains_key(&PkValue("siteurl".to_string())));
        backend.assert_no_remote_writes();
    }

    #[test]
    fn delete_tombstone_hides_remote_row_from_merged_selects() {
        let mut backend = FakeCowBackend::default();
        backend.insert_remote("wp_posts", "ID", "42", &[("post_title", "remote")]);

        execute_row_cow(&mut backend, "DELETE FROM wp_posts WHERE ID = 42").unwrap();
        assert!(
            backend.remote_calls.is_empty(),
            "DELETE by primary key must tombstone locally without fetching remote rows"
        );
        let execution =
            execute_row_cow(&mut backend, "SELECT * FROM wp_posts WHERE ID = 42").unwrap();

        let RowCowExecution::Select(result) = execution else {
            panic!("expected row-level select");
        };
        assert!(result.rows.is_empty());
        backend.assert_no_remote_writes();
    }

    #[test]
    fn select_materializes_remote_rows_for_later_offline_reads() {
        let mut backend = FakeCowBackend::default();
        backend.insert_remote("wp_posts", "ID", "42", &[("post_title", "remote")]);

        let execution =
            execute_row_cow(&mut backend, "SELECT * FROM wp_posts WHERE ID = 42").unwrap();
        let RowCowExecution::Select(result) = execution else {
            panic!("expected row-level select");
        };

        assert_eq!(result.rows.len(), 1);
        assert_eq!(
            result.rows[0].get("post_title"),
            Some(&Value::String("remote".to_string()))
        );
        assert_eq!(
            backend.local["wp_posts"][&PkValue("42".to_string())].get("post_title"),
            Some(&Value::String("remote".to_string())),
            "row-level reads must materialize remote rows so offline refresh can use local state"
        );
        backend.assert_no_remote_writes();
    }

    #[test]
    fn repeated_select_uses_materialized_local_row_without_remote_read() {
        let mut backend = FakeCowBackend::default();
        backend.insert_remote("wp_posts", "ID", "42", &[("post_title", "remote")]);

        execute_row_cow(&mut backend, "SELECT * FROM wp_posts WHERE ID = 42").unwrap();
        backend.remote_calls.clear();

        let execution =
            execute_row_cow(&mut backend, "SELECT * FROM wp_posts WHERE ID = 42").unwrap();
        let RowCowExecution::Select(result) = execution else {
            panic!("expected row-level select");
        };

        assert_eq!(
            result.rows[0].get("post_title"),
            Some(&Value::String("remote".to_string()))
        );
        assert!(
            backend.remote_calls.is_empty(),
            "materialized row-level reads should be served from local COW state"
        );
    }

    #[test]
    fn select_materialization_preserves_local_overlay_rows() {
        let mut backend = FakeCowBackend::default();
        backend.insert_remote("wp_posts", "ID", "42", &[("post_title", "remote")]);
        backend.insert_local("wp_posts", "ID", "42", &[("post_title", "local")]);

        let execution =
            execute_row_cow(&mut backend, "SELECT * FROM wp_posts WHERE ID = 42").unwrap();
        let RowCowExecution::Select(result) = execution else {
            panic!("expected row-level select");
        };

        assert_eq!(
            result.rows[0].get("post_title"),
            Some(&Value::String("local".to_string()))
        );
        assert_eq!(
            backend.local["wp_posts"][&PkValue("42".to_string())].get("post_title"),
            Some(&Value::String("local".to_string()))
        );
        backend.assert_no_remote_writes();
    }

    #[test]
    fn insert_after_delete_clears_tombstone_and_shadows_remote_row() {
        let mut backend = FakeCowBackend::default();
        backend.insert_remote("wp_posts", "ID", "42", &[("post_title", "remote")]);

        execute_row_cow(&mut backend, "DELETE FROM wp_posts WHERE ID = 42").unwrap();
        execute_row_cow(
            &mut backend,
            "INSERT INTO wp_posts (ID, post_title) VALUES (42, 'local replacement')",
        )
        .unwrap();
        backend.insert_local(
            "wp_posts",
            "ID",
            "42",
            &[("post_title", "local replacement")],
        );

        let execution =
            execute_row_cow(&mut backend, "SELECT * FROM wp_posts WHERE ID = 42").unwrap();
        let RowCowExecution::Select(result) = execution else {
            panic!("expected row-level select");
        };

        assert_eq!(result.rows.len(), 1);
        assert_eq!(
            result.rows[0].get("post_title"),
            Some(&Value::String("local replacement".to_string()))
        );
    }

    #[test]
    fn local_insert_is_not_sent_to_remote_and_appears_in_merged_select() {
        let mut backend = FakeCowBackend::default();
        let execution = execute_row_cow(
            &mut backend,
            "INSERT INTO wp_posts (ID, post_title) VALUES (9, 'local')",
        )
        .unwrap();
        assert!(matches!(
            execution,
            RowCowExecution::LocalOnlyInsert { table } if table == "wp_posts"
        ));
        assert!(
            backend.remote_calls.is_empty(),
            "INSERT must not be sent to or read from remote"
        );

        backend.insert_local("wp_posts", "ID", "9", &[("post_title", "local")]);
        let execution =
            execute_row_cow(&mut backend, "SELECT * FROM wp_posts WHERE ID = 9").unwrap();
        let RowCowExecution::Select(result) = execution else {
            panic!("expected row-level select");
        };

        assert_eq!(result.rows.len(), 1);
        assert_eq!(
            result.rows[0].get("post_title"),
            Some(&Value::String("local".to_string()))
        );
        assert!(!backend
            .remote
            .get("wp_posts")
            .unwrap_or(&BTreeMap::new())
            .contains_key(&PkValue("9".to_string())));
        backend.assert_no_remote_writes();
    }

    #[test]
    fn local_insert_without_pk_reserves_auto_increment_before_write() {
        let mut backend = FakeCowBackend::default();
        let execution = execute_row_cow(
            &mut backend,
            "INSERT INTO wp_posts (post_title) VALUES ('local auto id')",
        )
        .unwrap();
        assert!(matches!(
            execution,
            RowCowExecution::LocalOnlyInsert { table } if table == "wp_posts"
        ));
        assert_eq!(
            backend.reserved_inserts,
            vec![("wp_posts".to_string(), Some("ID".to_string()))]
        );
        backend.assert_no_remote_writes();
    }

    #[test]
    fn ambiguous_sql_is_never_row_level_safe() {
        assert_not_row_level(
            "SELECT p.* FROM wp_posts p JOIN wp_postmeta m ON m.post_id = p.ID WHERE p.ID = 1",
        );
        assert_not_row_level("SELECT COUNT(*) FROM wp_posts WHERE ID IN (1, 2)");
        assert_not_row_level("UPDATE wp_posts SET post_title = 'x' WHERE post_name = 'hello'");
        assert_not_row_level("DELETE FROM wp_posts WHERE post_title = 'hello'");
        assert_not_row_level(
            "SELECT * FROM wp_posts WHERE ID IN (1, 2) ORDER BY post_date DESC LIMIT 1",
        );
        assert_not_row_level("SELECT * FROM wp_posts WHERE ID = 1 OR ID = 2");
        assert_not_row_level("SELECT * FROM wp_terms WHERE ID = 1");
        assert_not_row_level("SELECT * FROM wp_posts p WHERE q.ID = 1");
        assert_not_row_level("UPDATE wp_posts p SET post_title = 'x' WHERE q.ID = 1");
        assert_not_row_level(
            "UPDATE wp_posts SET post_author = (SELECT ID FROM wp_users LIMIT 1) WHERE ID = 1",
        );
        assert_not_row_level("DELETE FROM wp_posts p WHERE q.ID = 1");
        assert_not_row_level("DELETE FROM wp_posts p, wp_users u WHERE p.ID = 1");
        assert_not_row_level("INSERT INTO wp_posts SELECT * FROM wp_users");
        assert_not_row_level("INSERT INTO wp_posts nonsense");
        assert_not_row_level("INSERT INTO wp_posts nonsense VALUES (1)");
        assert_not_row_level("SELECT * FROM wp_posts, wp_users WHERE wp_posts.ID = 1");
        assert_not_row_level("SELECT * FROM wp_posts WHERE ID = 'unterminated");
        assert_not_row_level("SELECT * FROM `wp_posts WHERE ID = 1");
        assert_not_row_level("SELECT * FROM wp_posts /* unterminated comment WHERE ID = 1");
    }

    #[test]
    fn complex_reads_make_explicit_promotion_decisions() {
        let RowCowPlan::PromoteTable { tables, .. } =
            plan_sql("SELECT * FROM wp_posts WHERE ID IN (1, 2) ORDER BY post_date DESC LIMIT 1")
        else {
            panic!("ordered and limited reads must promote instead of row-merging");
        };
        assert_eq!(tables, vec!["wp_posts".to_string()]);

        let RowCowPlan::PromoteTable { tables, .. } = plan_sql(
            "SELECT p.* FROM wp_posts p JOIN wp_postmeta m ON m.post_id = p.ID WHERE p.ID = 1",
        ) else {
            panic!("join reads must promote instead of row-merging");
        };
        assert_eq!(
            tables,
            vec!["wp_posts".to_string(), "wp_postmeta".to_string()]
        );

        let RowCowPlan::PromoteTable { tables, .. } =
            plan_sql("SELECT * FROM wp_posts, wp_users WHERE wp_posts.ID = 1")
        else {
            panic!("comma-join reads must promote instead of row-merging");
        };
        assert_eq!(tables, vec!["wp_posts".to_string(), "wp_users".to_string()]);
    }
}

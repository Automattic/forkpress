use anyhow::{anyhow, Result};
use serde::{Deserialize, Serialize};
use serde_json::json;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;
use tiny_http::{Header, Request, Response, Server, StatusCode};

use crate::config::{self, ClonePaths, Manifest};
use crate::db;
use crate::remote::RemoteClient;

#[derive(Debug, Deserialize)]
struct ControlRequest {
    #[allow(dead_code)]
    clone: Option<String>,
    tables: Option<Vec<String>>,
    sql: Option<String>,
}

#[derive(Debug, Serialize)]
struct BasicResponse<'a> {
    ok: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    error: Option<&'a str>,
}

pub fn serve_control(
    addr: &str,
    manifest: Manifest,
    paths: ClonePaths,
    remote: RemoteClient,
    shutdown: Arc<AtomicBool>,
) -> Result<()> {
    let server =
        Server::http(addr).map_err(|err| anyhow!("bind control server {}: {}", addr, err))?;
    while !shutdown.load(Ordering::SeqCst) {
        match server.recv_timeout(Duration::from_millis(250)) {
            Ok(Some(request)) => {
                if let Err(err) = handle_request(request, &manifest, &paths, &remote) {
                    eprintln!("wp-cow control error: {err:#}");
                }
            }
            Ok(None) => {}
            Err(err) => return Err(anyhow!("control server receive failed: {}", err)),
        }
    }
    Ok(())
}

fn handle_request(
    mut request: Request,
    manifest: &Manifest,
    paths: &ClonePaths,
    remote: &RemoteClient,
) -> Result<()> {
    if request.method().as_str() != "POST" {
        return send_json(
            request,
            StatusCode(405),
            &BasicResponse {
                ok: false,
                error: Some("method not allowed"),
            },
        );
    }

    let mut body = String::new();
    request.as_reader().read_to_string(&mut body)?;

    let response = match serde_json::from_str::<ControlRequest>(&body) {
        Ok(input) => match control_response(request.url(), input, manifest, paths, remote) {
            Ok(response) => response,
            Err(err) => json!({ "ok": false, "error": format!("{err:#}") }),
        },
        Err(err) => json!({ "ok": false, "error": format!("decode control JSON: {err}") }),
    };

    let status = match response.get("ok").and_then(|v| v.as_bool()) {
        Some(true) => StatusCode(200),
        Some(false) if response.get("error").and_then(|v| v.as_str()) == Some("not found") => {
            StatusCode(404)
        }
        Some(false) => StatusCode(500),
        None => StatusCode(500),
    };
    send_json(request, status, &response)
}

fn control_response(
    url: &str,
    input: ControlRequest,
    manifest: &Manifest,
    paths: &ClonePaths,
    remote: &RemoteClient,
) -> Result<serde_json::Value> {
    match url {
        "/materialize" => {
            let tables = input.tables.unwrap_or_default();
            if config::is_offline(paths) {
                return Ok(json!({
                    "ok": true,
                    "backend": "local",
                    "materialized": [],
                    "offline": true
                }));
            }
            let materialized = db::materialize_tables(remote, manifest, paths, &tables)?;
            Ok(json!({ "ok": true, "backend": "local", "materialized": materialized }))
        }
        "/route" => {
            let tables = input.tables.unwrap_or_default();
            if config::is_offline(paths) {
                return Ok(json!({
                    "ok": true,
                    "backend": "local",
                    "materialized": [],
                    "offline": true
                }));
            }
            let decision = if let Some(sql) = input.sql.as_deref() {
                db::route_for_query(remote, manifest, paths, sql, &tables)?
            } else {
                db::route_for_tables(remote, manifest, paths, &tables)?
            };
            Ok(
                json!({ "ok": true, "backend": decision.backend, "materialized": decision.materialized }),
            )
        }
        "/query" => {
            if config::is_offline(paths) {
                return Ok(json!({
                    "ok": false,
                    "error": "clone is severed from the remote database"
                }));
            }
            let sql = input.sql.ok_or_else(|| anyhow!("missing sql"))?;
            let result = db::remote_readonly_query(remote, &sql)?;
            Ok(json!({
                "ok": result.ok,
                "error": result.error,
                "rows": result.rows,
                "fields": result.fields,
                "affected": result.affected
            }))
        }
        _ => Ok(json!({ "ok": false, "error": "not found" })),
    }
}

fn send_json<T: Serialize>(request: Request, status: StatusCode, value: &T) -> Result<()> {
    let body = serde_json::to_vec(value)?;
    let header = Header::from_bytes("Content-Type", "application/json")
        .map_err(|_| anyhow!("invalid content-type header"))?;
    request
        .respond(
            Response::from_data(body)
                .with_status_code(status)
                .with_header(header),
        )
        .map_err(|err| anyhow!("send control response: {}", err))
}

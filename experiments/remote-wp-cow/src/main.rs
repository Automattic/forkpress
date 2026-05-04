mod cli;
mod config;
mod control;
mod db;
mod fusefs;
mod generate;
mod mysql_proxy;
mod overlay;
mod remote;
mod row_cow;
mod run;
mod runtime_cache;
mod sql;

fn main() -> anyhow::Result<()> {
    cli::run()
}

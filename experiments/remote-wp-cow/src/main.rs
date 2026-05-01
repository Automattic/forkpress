mod cli;
mod config;
mod control;
mod db;
mod fusefs;
mod generate;
mod overlay;
mod remote;
mod run;
mod sql;

fn main() -> anyhow::Result<()> {
    cli::run()
}

pub(crate) const PRODUCTION_COMMANDS: &[&str] = &[
    "agents",
    "branch",
    "branchctl",
    "clone",
    "commit",
    "doctor",
    "git",
    "init",
    "logs",
    "pull",
    "push",
    "serve",
    "server",
    "start",
    "stop",
    "storage",
];

pub(crate) const DEV_ONLY_COMMANDS: &[&str] = &["backup", "export", "import", "user", "zfs"];

macro_rules! argv {
    ($($arg:expr),* $(,)?) => {{
        vec![$(std::ffi::OsString::from($arg)),*]
    }};
}

#[path = "production_cli/branch.rs"]
mod branch;
#[path = "production_cli/git.rs"]
mod git;
#[path = "production_cli/harness.rs"]
mod harness;
#[path = "production_cli/lifecycle.rs"]
mod lifecycle;
#[path = "production_cli/logs_storage.rs"]
mod logs_storage;
#[path = "production_cli/support.rs"]
mod support;
#[path = "production_cli/surface.rs"]
mod surface;

use harness::Harness;

#[test]
#[ignore = "requires FORKPRESS_E2E_BIN to point to a built production forkpress binary"]
fn production_cli_exhaustive_e2e() {
    let mut harness = Harness::new();
    harness.command_surface();
    harness.parser_and_early_validation_failures();
    harness.no_site_diagnostics();
    harness.init_site();
    harness.server_entrypoints();
    harness.start_main_server_with_serve();
    harness.branch_commands();
    harness.git_checkout_commands();
    harness.agent_worktrees();
    harness.log_commands();
    harness.storage_commands();
    harness.stop_main_server();
    harness.foreground_start_exits_on_sigint();
    harness.post_stop_storage_commands();
    harness.readme_command_docs();
}

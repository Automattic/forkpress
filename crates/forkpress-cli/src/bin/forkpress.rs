#[cfg(feature = "dev-experiments")]
compile_error!(
    "the production forkpress binary must not be built with dev-experiments; build --bin forkpress-dev instead"
);

#[path = "../app.rs"]
mod app;

fn main() {
    app::main();
}

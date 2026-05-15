# macOS storage

ForkPress uses APFS copy-on-write on macOS. It first tries native `clonefile`
inside the project directory. If the current volume cannot clone files,
ForkPress falls back to a rootless APFS sparsebundle.

## APFS `clonefile`

`clonefile` creates a new file entry whose data blocks initially share APFS
extents with the source file.

```text
./main/wp-load.php       -> extents 100, 101, 102
./marketing/wp-load.php  -> extents 100, 101, 102
```

When one branch writes to the file, APFS allocates new blocks for the changed
data:

```text
./main/wp-load.php       -> extents 100, 101, 102
./marketing/wp-load.php  -> extents 100, 555, 102
```

This gives independent ordinary paths with shared unchanged file contents.
Branch creation still walks the source tree and creates a materialized
directory namespace for the new branch.

## APFS sparsebundle fallback

If the project volume cannot clone files, ForkPress creates:

```text
.forkpress/macos-cow/branches.sparsebundle
.forkpress/macos-cow/mount
```

The physical branch trees live in the mounted APFS image. Public branch
directories such as `./main` and `./marketing` remain visible beside
`.forkpress`.

## Mount lifecycle

Stop through ForkPress before deleting or moving a sparsebundle-backed site:

```bash
forkpress stop
```

Reclaim free space inside the sparsebundle after heavy branch churn:

```bash
forkpress storage compact
```

Compaction stops this site's server, detaches the sparsebundle, runs
`hdiutil compact`, and leaves storage detached. Run `forkpress serve` or
`forkpress storage mount` to attach it again.

If macOS reports the storage is busy, close terminals or editors inside
`.forkpress/macos-cow/mount` and run `forkpress stop` again. Use `--force` only
after normal detach reports a busy mount.

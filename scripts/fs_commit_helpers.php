<?php
/**
 * Shared helpers for the file-side commit graph (fs_commits +
 * fs_commit_files).
 *
 * TODO3 #5 — delta-encoded file commits mirror the TODO3 #2 db commit
 * model: FIRST commit on a branch is `kind='FULL'` and records every
 * path as `op='UPSERT'`; every subsequent commit is `kind='DELTA'`
 * and records only paths that differ from the previous commit
 * (UPSERT for added/changed, DELETE for removed).
 *
 * Readers reconstruct a commit's full tree by walking from the last
 * FULL base commit up through subsequent DELTA commits.
 *
 * This file is require_once'd from branchctl.php, merge.php, and the
 * git server so every reader uses the same chain-walker — critical
 * for correctness after delta encoding lands.
 */

if (!function_exists('fs_materialize_commit_tree')) {

/** Materialize a commit's full file tree by walking the chain (last
 *  FULL commit ≤ $commit_id) + subsequent DELTAs up to $commit_id).
 *
 *  Returns a path-keyed tree:
 *    [path => ['path' => …, 'blob_hash' => …, 'mode' => …,
 *              'mtime' => …, 'is_dir' => …]]
 *
 *  Compatible with pre-TODO3 .fp files: rows without an `op` column
 *  default to UPSERT (full-snapshot semantics), and rows without a
 *  `kind` column default to FULL. The walker therefore produces the
 *  same tree legacy readers produced when pointed at a legacy commit.
 */
function fs_materialize_commit_tree(SQLite3 $db, int $commit_id): array {
    $branch_id = (int)$db->querySingle(
        "SELECT branch_id FROM fs_commits WHERE id = $commit_id"
    );
    if ($branch_id <= 0) return [];
    $commits = [];
    $r = $db->query(
        "SELECT id, COALESCE(kind,'FULL') AS kind FROM fs_commits "
      . "WHERE branch_id = $branch_id AND id <= $commit_id ORDER BY id"
    );
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $commits[] = $row;
    $r->finalize();

    $base_idx = 0;
    for ($i = count($commits) - 1; $i >= 0; $i--) {
        if ($commits[$i]['kind'] === 'FULL') { $base_idx = $i; break; }
    }
    $tree = [];
    for ($i = $base_idx; $i < count($commits); $i++) {
        $cid  = (int)$commits[$i]['id'];
        $kind = $commits[$i]['kind'];
        if ($kind === 'FULL') $tree = [];
        $rr = $db->query(
            "SELECT path, blob_hash, mode, mtime, is_dir, "
          . "       COALESCE(op,'UPSERT') AS op "
          . "FROM fs_commit_files WHERE commit_id = $cid"
        );
        while ($row = $rr->fetchArray(SQLITE3_ASSOC)) {
            if ($row['op'] === 'DELETE') {
                unset($tree[$row['path']]);
                continue;
            }
            $tree[$row['path']] = [
                'path'      => $row['path'],
                'blob_hash' => $row['blob_hash'],
                'mode'      => (int)($row['mode']  ?? 0),
                'mtime'     => (int)($row['mtime'] ?? 0),
                'is_dir'    => (int)($row['is_dir'] ?? 0),
            ];
        }
        $rr->finalize();
    }
    return $tree;
}

}

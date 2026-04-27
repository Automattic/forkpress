PHP_ARG_ENABLE(branchfs, whether to enable branchfs support,
[  --enable-branchfs      Enable branchfs support])

if test "$PHP_BRANCHFS" != "no"; then
  PHP_NEW_EXTENSION(branchfs, branchfs.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
  PHP_ADD_LIBRARY(sqlite3, , BRANCHFS_SHARED_LIBADD)
  PHP_SUBST(BRANCHFS_SHARED_LIBADD)
fi

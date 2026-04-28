#ifndef FORKPRESS_ZFS_ENGINE_SYS_SIMD_H
#define FORKPRESS_ZFS_ENGINE_SYS_SIMD_H

#if defined(__APPLE__)

static inline unsigned long
forkpress_zfs_getauxval(unsigned long key)
{
    (void)key;
    return (0UL);
}

#ifndef AT_HWCAP
#define AT_HWCAP 16
#endif

#ifndef AT_HWCAP2
#define AT_HWCAP2 26
#endif

#define getauxval forkpress_zfs_getauxval
#include_next <sys/simd.h>

#else
#include_next <sys/simd.h>
#endif

#endif

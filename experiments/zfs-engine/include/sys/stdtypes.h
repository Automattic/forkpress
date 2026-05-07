#ifndef FORKPRESS_ZFS_ENGINE_SYS_STDTYPES_H
#define FORKPRESS_ZFS_ENGINE_SYS_STDTYPES_H

#if defined(__APPLE__)

#include <mach/boolean.h>

enum {
    B_FALSE = 0,
    B_TRUE = 1
};

typedef unsigned char uchar_t;
typedef unsigned short ushort_t;
typedef unsigned int uint_t;
typedef unsigned long ulong_t;
typedef unsigned long long u_longlong_t;
typedef long long longlong_t;

typedef longlong_t offset_t;
typedef u_longlong_t u_offset_t;
typedef u_longlong_t len_t;
typedef longlong_t diskaddr_t;

typedef ulong_t pgcnt_t;
typedef long spgcnt_t;

typedef short pri_t;
typedef ushort_t o_mode_t;

typedef int major_t;
typedef int minor_t;

typedef short index_t;

#else
#include_next <sys/stdtypes.h>
#endif

#endif

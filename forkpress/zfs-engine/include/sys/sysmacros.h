#ifndef FORKPRESS_ZFS_ENGINE_SYS_SYSMACROS_H
#define FORKPRESS_ZFS_ENGINE_SYS_SYSMACROS_H

#if !defined(__APPLE__)
#include_next <sys/sysmacros.h>
#endif

#include <stdint.h>
#include <sys/types.h>
#include <unistd.h>

#ifndef MIN
#define MIN(a, b) ((a) < (b) ? (a) : (b))
#endif

#ifndef MAX
#define MAX(a, b) ((a) < (b) ? (b) : (a))
#endif

#ifndef ABS
#define ABS(a) ((a) < 0 ? -(a) : (a))
#endif

#ifndef ARRAY_SIZE
#define ARRAY_SIZE(a) (sizeof (a) / sizeof ((a)[0]))
#endif

#ifndef DIV_ROUND_UP
#define DIV_ROUND_UP(n, d) (((n) + (d) - 1) / (d))
#endif

#ifndef makedevice
#define makedevice(maj, min) makedev((maj), (min))
#endif

#ifndef _sysconf
#define _sysconf(a) sysconf(a)
#endif

#ifndef P2CROSS
#define P2CROSS(x, y, align) (((x) ^ (y)) > (align) - 1)
#endif

#ifndef P2ROUNDUP
#define P2ROUNDUP(x, align) ((((x) - 1) | ((align) - 1)) + 1)
#endif

#ifndef P2BOUNDARY
#define P2BOUNDARY(off, len, align) (((off) ^ ((off) + (len) - 1)) > (align) - 1)
#endif

#ifndef P2PHASE
#define P2PHASE(x, align) ((x) & ((align) - 1))
#endif

#ifndef P2NPHASE
#define P2NPHASE(x, align) (-(x) & ((align) - 1))
#endif

#ifndef P2NPHASE_TYPED
#define P2NPHASE_TYPED(x, align, type) (-(type)(x) & ((type)(align) - 1))
#endif

#ifndef ISP2
#define ISP2(x) (((x) & ((x) - 1)) == 0)
#endif

#ifndef IS_P2ALIGNED
#define IS_P2ALIGNED(v, a) ((((uintptr_t)(v)) & ((uintptr_t)(a) - 1)) == 0)
#endif

#ifndef P2ALIGN_TYPED
#define P2ALIGN_TYPED(x, align, type) ((type)(x) & -(type)(align))
#endif

#ifndef P2PHASE_TYPED
#define P2PHASE_TYPED(x, align, type) ((type)(x) & ((type)(align) - 1))
#endif

#ifndef P2ROUNDUP_TYPED
#define P2ROUNDUP_TYPED(x, align, type) \
    ((((type)(x) - 1) | ((type)(align) - 1)) + 1)
#endif

#ifndef P2END_TYPED
#define P2END_TYPED(x, align, type) (-(~(type)(x) & -(type)(align)))
#endif

#ifndef P2PHASEUP_TYPED
#define P2PHASEUP_TYPED(x, align, phase, type) \
    ((type)(phase) - (((type)(phase) - (type)(x)) & -(type)(align)))
#endif

#ifndef P2CROSS_TYPED
#define P2CROSS_TYPED(x, y, align, type) \
    (((type)(x) ^ (type)(y)) > (type)(align) - 1)
#endif

#ifndef P2SAMEHIGHBIT_TYPED
#define P2SAMEHIGHBIT_TYPED(x, y, type) \
    (((type)(x) ^ (type)(y)) < ((type)(x) & (type)(y)))
#endif

#endif

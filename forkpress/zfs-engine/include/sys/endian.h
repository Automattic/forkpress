#ifndef FORKPRESS_ZFS_ENGINE_SYS_ENDIAN_H
#define FORKPRESS_ZFS_ENGINE_SYS_ENDIAN_H

#if defined(__APPLE__)
#include <machine/endian.h>

#ifndef _LITTLE_ENDIAN
#define _LITTLE_ENDIAN LITTLE_ENDIAN
#endif

#ifndef _BIG_ENDIAN
#define _BIG_ENDIAN BIG_ENDIAN
#endif

#ifndef _BYTE_ORDER
#define _BYTE_ORDER BYTE_ORDER
#endif

#else
#include_next <sys/endian.h>
#endif

#endif

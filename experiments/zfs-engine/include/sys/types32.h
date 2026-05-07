#ifndef FORKPRESS_ZFS_ENGINE_SYS_TYPES32_H
#define FORKPRESS_ZFS_ENGINE_SYS_TYPES32_H

#if defined(__APPLE__)

#include <stdint.h>
#include <sys/_types/_timeval32.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef uint32_t caddr32_t;
typedef int32_t daddr32_t;
typedef int32_t off32_t;
typedef uint32_t ino32_t;
typedef int32_t blkcnt32_t;
typedef uint32_t fsblkcnt32_t;
typedef uint32_t fsfilcnt32_t;
typedef int32_t id32_t;
typedef uint32_t major32_t;
typedef uint32_t minor32_t;
typedef int32_t key32_t;
typedef uint32_t mode32_t;
typedef uint32_t uid32_t;
typedef uint32_t gid32_t;
typedef uint32_t nlink32_t;
typedef uint32_t dev32_t;
typedef int32_t pid32_t;
typedef uint32_t size32_t;
typedef int32_t ssize32_t;
typedef int32_t time32_t;
typedef int32_t clock32_t;

typedef struct timespec32 {
    time32_t tv_sec;
    int32_t tv_nsec;
} timespec32_t;

typedef struct timespec32 timestruc32_t;

typedef struct itimerspec32 {
    struct timespec32 it_interval;
    struct timespec32 it_value;
} itimerspec32_t;

#ifdef __cplusplus
}
#endif

#else
#include_next <sys/types32.h>
#endif

#endif

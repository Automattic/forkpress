#ifndef FORKPRESS_ZFS_ENGINE_LIBINTL_H
#define FORKPRESS_ZFS_ENGINE_LIBINTL_H

#define gettext(message) ((char *)(message))
#define dgettext(domain, message) ((char *)(message))
#define dcgettext(domain, message, category) ((char *)(message))

static inline char *
textdomain(const char *domain)
{
    return ((char *)domain);
}

static inline char *
bindtextdomain(const char *domain, const char *dirname)
{
    (void)domain;
    return ((char *)dirname);
}

#endif

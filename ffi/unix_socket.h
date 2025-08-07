/*
 * Structures for SCM_RIGHTS file descriptor passing
 */

struct iovec {
    void *iov_base;
    size_t iov_len;
};

struct msghdr {
    void *msg_name;
    unsigned int msg_namelen;
    struct iovec *msg_iov;
    size_t msg_iovlen;
    void *msg_control;
    size_t msg_controllen;
    int msg_flags;
};

struct cmsghdr {
    cmsg_len_t cmsg_len;
    int cmsg_level;
    int cmsg_type;
};

/* Control message with fd data */
struct cmsghdr_fd {
    struct cmsghdr hdr;
    int fd;
};

/* System calls - only what we need for SCM_RIGHTS */
int sendmsg(int sockfd, const struct msghdr *msg, int flags);
int recvmsg(int sockfd, struct msghdr *msg, int flags);
int getdtablesize(void);


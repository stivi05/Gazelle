<?php

namespace Gazelle\Notification;

class Torrent extends \Gazelle\Base {
    protected $userId;
    protected $cond;
    protected $args;

    public function __construct(int $userId) {
        parent::__construct();
        $this->cond = ['unt.UserID = ?'];
        $this->args = [$userId];
        $this->userId = $userId;
    }

    public function setFilter(int $filterId) {
        $cond[] = 'unf.ID = ?';
        $args[] = $filterId;
        return $this;
    }

    public function total(): int {
        return $this->db->scalar("
            SELECT count(*)
            FROM users_notify_torrents AS unt
            INNER JOIN torrents AS t ON (t.ID = unt.TorrentID)
            LEFT JOIN users_notify_filters AS unf ON (unf.ID = unt.FilterID)
            WHERE " . implode(' AND ', $this->cond)
            , ...$this->args
        );
    }

    public function unreadList(int $limit, int $offset): array {
        $args = array_merge($this->args, [$limit, $offset]);
        $this->db->prepared_query("
            SELECT t.GroupID  AS groupId,
                unt.UnRead    AS unread,
                unf.Label     AS label,
                unt.TorrentID AS torrentId
            FROM users_notify_torrents AS unt
            INNER JOIN torrents AS t ON (t.ID = unt.TorrentID)
            LEFT JOIN users_notify_filters AS unf ON (unf.ID = unt.FilterID)
            WHERE " . implode(' AND ', $this->cond) . "
            ORDER BY unf.Label, unt.TorrentID DESC
            LIMIT ? OFFSET ?
            ", ...$args
        );
        $list = $this->db->to_array(false, MYSQLI_ASSOC, false);
        $this->db->prepared_query('
            UPDATE users_notify_torrents SET
                UnRead = ?
            WHERE UserID = ?
            ', 0, $this->userId
        );
        $this->cache->delete_value("user_notify_upload_" . $this->userId);
        return $list;
    }
}
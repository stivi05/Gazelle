<?php

namespace Gazelle;

class Request extends BaseObject {
    protected array $info;

    protected const CACHE_REQUEST = "request_%d";
    protected const CACHE_ARTIST  = "request_artists_%d";
    protected const CACHE_VOTE    = "request_votes_%d";

    public function tableName(): string {
        return 'requests';
    }

    public function flush() {
        if ($this->info()['GroupID']) {
            $this->cache->delete_value("requests_group_" . $this->info()['GroupID']);
        }
        $this->cache->deleteMulti([
            sprintf(self::CACHE_REQUEST, $this->id),
            sprintf(self::CACHE_ARTIST, $this->id),
            sprintf(self::CACHE_VOTE, $this->id),
        ]);
        $this->info = [];
    }

    public function artistFlush() {
        $this->flush();
        $this->db->prepared_query("
            SELECT ArtistID FROM requests_artists WHERE RequestID = ?
            ", $this->id
        );
        $this->cache->deleteMulti([
            ...array_map(fn ($id) => "artists_requests_$id", $this->db->collect(0, false)),
        ]);
    }

    public function remove(): bool {
        $this->db->begin_transaction();
        $this->db->prepared_query("DELETE FROM requests_votes WHERE RequestID = ?", $this->id);
        $this->db->prepared_query("DELETE FROM requests_tags WHERE RequestID = ?", $this->id);
        $this->db->prepared_query("DELETE FROM requests WHERE ID = ?", $this->id);
        $affected = $this->db->affected_rows();
        $this->db->prepared_query("
            SELECT ArtistID FROM requests_artists WHERE RequestID = ?
            ", $this->id
        );
        $artisIds = $this->db->collect(0);
        $this->db->prepared_query('
            DELETE FROM requests_artists WHERE RequestID = ?', $this->id
        );
        $this->db->prepared_query("
            REPLACE INTO sphinx_requests_delta (ID) VALUES (?)
            ", $this->id
        );
        (new \Gazelle\Manager\Comment)->remove('requests', $this->id);
        $this->db->commit();

        foreach ($artisIds as $artistId) {
            $this->cache->delete_value("artists_requests_$artistId");
        }
        $this->flush();

        return $affected != 0;
    }

    protected function info(): array {
        if (!isset($this->info)) {
            $this->info = $this->db->rowAssoc("
                SELECT UserID,
                    FillerID,
                    Title,
                    CategoryID,
                    GroupID,
                    TorrentID,
                    LogCue,
                    Checksum,
                    BitrateList,
                    FormatList,
                    MediaList
                FROM requests
                WHERE ID = ?
                ", $this->id
            );
            $this->info['need_encoding'] = explode('|', $this->info['BitrateList']);
            $this->info['need_format'] = explode('|', $this->info['FormatList']);
            $this->info['need_media'] = explode('|', $this->info['MediaList']);
        }
        return $this->info;
    }

    public function bountyTotal(): int {
        return (int)$this->db->scalar("
            SELECT sum(Bounty) FROM requests_votes WHERE RequestID = ?
            ", $this->id
        );
    }

    public function categoryId(): int {
        return $this->info()['CategoryID'];
    }

    public function userId(): int {
        return $this->info()['UserID'];
    }

    public function fillerId(): ?int {
        return $this->info()['FillerID'];
    }

    public function needCue(): bool {
        return strpos($this->info()['LogCue'], 'Cue') !== false;
    }

    public function needEncoding(string $encoding): bool {
        return in_array($encoding, $this->info()['need_encoding']);
    }

    public function needFormat(string $format): bool {
        return in_array($format, $this->info()['need_format']);
    }

    public function needLog(): bool {
        return strpos($this->info()['LogCue'], 'Log') !== false;
    }

    public function needLogChecksum(): bool {
        return (bool)$this->info()['Checksum'];
    }

    public function needLogScore(): int {
        return preg_match('/(\d+)%/', $this->info()['LogCue'], $match)
            ? $match[1]
            : 0;
    }

    public function needMedia(string $media): bool {
        return in_array($media, $this->info()['need_media']);
    }

    public function title(): string {
        return $this->info()['Title'];
    }

    public function fullTitle() {
        $title = '';
        if (CATEGORY[$this->info()['CategoryID'] - 1] === 'Music') {
            $title = \Artists::display_artists($this->artistList(), false, true);
        }
        return $title . $this->title();
    }

    public function torrentId(): ?int {
        return $this->info()['TorrentID'];
    }

    public function artistList(): array {
        $key = sprintf(self::CACHE_ARTIST, $this->id);
        $list = $this->cache->get_value($key);
        if ($list !== false) {
            return $list;
        }
        $this->db->prepared_query("
            SELECT ra.ArtistID,
                aa.Name,
                ra.Importance
            FROM requests_artists AS ra
            INNER JOIN artists_alias AS aa USING (AliasID)
            WHERE ra.RequestID = ?
            ORDER BY ra.Importance, aa.Name
            ", $this->id
        );
        $raw = $this->db->to_array();
        $list = [];
        foreach ($raw as list($artistId, $artistName, $role)) {
            $list[$role][] = ['id' => $artistId, 'name' => $artistName];
        }
        $this->cache->cache_value($key, $list, 0);
        return $list;
    }

    public function validate(Torrent $torrent): array {
        $error = [];
        if ($this->torrentId()) {
            $error[] = 'This request has already been filled.';
        }
        if (!in_array($this->categoryId(), [0, $torrent->group()->categoryId()])) {
            $error[] = 'This torrent is of a different category than the request. If the request is actually miscategorized, please contact staff.';
        }

        if ($torrent->media() === 'CD' && $torrent->format() === 'FLAC') {
            if ($this->needLog()) {
                if (!$torrent->hasLogDb()) {
                    $error[] = 'This request requires a log.';
                } else {
                    if ($torrent->logScore() < $this->needLogScore()) {
                        $error[] = 'This torrent\'s log score (' . $torrent->logScore() . ') is too low.';
                    }
                    if (!$torrent->logChecksum() && $this->needLogChecksum()) {
                        $error[] = 'The ripping log for this torrent does not have a valid checksum.';
                    }
                }
            }
            if ($this->needCue() && !$torrent->hasCue()) {
                $error[] = 'This request requires a cue file.';
            }
        }

        if (!$this->needMedia('Any') && !$this->needMedia($torrent->media())) {
            $error[] = $torrent->media() . " is not a permitted media for this request.";
        }
        if (!$this->needFormat('Any') && !$this->needFormat($torrent->format())) {
            $error[] = $torrent->format() . " is not an allowed format for this request.";
        }
        if ($this->needEncoding('Other')) {
            if (in_array($torrent->encoding(), ['24bit Lossless', 'Lossless', 'V0 (VBR)', 'V1 (VBR)', 'V2 (VBR)', 'APS (VBR)', 'APX (VBR)', '256', '320'])) {
                $error[] = $torrent->encoding() . " is not an allowed encoding for this request.";
            }
        } elseif (!$this->needEncoding('Any') && !$this->needEncoding($torrent->encoding())) {
            $error[] = $torrent->encoding() . " is not an allowed encoding for this request.";
        }

        return $error;
    }

    public function fill(User $user, Torrent $torrent): int {
        $this->db->prepared_query("
            UPDATE requests SET
                TimeFilled = now(),
                FillerID = ?,
                TorrentID = ?
            WHERE ID = ?
            ", $user->id(), $torrent->id(), $this->id
        );
        $updated = $this->db->affected_rows();
        $this->refreshSphinxDelta();
        (new \SphinxqlQuery())->raw_query("
            UPDATE requests, requests_delta SET
                torrentid = " . $torrent->id() , ",
                fillerid = " . $user->id() . ",
            WHERE id = " . $this->id, false
        );

        $bounty = $this->bountyTotal();
        $user->addBounty($bounty);

        $name = $torrent->group()->displayNameText();
        $message = "One of your requests&nbsp;&mdash;&nbsp;[url=requests.php?action=view&amp;id="
            . $this->id . "]$name" . "[/url]&nbsp;&mdash;&nbsp;has been filled. You can view it here: [pl]"
            . $torrent->id() . "[/pl]";
        $this->db->prepared_query("
            SELECT UserID FROM requests_votes WHERE RequestID = ?
            ", $this->id
        );
        $ids = $this->db->collect(0, false);
        $userMan = new Manager\User;
        foreach ($ids as $userId) {
            $userMan->sendPM($userId, 0, "The request \"$name\" has been filled", $message);
        }

        (new Log)->general("Request " . $this->id . " ($name) was filled by " . $user->label()
            . " with the torrent " . $torrent->id() . " for a "
            . \Format::get_size($bounty) . ' bounty.'
        );

        $this->artistFlush();
        return $updated;
    }

    public function unfill(User $admin, string $reason): int {
        $bounty = $this->bountyTotal();
        $filler = new User($this->fillerId());
        $torrent = new Torrent($this->torrentId());
        $name = $torrent->group()->displayNameText();

        $this->db->begin_transaction();
        $this->db->prepared_query("
            UPDATE requests SET
                TorrentID = 0,
                FillerID = 0,
                TimeFilled = null,
                Visible = 1
            WHERE ID = ?
            ", $this->id
        );
        $updated = $this->db->affected_rows();
        $this->refreshSphinxDelta();
        $filler->addBounty(-$bounty);
        $this->db->commit();

        (new \SphinxqlQuery())->raw_query("
            UPDATE requests, requests_delta SET
                torrentid = 0,
                fillerid = 0
            WHERE id = " . $this->id, false
        );

        if ($filler->id() !== $admin->id()) {
            (new Manager\User)->sendPM($filler->id(), 0, 'A request you filled has been unfilled',
                $this->twig->render('request/unfill-pm.twig', [
                    'name'    => $name,
                    'reason'  => $reason,
                    'request' => $this,
                    'viewer'  => $admin,
                ])
            );
        }

        (new Log)->general("Request " . $this->id . " ($name), with a "
            . \Format::get_size($bounty) . " bounty, was unfilled by "
            . $admin->label() . " for the reason: $reason"
        );

        $this->artistFlush();
        return $updated;
    }

    /**
     * Get the bounty of request, by user
     *
     * @return array keyed by user ID
     */
    public function bounty(): array {
        $this->db->prepared_query("
            SELECT UserID, Bounty
            FROM requests_votes
            WHERE RequestID = ?
            ORDER BY Bounty DESC, UserID DESC
            ", $this->id
        );
        return $this->db->to_array('UserID', MYSQLI_ASSOC, false);
    }

    /**
     * Get the total bounty that a user has added to a request
     * @param int $userId ID of user
     * @return int keyed by user ID
     */
    public function userBounty(int $userId) {
        return $this->db->scalar("
            SELECT Bounty
            FROM requests_votes
            WHERE RequestID = ? AND UserID = ?
            ", $this->id, $userId
        );
    }

    /**
     * Refund the bounty of a user on a request
     * @param int $userId ID of user
     * @param int $staffName name of staff performing the operation
     */
    public function refundBounty(int $userId, string $staffName) {
        $bounty = $this->userBounty($userId);
        $this->db->begin_transaction();
        $this->db->prepared_query("
            DELETE FROM requests_votes
            WHERE RequestID = ? AND UserID = ?
            ", $this->id, $userId
        );
        if ($this->db->affected_rows() == 1) {
            $this->informRequestFillerReduction($bounty, $staffName);
            $message = sprintf("%s Refund of %s bounty (%s b) on %s by %s\n\n",
                sqltime(), \Format::get_size($bounty), $bounty,
                'requests.php?action=view&id=' . $this->id, $staffName
            );
            $this->db->prepared_query("
                UPDATE users_info ui
                INNER JOIN users_leech_stats uls USING (UserID)
                SET
                    uls.Uploaded = uls.Uploaded + ?,
                    ui.AdminComment = concat(?, ui.AdminComment)
                WHERE ui.UserId = ?
                ", $bounty, $message, $userId
            );
        }
        $this->db->commit();
        $this->cache->deleteMulti(["request_" . $this->id, "request_votes_" . $this->id]);
    }

    /**
     * Remove the bounty of a user on a request
     * @param int $userId ID of user
     * @param int $staffName name of staff performing the operation
     */
    public function removeBounty(int $userId, string $staffName) {
        $bounty = $this->userBounty($userId);
        $this->db->begin_transaction();
        $this->db->prepared_query("
            DELETE FROM requests_votes
            WHERE RequestID = ? AND UserID = ?
            ", $this->id, $userId
        );
        if ($this->db->affected_rows() == 1) {
            $this->informRequestFillerReduction($bounty, $staffName);
            $message = sprintf("%s Removal of %s bounty (%s b) on %s by %s\n\n",
                sqltime(), \Format::get_size($bounty), $bounty,
                'requests.php?action=view&id=' . $this->id, $staffName
            );
            $this->db->prepared_query("
                UPDATE users_info ui SET
                    ui.AdminComment = concat(?, ui.AdminComment)
                WHERE ui.UserId = ?
                ", $message, $userId
            );
        }
        $this->db->commit();
        $this->cache->deleteMulti(["request_" . $this->id, "request_votes_" . $this->id]);
    }

    /**
     * Inform the filler of a request that their bounty was reduced
     *
     * @param int $bounty The amount of bounty reduction
     * @param int $staffName name of staff performing the operation
     */
    public function informRequestFillerReduction(int $bounty, string $staffName) {
        [$fillerId, $fillDate] = $this->db->row("
            SELECT FillerID, date(TimeFilled)
            FROM requests
            WHERE TimeFilled IS NOT NULL AND ID = ?
            ", $this->id
        );
        if (!$fillerId) {
            return;
        }
        $requestUrl = 'requests.php?action=view&id=' . $this->id;
        $message = sprintf("%s Reduction of %s bounty (%s b) on filled request %s by %s\n\n",
            sqltime(), \Format::get_size($bounty), $bounty, $requestUrl, $staffName
        );
        $this->db->prepared_query("
            UPDATE users_info ui
            INNER JOIN users_leech_stats uls USING (UserID)
            SET
                uls.Uploaded = uls.Uploaded - ?,
                ui.AdminComment = concat(?, ui.AdminComment)
            WHERE ui.UserId = ?
            ", $bounty, $message, $fillerId
        );
        $this->cache->delete_value("user_stats_$fillerId");
        (new Manager\User)->sendPM($fillerId, 0, "Bounty was reduced on a request you filled",
            $this->twig->render('request/bounty-reduction.twig', [
                'bounty'      => $bounty,
                'fill_date'   => $fillDate,
                'request_url' => $requestUrl,
                'staff_name'  => $staffName,
            ])
        );
    }

    public function refreshSphinxDelta(): int {
        $this->db->prepared_query("
            REPLACE INTO sphinx_requests_delta (
                ID, UserID, TimeAdded, LastVote, CategoryID, Title,
                Year, ReleaseType, CatalogueNumber, RecordLabel, BitrateList,
                FormatList, MediaList, LogCue, FillerID, TorrentID,
                TimeFilled, Visible, Votes, Bounty, TagList,
                ArtistList)
            SELECT
                r.ID, r.UserID, unix_timestamp(r.TimeAdded), unix_timestamp(r.LastVote), r.CategoryID, r.Title,
                r.Year, r.ReleaseType, r.CatalogueNumber, r.RecordLabel, r.BitrateList,
                r.FormatList, r.MediaList, r.LogCue, r.FillerID, r.TorrentID,
                unix_timestamp(r.TimeFilled), r.Visible,
                count(rv.UserID), sum(rv.Bounty) >> 10, group_concat(' ', REPLACE(t.Name, '.', '_')),
                (
                    SELECT group_concat(aa.Name SEPARATOR ' ')
                    FROM requests_artists AS ra
                    INNER JOIN artists_alias AS aa USING (AliasID)
                    WHERE ra.RequestID = ?
                    GROUP BY ra.RequestID
                )
            FROM requests AS r
            INNER JOIN requests_tags AS rt ON (rt.TagID = r.ID)
            INNER JOIN  tags AS t ON (t.ID = rt.TagID)
            LEFT JOIN requests_votes AS rv ON (rv.RequestID = r.ID)
            WHERE r.ID = ?
            GROUP BY r.ID
            ", $this->id, $this->id
        );
        return $this->db->affected_rows();
    }
}

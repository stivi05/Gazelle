<?php

if (!isset($_GET['userid'])) {
    $userId = $Viewer->id();
} else {
    $userId = (int)$_GET['userid'];
    if (!$userId) {
        error(0);
    }
    if ($userId !== $Viewer->id() && !$Viewer->permitted('admin_fl_history')) {
        error(403);
    }
}

$user = (new Gazelle\Manager\User)->findById($userId);
if (!$user) {
    error(404);
}

$torMan = new Gazelle\Manager\Torrent;
if ($_GET['expire'] ?? 0) {
    if (!$Viewer->permitted('admin_fl_history')) {
        error(403);
    }
    $torrent = $torMan->findById((int)$_GET['torrentid']);
    if (is_null($torrent)) {
        error(404);
    }
    $torrent->expireToken($userId);
    header("Location: userhistory.php?action=token_history&userid=$userId");
}

$paginator = new Gazelle\Util\Paginator(25, (int)($_GET['page'] ?? 1));
$paginator->setTotal((new Gazelle\Stats\User($user->id()))->flTokenTotal());

$user->setTorrentManager($torMan)
    ->setTorrentLabelManager(
        (new Gazelle\Manager\TorrentLabel)->showMedia(true)->showEdition(true)
    );

echo $Twig->render('user/history-freeleech.twig', [
    'admin'       => $Viewer->permitted('admin_fl_history'),
    'auth'        => $Viewer->auth(),
    'own_profile' => $Viewer->id() == $userId,
    'paginator'   => $paginator,
    'user'        => $user,
]);

<?php

if (!$Viewer->permitted('site_view_torrent_snatchlist')) {
    error(403);
}
$torrent = (new Gazelle\Manager\Torrent)->findById((int)$_GET['torrentid']);
if (is_null($torrent)) {
    error(404);
}
$paginator = new Gazelle\Util\Paginator(PEERS_PER_PAGE, (int)($_GET['page'] ?? 1));
$paginator->setTotal($torrent->downloadTotal());

echo $Twig->render('torrent/downloadlist.twig', [
    'page'       => $torrent->downloadPage($paginator->limit(), $paginator->offset()),
    'paginator'  => $paginator,
    'torrent_id' => $torrent->id(),
    'url_stem'   => STATIC_SERVER . '/styles/' . $Viewer->stylesheetName() . '/images/',
    'viewer_id'  => $Viewer->id(),
]);

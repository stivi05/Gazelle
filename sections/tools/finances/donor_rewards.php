<?php

if (!$Viewer->permitted('users_mod')) {
    error(403);
}

$userMan = new Gazelle\Manager\User;
$paginator = new Gazelle\Util\Paginator(USERS_PER_PAGE, (int)($_GET['page'] ?? 1));
$paginator->setTotal($userMan->donorRewardTotal());
$search = $_GET['search'] ?? null;

echo $Twig->render('donation/reward-list.twig', [
    'paginator' => $paginator,
    'user'      => $userMan->donorRewardPage($search, $paginator->limit(), $paginator->offset()),
    'search'    => $search,
]);

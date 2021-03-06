<?php

use Gazelle\Exception\BonusException;

authorize();

$label = $_REQUEST['label'];
$bonus = new Gazelle\Bonus($Viewer);
switch($label) {
    case 'collage-1':
        try {
            if ($bonus->purchaseCollage($label)) {
                header("Location: bonus.php?complete=$label");
                exit;
            }
        }
        catch (BonusException $e) {
            if ($e->getMessage() === 'collage:nofunds') {
                error('Could not complete purchase of collage due to lack of funds.');
            }
        }
        break;
    case 'seedbox':
        try {
            if ($bonus->unlockSeedbox()) {
                header("Location: bonus.php?complete=$label");
                exit;
            }
        }
        catch (BonusException $e) {
            if ($e->getMessage() === 'seedbox:nofunds') {
                error('Could not complete purchase of seedbox viewer due to lack of funds.');
            } elseif ($e->getMessage() === 'seedbox:already-purchased') {
                error('Could not buy seedbox viewer for a second time.');
            }
        }
}
error(403);

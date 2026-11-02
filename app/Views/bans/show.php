<?php
use App\Core\Lang;
use App\Core\View;
/** @var array $ban */
$title = 'Ban #' . (int) $ban['id'];
$L = fn(string $k) => View::e(Lang::t($k));
?>
<a class="btn btn-ghost" href="/bans" style="margin-bottom:18px">‹ <?= $L('bans.back') ?></a>
<div class="card" style="max-width:760px">
    <div class="card-title"><?= $L('bans.details') ?> #<?= (int) $ban['id'] ?></div>
    <?php
    $sanction = $ban;
    $sanctionKind = 'bans';
    $removal = $removals[(int) $ban['id']] ?? null;
    require BASE_PATH . '/app/Views/partials/sanction-details.php';
    ?>
</div>

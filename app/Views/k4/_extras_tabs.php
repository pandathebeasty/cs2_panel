<?php
/** @var string $k4Active */
foreach (\App\Models\K4ExtrasModel::pages() as $k4Page): ?>
    <a class="subtab<?= ($k4Active ?? '') === $k4Page ? ' active' : '' ?>" href="/k4/<?= $k4Page ?>"><?= \App\Core\View::e(\App\Core\Lang::t('nav.k4_' . $k4Page)) ?></a>
<?php endforeach; ?>

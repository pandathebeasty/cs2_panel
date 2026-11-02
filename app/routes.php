<?php

declare(strict_types=1);

use App\Core\Router;
use App\Controllers\AdminController;
use App\Controllers\AppealController;
use App\Controllers\AuthController;
use App\Controllers\BanController;
use App\Controllers\ChatLogController;
use App\Controllers\ContestController;
use App\Controllers\DashboardController;
use App\Controllers\GroupController;
use App\Controllers\HealthController;
use App\Controllers\InstallController;
use App\Controllers\LangController;
use App\Controllers\LogController;
use App\Controllers\MuteController;
use App\Controllers\OrderController;
use App\Controllers\PanelUserController;
use App\Controllers\ProfileController;
use App\Controllers\ReportController;
use App\Controllers\ServerController;
use App\Controllers\SanctionBulkController;
use App\Controllers\SettingsController;
use App\Controllers\StoreController;
use App\Controllers\UpdateController;
use App\Controllers\VipController;
use App\Controllers\WarnController;
use App\Controllers\ZenithController;
use App\Controllers\K4Controller;
use App\Controllers\CreditsController;
use App\Controllers\SkinsController;
use App\Controllers\BenefitController;

$router = new Router();

           
$router->get('/lang/{code}', [LangController::class, 'set']);

// Setup wizard
$router->get('/install', [InstallController::class, 'index']);
$router->post('/install', [InstallController::class, 'run']);

            
$router->get('/', [DashboardController::class, 'index']);

// Owner-only diagnostics (no app chrome, so it answers even when a page breaks)
$router->get('/health', [HealthController::class, 'index']);

// Auth
$router->get('/login', [AuthController::class, 'loginPage']);
$router->get('/login/servers/{id}/info', [ServerController::class, 'loginInfo']);
$router->get('/auth/steam', [AuthController::class, 'login']);
$router->get('/auth/steam/return', [AuthController::class, 'callback']);
$router->get('/auth/guest', [AuthController::class, 'guest']);
$router->get('/logout', [AuthController::class, 'logout']);

// Panel profile (own Steam identity + Discord handle)
$router->get('/profile/card', [ProfileController::class, 'card']);
$router->post('/profile', [ProfileController::class, 'save']);

// Bans
$router->get('/bans', [BanController::class, 'index']);
$router->post('/bans', [BanController::class, 'store']);
$router->post('/bans/bulk-update', [SanctionBulkController::class, 'bans']);
$router->post('/bans/delete-all', [BanController::class, 'deleteAll']);
$router->post('/bans/{id}/update', [BanController::class, 'update']);
$router->post('/bans/{id}/unban', [BanController::class, 'unban']);
$router->post('/bans/{id}/delete', [BanController::class, 'delete']);
$router->get('/bans/{id}', [BanController::class, 'show']);

// Mutes / Gags / Silences
$router->get('/mutes', [MuteController::class, 'index']);
$router->post('/mutes', [MuteController::class, 'store']);
$router->post('/mutes/bulk-update', [SanctionBulkController::class, 'mutes']);
$router->post('/mutes/{id}/unmute', [MuteController::class, 'unmute']);
$router->post('/mutes/{id}/delete', [MuteController::class, 'delete']);

// Ban appeals (players appeal their own punishment)
$router->get('/appeals', [AppealController::class, 'index']);
$router->post('/appeals', [AppealController::class, 'store']);
$router->post('/appeals/{id}/resolve', [AppealController::class, 'resolve']);
$router->post('/appeals/{id}/delete', [AppealController::class, 'delete']);

// Player reports
$router->get('/reports', [ReportController::class, 'index']);
$router->post('/reports', [ReportController::class, 'store']);
$router->post('/reports/{id}/resolve', [ReportController::class, 'resolve']);
$router->post('/reports/{id}/delete', [ReportController::class, 'delete']);

// Warns
$router->get('/warns', [WarnController::class, 'index']);
$router->post('/warns', [WarnController::class, 'store']);
$router->post('/warns/{id}/expire', [WarnController::class, 'expire']);
$router->post('/warns/{id}/delete', [WarnController::class, 'delete']);

// In-game admins & Groups
$router->get('/admins', [AdminController::class, 'index']);
$router->post('/admins', [AdminController::class, 'store']);
$router->post('/admins/{id}/update', [AdminController::class, 'update']);
$router->post('/admins/{id}/delete', [AdminController::class, 'delete']);

// Admin groups (root only)
$router->get('/groups', [GroupController::class, 'index']);
$router->post('/groups', [GroupController::class, 'store']);
$router->post('/groups/{id}/update', [GroupController::class, 'update']);
$router->post('/groups/{id}/delete', [GroupController::class, 'delete']);

// Panel users (site access) - root only
$router->get('/panel-users', [PanelUserController::class, 'index']);
$router->post('/panel-users', [PanelUserController::class, 'store']);
$router->post('/panel-users/{id}/update', [PanelUserController::class, 'update']);
$router->post('/panel-users/{id}/delete', [PanelUserController::class, 'delete']);

// Action log
$router->get('/logs', [LogController::class, 'index']);
$router->post('/logs/clear-errors', [LogController::class, 'clearErrors']);
$router->post('/logs/clear-actions', [LogController::class, 'clearActions']);
$router->get('/chat-logs', [ChatLogController::class, 'index']);

// Panel self-update (root only)
$router->get('/update', [UpdateController::class, 'index']);
$router->post('/update/check', [UpdateController::class, 'check']);
$router->post('/update/run', [UpdateController::class, 'run']);
$router->post('/update/step', [UpdateController::class, 'step']);

// Site settings (root or can_manage)
$router->get('/settings', [SettingsController::class, 'index']);
$router->post('/settings', [SettingsController::class, 'save']);
$router->post('/settings/test-discord', [SettingsController::class, 'testDiscord']);
$router->post('/settings/skins-rarity-refresh', [SettingsController::class, 'skinsRarityRefresh']);
$router->post('/settings/test-mail', [SettingsController::class, 'testMail']);
$router->post('/settings/theme', [SettingsController::class, 'theme']);

// Server management + RCON
$router->get('/servers', [ServerController::class, 'index']);
$router->post('/servers', [ServerController::class, 'store']);
$router->post('/servers/{id}/update', [ServerController::class, 'update']);
$router->post('/servers/{id}/delete', [ServerController::class, 'delete']);
$router->post('/servers/{id}/rcon', [ServerController::class, 'rcon']);
$router->get('/servers/{id}/players', [ServerController::class, 'players']);
$router->get('/servers/{id}/info', [ServerController::class, 'info']);
$router->post('/servers/{id}/player-action', [ServerController::class, 'playerAction']);

          
$router->get('/vip', [VipController::class, 'index']);
$router->post('/vip', [VipController::class, 'store']);
$router->post('/vip/extend', [VipController::class, 'extend']);
$router->post('/vip/update', [VipController::class, 'update']);
$router->post('/vip/remove', [VipController::class, 'remove']);

// General maintenance cron (token-protected; called from the host's crontab).
// Runs ALL periodic panel work: contest start/finalize + history purge, expired
// admin-perk revocation, and stale (unpaid) order cancellation.
$router->get('/cron', [ContestController::class, 'cron']);
$router->post('/cron', [ContestController::class, 'cron']);

// Contests (rank-based VIP giveaways; separate Discord webhook)
$router->get('/contests', [ContestController::class, 'index']);
$router->post('/contests', [ContestController::class, 'store']);
$router->post('/contests/{id}/process', [ContestController::class, 'process']);
$router->post('/contests/{id}/grant-all', [ContestController::class, 'grantAll']);
$router->post('/contests/{id}/grant/{winnerId}', [ContestController::class, 'grantWinner']);
$router->post('/contests/{id}/redraw', [ContestController::class, 'redraw']);
$router->post('/contests/{id}/update', [ContestController::class, 'update']);
$router->post('/contests/{id}/resend', [ContestController::class, 'resend']);
$router->post('/contests/{id}/delete', [ContestController::class, 'delete']);
$router->get('/contests/{id}', [ContestController::class, 'show']);

// Store (public shop; PayPal). Static routes only — order among these is safe.
$router->get('/store', [StoreController::class, 'index']);
$router->post('/store/checkout', [StoreController::class, 'checkout']);
$router->get('/store/return', [StoreController::class, 'return']);
$router->get('/store/cancel', [StoreController::class, 'cancel']);
// PayPal webhook: no CSRF / no auth (verified by PayPal signature instead).
$router->post('/store/webhook', [StoreController::class, 'webhook']);
// The buyer's own purchase history.
$router->get('/store/mine', [StoreController::class, 'mine']);
// Cart
$router->get('/store/cart', [StoreController::class, 'cart']);
$router->post('/store/cart/add', [StoreController::class, 'cartAdd']);
$router->get('/store/cart/mini', [StoreController::class, 'miniCart']);
$router->post('/store/cart/remove', [StoreController::class, 'cartRemove']);
$router->post('/store/cart/clear', [StoreController::class, 'cartClear']);
$router->post('/store/cart/code', [StoreController::class, 'cartCode']);
$router->post('/store/cart/checkout', [StoreController::class, 'cartCheckout']);
$router->post('/store/donate', [StoreController::class, 'donate']);
$router->post('/store/credits/checkout', [StoreController::class, 'creditsCheckout']);

// Orders (admin). Static list, then param actions (root-only in controller).
$router->get('/orders', [OrderController::class, 'index']);
$router->post('/orders/{id}/refund', [OrderController::class, 'refund']);
$router->post('/orders/{id}/regrant', [OrderController::class, 'regrant']);
$router->post('/orders/{id}/test-paid', [OrderController::class, 'markPaidTest']);
$router->post('/orders/{id}/test-email', [OrderController::class, 'resendEmail']);
$router->post('/orders/{id}/test-discord', [OrderController::class, 'testDiscordPurchase']);

// K4-Zenith (Stats group: Ranks + Play Time)
$router->get('/zenith/ranks', [ZenithController::class, 'ranks']);
$router->post('/zenith/ranks/reset', [ZenithController::class, 'resetRank']);
$router->post('/zenith/ranks/reset-all', [ZenithController::class, 'resetAllRanks']);
$router->post('/zenith/ranks/edit-points', [ZenithController::class, 'editPoints']);
$router->post('/zenith/ranks/edit-stats', [ZenithController::class, 'editStats']);
$router->get('/zenith/stats', [ZenithController::class, 'stats']);
$router->post('/zenith/stats/reset', [ZenithController::class, 'resetStats']);
$router->post('/zenith/stats/reset-all', [ZenithController::class, 'resetAllStats']);
$router->get('/zenith/playtime', [ZenithController::class, 'playtime']);
$router->post('/zenith/playtime/reset', [ZenithController::class, 'resetPlaytime']);
$router->post('/zenith/playtime/reset-all', [ZenithController::class, 'resetAllPlaytime']);
$router->post('/zenith/playtime/edit', [ZenithController::class, 'editPlaytime']);

// K4-System (separate stats backend: Ranks + Stats + Play Time)
$router->get('/k4/ranks', [K4Controller::class, 'ranks']);
$router->post('/k4/ranks/reset', [K4Controller::class, 'resetRank']);
$router->post('/k4/ranks/reset-all', [K4Controller::class, 'resetAllRanks']);
$router->post('/k4/ranks/edit-points', [K4Controller::class, 'editPoints']);
$router->post('/k4/ranks/edit-stats', [K4Controller::class, 'editStats']);
$router->get('/k4/stats', [K4Controller::class, 'stats']);
$router->post('/k4/stats/reset', [K4Controller::class, 'resetStats']);
$router->post('/k4/stats/reset-all', [K4Controller::class, 'resetAllStats']);
$router->get('/k4/playtime', [K4Controller::class, 'playtime']);
$router->post('/k4/playtime/reset', [K4Controller::class, 'resetPlaytime']);
$router->post('/k4/playtime/reset-all', [K4Controller::class, 'resetAllPlaytime']);
$router->post('/k4/playtime/edit', [K4Controller::class, 'editPlaytime']);

// Store credits (Statistics group). View = any logged-in user; writes = root.
$router->get('/credits', [CreditsController::class, 'index']);
$router->post('/credits/set', [CreditsController::class, 'set']);
$router->post('/credits/add', [CreditsController::class, 'add']);
$router->post('/credits/subtract', [CreditsController::class, 'subtract']);
$router->post('/credits/reset', [CreditsController::class, 'reset']);
$router->post('/credits/reset-all', [CreditsController::class, 'resetAll']);

// WeaponPaints "Skins" loadout editor (any logged-in user; gated on skins_enabled)
$router->get('/skins', [SkinsController::class, 'index']);
$router->post('/skins/save', [SkinsController::class, 'save']);

// Benefits page (public, gated on benefits_enabled in controller)
$router->get('/benefits', [BenefitController::class, 'index']);
$router->post('/benefits/save', [BenefitController::class, 'save']);

return $router;

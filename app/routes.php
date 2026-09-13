<?php

/**
 * Route table for the single front controller (app/index.php). Routes are
 * addressed by name via ?route=xxx (see App\Core\Router) — replaces the old
 * ?page=xxx if/elseif chain in app/index.php and the ~30 isset($_POST[...])
 * blocks in server/app.php.
 */

use App\Core\Router;
use App\Controllers\DashboardController;
use App\Controllers\SmsSenderController;
use App\Controllers\NotificationController;
use App\Controllers\ContactController;
use App\Controllers\GroupController;
use App\Controllers\SegmentController;
use App\Controllers\CampaignController;
use App\Controllers\TemplateController;
use App\Controllers\ReportController;
use App\Controllers\SmsHistoryController;
use App\Controllers\JournalController;
use App\Controllers\CreditController;
use App\Controllers\OrganizationController;
use App\Controllers\TeamController;

$router = new Router();

$router->get('dashboard.index', DashboardController::class, 'index');

$router->get('sms.index', SmsSenderController::class, 'index');
$router->post('sms.sendSingle', SmsSenderController::class, 'sendSingle');

$router->post('notifications.markAllRead', NotificationController::class, 'markAllRead');

$router->get('contacts.index', ContactController::class, 'index');
$router->post('contacts.create', ContactController::class, 'create');
$router->post('contacts.delete', ContactController::class, 'delete');
$router->post('contacts.import', ContactController::class, 'import');
$router->get('contacts.exportErrors', ContactController::class, 'exportErrors');

$router->get('groups.index', GroupController::class, 'index');
$router->get('groups.show', GroupController::class, 'show');
$router->post('groups.create', GroupController::class, 'create');
$router->post('groups.delete', GroupController::class, 'delete');
$router->post('groups.addContact', GroupController::class, 'addContact');
$router->post('groups.removeContact', GroupController::class, 'removeContact');
$router->post('groups.sendMessage', GroupController::class, 'sendMessage');

$router->get('segments.index', SegmentController::class, 'index');
$router->post('segments.create', SegmentController::class, 'create');
$router->post('segments.delete', SegmentController::class, 'delete');
$router->get('segments.previewCount', SegmentController::class, 'previewCount');

$router->get('campaigns.index', CampaignController::class, 'index');
$router->get('campaigns.show', CampaignController::class, 'show');
$router->post('campaigns.create', CampaignController::class, 'create');
$router->post('campaigns.importExcel', CampaignController::class, 'importExcel');
$router->post('campaigns.addRecipientsFromAudience', CampaignController::class, 'addRecipientsFromAudience');
$router->post('campaigns.stopRecurrence', CampaignController::class, 'stopRecurrence');
$router->post('campaigns.sendTestSms', CampaignController::class, 'sendTestSms');
$router->post('campaigns.launch', CampaignController::class, 'launch');
$router->post('campaigns.schedule', CampaignController::class, 'schedule');
$router->post('campaigns.unschedule', CampaignController::class, 'unschedule');
$router->post('campaigns.pause', CampaignController::class, 'pause');
$router->post('campaigns.resume', CampaignController::class, 'resume');
$router->post('campaigns.cancel', CampaignController::class, 'cancel');
$router->post('campaigns.retryFailures', CampaignController::class, 'retryFailures');
$router->get('campaigns.poll', CampaignController::class, 'poll');
$router->get('campaigns.previewTools', CampaignController::class, 'previewTools');

$router->get('templates.index', TemplateController::class, 'index');
$router->post('templates.create', TemplateController::class, 'create');
$router->post('templates.update', TemplateController::class, 'update');
$router->post('templates.duplicate', TemplateController::class, 'duplicate');
$router->post('templates.archive', TemplateController::class, 'archive');
$router->post('templates.unarchive', TemplateController::class, 'unarchive');

$router->get('reports.index', ReportController::class, 'index');

$router->get('sms-history.index', SmsHistoryController::class, 'index');

$router->get('journal.index', JournalController::class, 'index');

$router->get('credits.index', CreditController::class, 'index');
$router->post('credits.recharge', CreditController::class, 'recharge');

$router->get('organization.index', OrganizationController::class, 'index');
$router->post('organization.update', OrganizationController::class, 'update');

$router->get('team.index', TeamController::class, 'index');
$router->post('team.create', TeamController::class, 'create');
$router->post('team.updateRole', TeamController::class, 'updateRole');
$router->post('team.delete', TeamController::class, 'delete');

return $router;

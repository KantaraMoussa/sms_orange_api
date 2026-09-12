<?php

namespace Tests\Integration;

use App\Services\ActivityLogger;
use App\Services\AuthService;
use App\Services\ContactService;
use App\Services\NotificationService;
use App\Services\SmsTemplateService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie le critère d'acceptation §59 : "Une entreprise ne peut jamais voir
 * les données d'une autre." Crée deux organisations réelles (via le même
 * chemin que app/register.php) et s'assure qu'aucun Service scopé ne laisse
 * fuiter les données de l'une vers l'autre — pas seulement qu'elles sont
 * correctement taguées à la création.
 *
 * Runs against the real apiSms database. All rows use a nom/email prefixed
 * "PHPUNITORG-" and are deleted in tearDown() (organizations en dernier, une
 * fois que les FK enfants ont été nettoyées).
 */
class OrganizationIsolationTest extends TestCase
{
    private PDO $pdo;
    private AuthService $auth;
    /** @var int[] */
    private array $orgIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->auth = new AuthService($this->pdo);
    }

    protected function tearDown(): void
    {
        if (empty($this->orgIds)) {
            return;
        }
        $ids = implode(',', array_map('intval', $this->orgIds));
        $this->pdo->exec("DELETE FROM groupe_contacts_v2 WHERE contact_id IN (SELECT id FROM contacts_v2 WHERE organization_id IN ($ids))");
        $this->pdo->exec("DELETE FROM contacts_v2 WHERE organization_id IN ($ids)");
        $this->pdo->exec("DELETE FROM groupes_v2 WHERE organization_id IN ($ids)");
        $this->pdo->exec("DELETE FROM sms_templates WHERE organization_id IN ($ids)");
        $this->pdo->exec("DELETE FROM notifications WHERE organization_id IN ($ids)");
        $this->pdo->exec("DELETE FROM activity_logs WHERE organization_id IN ($ids)");
        $this->pdo->exec("DELETE FROM utilisateurs WHERE organization_id IN ($ids)");
        $this->pdo->exec("DELETE FROM organizations WHERE id IN ($ids)");
    }

    private function registerOrg(string $suffix): int
    {
        $result = $this->auth->registerOrganization(
            ['nom' => "PHPUNITORG-$suffix"],
            "Owner $suffix",
            "phpunitorg-$suffix@example.com",
            'password1234'
        );
        $this->orgIds[] = $result['organization_id'];

        return $result['organization_id'];
    }

    public function testRegisterOrganizationCreatesOwnerInNewOrganization(): void
    {
        $orgId = $this->registerOrg('A');

        $stmt = $this->pdo->prepare("SELECT role, organization_id FROM utilisateurs WHERE email = :e");
        $stmt->execute([':e' => 'phpunitorg-A@example.com']);
        $user = $stmt->fetch();

        $this->assertSame('OWNER', $user['role']);
        $this->assertSame($orgId, (int) $user['organization_id']);
    }

    public function testRegisterOrganizationRejectsDuplicateEmail(): void
    {
        $this->registerOrg('Dup');

        $this->expectException(\Exception::class);
        $this->registerOrg('Dup');
    }

    public function testContactsAreIsolatedBetweenOrganizations(): void
    {
        $orgA = $this->registerOrg('C1');
        $orgB = $this->registerOrg('C2');

        $contactsA = new ContactService($this->pdo, $orgA);
        $contactsB = new ContactService($this->pdo, $orgB);

        $contactsA->createContact('PHPUNITORG-Contact', 'A', '622991001');

        $this->assertCount(1, $contactsA->allContacts());
        $this->assertCount(0, $contactsB->allContacts(), 'org B must not see org A contacts');
    }

    public function testSamePhoneNumberIsAllowedAcrossDifferentOrganizations(): void
    {
        $orgA = $this->registerOrg('P1');
        $orgB = $this->registerOrg('P2');

        $idA = (new ContactService($this->pdo, $orgA))->createContact('PHPUNITORG-Same', 'A', '622991002');
        $idB = (new ContactService($this->pdo, $orgB))->createContact('PHPUNITORG-Same', 'B', '622991002');

        $this->assertNotSame($idA, $idB, 'two organizations must be able to independently own a contact with the same phone number');
    }

    public function testCrossOrganizationIdDoesNotLeakOnDeleteOrAddToGroup(): void
    {
        $orgA = $this->registerOrg('X1');
        $orgB = $this->registerOrg('X2');

        $contactsA = new ContactService($this->pdo, $orgA);
        $contactsB = new ContactService($this->pdo, $orgB);

        $contactId = $contactsA->createContact('PHPUNITORG-Guarded', 'A', '622991003');
        $groupIdB = $contactsB->createGroup('PHPUNITGROUP-ORGISO');

        // org B tente d'ajouter le contact de org A à son propre groupe.
        $contactsB->addContactToGroup($groupIdB, $contactId);
        $this->assertCount(0, $contactsB->allContacts($groupIdB), 'a group must not gain a member belonging to another organization');

        // org B tente de supprimer le contact de org A : ne doit rien faire.
        $contactsB->deleteContact($contactId);
        $this->assertCount(1, $contactsA->allContacts(), 'org B must not be able to delete org A\'s contact');
    }

    public function testTemplatesAreIsolatedBetweenOrganizations(): void
    {
        $orgA = $this->registerOrg('T1');
        $orgB = $this->registerOrg('T2');

        $templatesA = new SmsTemplateService($this->pdo, $orgA);
        $templatesB = new SmsTemplateService($this->pdo, $orgB);

        $id = $templatesA->create('PHPUNITTPL-ORGISO', 'notification', 'contenu');

        $this->assertNotNull($templatesA->find($id));
        $this->assertNull($templatesB->find($id), 'org B must not be able to read org A\'s template by id');
        $this->assertCount(0, $templatesB->all());

        $this->pdo->exec("DELETE FROM sms_templates WHERE id = $id");
    }

    public function testNotificationsAreIsolatedBetweenOrganizations(): void
    {
        $orgA = $this->registerOrg('N1');
        $orgB = $this->registerOrg('N2');

        $notificationsA = new NotificationService($this->pdo, $orgA);
        $notificationsB = new NotificationService($this->pdo, $orgB);

        $notificationsA->create('phpunit_test_notif', 'Org A only');

        $this->assertSame(1, $notificationsA->unreadCount());
        $this->assertSame(0, $notificationsB->unreadCount(), 'org B must not count org A\'s notifications');
        $this->assertEmpty($notificationsB->recent());
    }

    public function testActivityLogsAreIsolatedBetweenOrganizations(): void
    {
        $orgA = $this->registerOrg('L1');
        $orgB = $this->registerOrg('L2');

        $loggerA = new ActivityLogger($this->pdo, $orgA);
        $loggerB = new ActivityLogger($this->pdo, $orgB);

        $loggerA->log('phpunit_test_action', null, 'PHPUNITORG-Actor');

        $recentA = array_column($loggerA->recent(), 'action');
        $recentB = array_column($loggerB->recent(), 'action');

        $this->assertContains('phpunit_test_action', $recentA);
        $this->assertNotContains('phpunit_test_action', $recentB);
    }
}

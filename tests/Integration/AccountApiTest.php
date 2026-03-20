<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Account\Domain\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AccountApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement('DELETE FROM ledger_entries');
        $this->em->getConnection()->executeStatement('DELETE FROM transfers');
        $this->em->getConnection()->executeStatement('DELETE FROM accounts');
    }

    public function testCreateAccount(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/accounts', [
            'currency'        => 'EUR',
            'initial_balance' => 5000,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertNotNull($data['id']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $data['id'],
            'Account id must be a valid UUID'
        );
        $this->assertEquals('EUR', $data['currency']);
        $this->assertEquals('active', $data['status']);
        $this->assertEquals(5000, $data['balance']);
        $this->assertEquals('50.00', $data['balanceFormatted']);
    }

    public function testCreateAccountDefaultsToZeroBalance(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/accounts', [
            'currency' => 'USD',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(0, $data['balance']);
        $this->assertEquals('USD', $data['currency']);
    }

    public function testCreateAccountValidationFailed(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/accounts', []);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('validation_failed', $data['error']);
        $this->assertNotEmpty($data['details']);
    }

    public function testCreateAccountInvalidCurrency(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/accounts', [
            'currency' => 'EURO', // 4 letters — invalid
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('validation_failed', json_decode($response->getContent(), true)['error']);
    }

    public function testSuspendAccount(): void
    {
        $account = new Account('EUR', 100_00);
        $this->em->persist($account);
        $this->em->flush();

        $this->client->request('POST', '/api/v1/accounts/' . $account->getUuid() . '/suspend');

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('suspended', $data['status']);

        // Verify the status is persisted in the DB
        $this->em->clear();
        $this->assertEquals('suspended', $this->em->find(Account::class, $account->getId())->getStatus());
    }

    public function testSuspendAccountIsIdempotent(): void
    {
        $account = new Account('EUR', 100_00);
        $account->suspend();
        $this->em->persist($account);
        $this->em->flush();

        // Suspending again must return 200, not an error
        $this->client->request('POST', '/api/v1/accounts/' . $account->getUuid() . '/suspend');
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
    }

    public function testActivateAccount(): void
    {
        $account = new Account('EUR', 100_00);
        $account->suspend();
        $this->em->persist($account);
        $this->em->flush();

        $this->client->request('POST', '/api/v1/accounts/' . $account->getUuid() . '/activate');

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('active', $data['status']);
    }

    public function testCannotTransferFromSuspendedThenActivateAndRetry(): void
    {
        $from = new Account('EUR', 100_00);
        $to   = new Account('EUR', 50_00);
        $from->suspend();
        $this->em->persist($from);
        $this->em->persist($to);
        $this->em->flush();

        // Transfer fails while suspended
        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 10_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'b2c3d4e5-f6a1-4b2c-8d3e-000000000001',
        ]);
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        // Activate the account
        $this->client->request('POST', '/api/v1/accounts/' . $from->getUuid() . '/activate');
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        // Transfer succeeds after activation (different idempotency key)
        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 10_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'b2c3d4e5-f6a1-4b2c-8d3e-000000000002',
        ]);
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());
    }

    public function testSuspendNotFound(): void
    {
        $this->client->request('POST', '/api/v1/accounts/00000000-0000-7000-8000-000000000001/suspend');
        $this->assertEquals(404, $this->client->getResponse()->getStatusCode());
    }

    public function testActivateNotFound(): void
    {
        $this->client->request('POST', '/api/v1/accounts/00000000-0000-7000-8000-000000000001/activate');
        $this->assertEquals(404, $this->client->getResponse()->getStatusCode());
    }

    public function testGetAccount(): void
    {
        $account = new Account('EUR', 100_00);
        $this->em->persist($account);
        $this->em->flush();

        $this->client->request('GET', '/api/v1/accounts/' . $account->getUuid());

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals($account->getUuid(), $data['id']);
        $this->assertEquals('EUR', $data['currency']);
        $this->assertEquals(100_00, $data['balance']);
        $this->assertEquals('100.00', $data['balanceFormatted']);
    }

    public function testAccountNotFound(): void
    {
        $this->client->request('GET', '/api/v1/accounts/00000000-0000-7000-8000-000000000001');

        $response = $this->client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('account_not_found', $data['error']);
    }

    public function testBalanceAfterTransfer(): void
    {
        $from = new Account('EUR', 100_00);
        $to = new Account('EUR', 50_00);
        $this->em->persist($from);
        $this->em->persist($to);
        $this->em->flush();

        // Perform transfer
        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id' => $to->getUuid(),
            'amount' => 25_00,
            'currency' => 'EUR',
            'idempotency_key' => 'b2c3d4e5-f6a1-4b2c-8d3e-000000000003',
        ]);
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        // Check from account
        $this->client->request('GET', '/api/v1/accounts/' . $from->getUuid());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(75_00, $data['balance']);
        $this->assertEquals('75.00', $data['balanceFormatted']);

        // Check to account
        $this->client->request('GET', '/api/v1/accounts/' . $to->getUuid());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(75_00, $data['balance']);
        $this->assertEquals('75.00', $data['balanceFormatted']);
    }

    public function testLedgerAfterTransfer(): void
    {
        $from = new Account('EUR', 100_00);
        $to   = new Account('EUR', 50_00);
        $this->em->persist($from);
        $this->em->persist($to);
        $this->em->flush();

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 30_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'b2c3d4e5-f6a1-4b2c-8d3e-000000000004',
        ]);
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        // Sender ledger: one DEBIT entry
        $this->client->request('GET', '/api/v1/accounts/' . $from->getUuid() . '/ledger');
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(1, $body['total']);
        $this->assertCount(1, $body['data']);
        $this->assertEquals('debit', $body['data'][0]['type']);
        $this->assertEquals(30_00, $body['data'][0]['amount']);
        $this->assertEquals('EUR', $body['data'][0]['currency']);
        $this->assertEquals(70_00, $body['data'][0]['balanceAfter']); // 100 - 30 = 70

        // Receiver ledger: one CREDIT entry
        $this->client->request('GET', '/api/v1/accounts/' . $to->getUuid() . '/ledger');
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(1, $body['total']);
        $this->assertCount(1, $body['data']);
        $this->assertEquals('credit', $body['data'][0]['type']);
        $this->assertEquals(30_00, $body['data'][0]['amount']);
        $this->assertEquals(80_00, $body['data'][0]['balanceAfter']); // 50 + 30 = 80
    }

    public function testLedgerAccountNotFound(): void
    {
        $this->client->request('GET', '/api/v1/accounts/00000000-0000-7000-8000-000000000001/ledger');
        $this->assertEquals(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTransferHistoryFilterBySent(): void
    {
        $from = new Account('EUR', 200_00);
        $to   = new Account('EUR', 100_00);
        $this->em->persist($from);
        $this->em->persist($to);
        $this->em->flush();

        // from → to
        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 20_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'b2c3d4e5-f6a1-4b2c-8d3e-000000000005',
        ]);
        // to → from
        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $to->getUuid(),
            'to_account_id'   => $from->getUuid(),
            'amount'          => 10_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'b2c3d4e5-f6a1-4b2c-8d3e-000000000006',
        ]);

        // Filter: only transfers SENT by $from
        $this->client->request('GET', '/api/v1/accounts/' . $from->getUuid() . '/transfers?direction=sent');
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(1, $body['total']);
        $this->assertCount(1, $body['data']);
        $this->assertEquals($from->getUuid(), $body['data'][0]['fromAccountId']);

        // Filter: only transfers RECEIVED by $from
        $this->client->request('GET', '/api/v1/accounts/' . $from->getUuid() . '/transfers?direction=received');
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(1, $body['total']);
        $this->assertCount(1, $body['data']);
        $this->assertEquals($from->getUuid(), $body['data'][0]['toAccountId']);
    }

    public function testCloseAccount(): void
    {
        // Create an account with zero balance — closure requires empty balance
        $account = new Account('EUR', 0);
        $this->em->persist($account);
        $this->em->flush();

        $this->client->request('POST', '/api/v1/accounts/' . $account->getUuid() . '/close');
        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('closed', $data['status']);
    }

    public function testCannotCloseAccountWithBalance(): void
    {
        // Balance must be zero — you must transfer out first
        $account = new Account('EUR', 50_00);
        $this->em->persist($account);
        $this->em->flush();

        $this->client->request('POST', '/api/v1/accounts/' . $account->getUuid() . '/close');
        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('domain_rule_violated', $data['error']);
    }

    public function testCloseAccountIsIdempotent(): void
    {
        $account = new Account('EUR', 0);
        $this->em->persist($account);
        $this->em->flush();

        // First close
        $this->client->request('POST', '/api/v1/accounts/' . $account->getUuid() . '/close');
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        // Second close — must still return 200, not error
        $this->client->request('POST', '/api/v1/accounts/' . $account->getUuid() . '/close');
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('closed', $data['status']);
    }

    public function testTransferHistoryIncludesReversedBy(): void
    {
        $from = new Account('EUR', 100_00);
        $to   = new Account('EUR', 50_00);
        $this->em->persist($from);
        $this->em->persist($to);
        $this->em->flush();

        // Create a transfer
        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 30_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'b2c3d4e5-f6a1-4b2c-8d3e-000000000007',
        ]);
        $original = json_decode($this->client->getResponse()->getContent(), true);

        // Reverse it
        $this->client->request('POST', '/api/v1/transfers/' . $original['id'] . '/reverse');
        $reversal = json_decode($this->client->getResponse()->getContent(), true);

        // Check transfer history for sender — original entry should have reversedBy set
        $this->client->request('GET', '/api/v1/accounts/' . $from->getUuid() . '/transfers');
        $body = json_decode($this->client->getResponse()->getContent(), true);

        // Should have 2 entries: original (sent) + reversal (received back)
        $this->assertEquals(2, $body['total']);

        // Find the original in the list and verify reversedBy
        $found = null;
        foreach ($body['data'] as $t) {
            if ($t['id'] === $original['id']) {
                $found = $t;
                break;
            }
        }
        $this->assertNotNull($found, 'Original transfer not found in history');
        $this->assertEquals('completed', $found['status']); // append-only: still completed
        $this->assertEquals($reversal['id'], $found['reversedBy']);
    }
}

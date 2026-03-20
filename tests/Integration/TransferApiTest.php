<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Account\Domain\Account;
use App\Module\Transfer\Domain\Event\TransferFailedEvent;
use App\Module\Transfer\Domain\Transfer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TransferApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Clean tables — order matters: child tables with FK constraints first.
        // ledger_entries references accounts → must delete before accounts.
        $this->em->getConnection()->executeStatement('DELETE FROM ledger_entries');
        $this->em->getConnection()->executeStatement('DELETE FROM transfers');
        $this->em->getConnection()->executeStatement('DELETE FROM accounts');
    }

    private function createAccount(string $currency, int $balance): Account
    {
        $account = new Account($currency, $balance);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testSuccessfulTransfer(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to = $this->createAccount('EUR', 50_00);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id' => $to->getUuid(),
            'amount' => 30_00,
            'currency' => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000001',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals(30_00, $data['amount']);
        $this->assertEquals('EUR', $data['currency']);

        // Verify balances updated correctly
        $this->em->clear();
        $fromRefreshed = $this->em->find(Account::class, $from->getId());
        $toRefreshed = $this->em->find(Account::class, $to->getId());

        $this->assertEquals(70_00, $fromRefreshed->getBalance());
        $this->assertEquals(80_00, $toRefreshed->getBalance());
    }

    public function testInsufficientFunds(): void
    {
        $from = $this->createAccount('EUR', 10_00);
        $to = $this->createAccount('EUR', 50_00);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id' => $to->getUuid(),
            'amount' => 50_00,
            'currency' => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000002',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('insufficient_funds', $data['error']);
    }

    public function testCurrencyMismatch(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to = $this->createAccount('USD', 50_00);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id' => $to->getUuid(),
            'amount' => 30_00,
            'currency' => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000003',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('currency_mismatch', $data['error']);
    }

    public function testSelfTransfer(): void
    {
        $account = $this->createAccount('EUR', 100_00);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $account->getUuid(),
            'to_account_id' => $account->getUuid(),
            'amount' => 30_00,
            'currency' => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000004',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('validation_failed', $data['error']);
    }

    public function testIdempotency(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to = $this->createAccount('EUR', 50_00);

        $payload = [
            'from_account_id' => $from->getUuid(),
            'to_account_id' => $to->getUuid(),
            'amount' => 25_00,
            'currency' => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000005',
        ];

        // First request
        $this->client->jsonRequest('POST', '/api/v1/transfers', $payload);
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());
        $first = json_decode($this->client->getResponse()->getContent(), true);

        // Second request with same idempotency key — must return same result
        $this->client->jsonRequest('POST', '/api/v1/transfers', $payload);
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());
        $second = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals($first['id'], $second['id']);

        // Balance changed only once
        $this->em->clear();
        $fromRefreshed = $this->em->find(Account::class, $from->getId());
        $this->assertEquals(75_00, $fromRefreshed->getBalance());
    }

    public function testAccountNotFound(): void
    {
        $from = $this->createAccount('EUR', 100_00);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id' => '00000000-0000-7000-8000-000000000001', // valid UUID format, no such account
            'amount' => 10_00,
            'currency' => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000006',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('account_not_found', $data['error']);
    }

    public function testInvalidPayload(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/transfers', []);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('validation_failed', $data['error']);
        $this->assertNotEmpty($data['details']);
    }

    public function testNegativeAmount(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to = $this->createAccount('EUR', 50_00);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id' => $to->getUuid(),
            'amount' => -10_00,
            'currency' => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000007',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testZeroAmount(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to = $this->createAccount('EUR', 50_00);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id' => $to->getUuid(),
            'amount' => 0,
            'currency' => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000008',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testInvalidIdempotencyKeyFormat(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to   = $this->createAccount('EUR', 50_00);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 10_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'not-a-uuid', // must be UUID v4
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('validation_failed', $data['error']);

        $fields = array_column($data['details'], 'field');
        $this->assertContains('idempotencyKey', $fields);
    }

    public function testInvalidJson(): void
    {
        $this->client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testSuspendedSourceAccount(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to   = $this->createAccount('EUR', 50_00);

        // Suspend the sending account (simulates KYC/AML compliance hold)
        $from->suspend();
        $this->em->flush();

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 30_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000009',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('account_suspended', $data['error']);

        // Verify balance was NOT changed
        $this->em->clear();
        $this->assertEquals(100_00, $this->em->find(Account::class, $from->getId())->getBalance());
    }

    public function testSuspendedDestinationAccount(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to   = $this->createAccount('EUR', 50_00);

        // Suspend the receiving account — money cannot flow into a suspended account
        $to->suspend();
        $this->em->flush();

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 30_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000010',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('account_suspended', $data['error']);

        // Verify neither balance changed — transaction rolled back
        $this->em->clear();
        $this->assertEquals(100_00, $this->em->find(Account::class, $from->getId())->getBalance());
        $this->assertEquals(50_00,  $this->em->find(Account::class, $to->getId())->getBalance());
    }

    public function testGetTransferById(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to   = $this->createAccount('EUR', 50_00);

        // Create a transfer first
        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 20_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000011',
        ]);
        $created = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        // Fetch it by UUID
        $this->client->request('GET', '/api/v1/transfers/' . $created['id']);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals($created['id'], $data['id']);
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals(20_00, $data['amount']);
        $this->assertEquals('EUR', $data['currency']);
    }

    public function testGetTransferByIdNotFound(): void
    {
        $this->client->request('GET', '/api/v1/transfers/00000000-0000-7000-8000-000000000001');

        $response = $this->client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('transfer_not_found', $data['error']);
    }

    public function testSingleTransferLimitExceeded(): void
    {
        // MAX_SINGLE_TRANSFER = 10,000,000 cents = €100,000.00
        // Send €100,001.00 (10,000,100 cents) — must be rejected
        $from = $this->createAccount('EUR', 20_000_000_00); // €20m — enough balance
        $to   = $this->createAccount('EUR', 0);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 10_000_100, // €100,001.00 — 1 cent over the limit
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000012',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('transfer_limit_exceeded', $data['error']);
        $this->assertEquals('single_transfer', $data['limit_type']);
        $this->assertEquals(10_000_000, $data['limit_amount']);

        // Balance must be unchanged
        $this->em->clear();
        $this->assertEquals(20_000_000_00, $this->em->find(Account::class, $from->getId())->getBalance());
    }

    public function testDailyOutgoingLimitExceeded(): void
    {
        // MAX_SINGLE_TRANSFER  = 10,000,000 cents (€100,000)
        // MAX_DAILY_OUTGOING   = 50,000,000 cents (€500,000)
        //
        // Strategy: send 5 transfers of €90,000 each (9,000,000 cents) — total €450,000.
        // Each individual transfer is within the single-transfer limit (9m < 10m).
        // The 6th transfer of €90,000 would bring the daily total to €540,000 > €500,000 — rejected.
        $from = $this->createAccount('EUR', 1_000_000_000); // €10m — plenty of balance
        $to   = $this->createAccount('EUR', 0);

        // Transfers 1–5: €90,000 each = €450,000 total outgoing — all allowed
        for ($i = 1; $i <= 5; $i++) {
            $this->client->jsonRequest('POST', '/api/v1/transfers', [
                'from_account_id' => $from->getUuid(),
                'to_account_id'   => $to->getUuid(),
                'amount'          => 9_000_000, // €90,000.00
                'currency'        => 'EUR',
                'idempotency_key' => sprintf('a1b2c3d4-e5f6-4a1b-8c2d-%012d', 20 + $i),
            ]);
            $this->assertEquals(201, $this->client->getResponse()->getStatusCode(), "Transfer $i should succeed");
        }

        // Transfer 6: €90,000 — would bring daily total to €540,000 > €500,000 limit — rejected
        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 9_000_000, // €90,000.00
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000026',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('transfer_limit_exceeded', $data['error']);
        $this->assertEquals('daily_outgoing', $data['limit_type']);
        $this->assertEquals(50_000_000, $data['limit_amount']);
    }

    public function testSuccessfulReversal(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to   = $this->createAccount('EUR', 50_00);

        // Create a transfer
        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 30_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000030',
        ]);
        $original = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        // Reverse it
        $this->client->request('POST', '/api/v1/transfers/' . $original['id'] . '/reverse');
        $response = $this->client->getResponse();
        $this->assertEquals(201, $response->getStatusCode());

        $reversal = json_decode($response->getContent(), true);
        $this->assertEquals('completed', $reversal['status']);
        $this->assertEquals(30_00, $reversal['amount']);
        $this->assertEquals($original['id'], $reversal['reversalOfTransferId']);

        // Append-only: original transfer must still be 'completed' — never mutated to 'reversed'
        $this->client->request('GET', '/api/v1/transfers/' . $original['id']);
        $refreshed = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('completed', $refreshed['status']);
        $this->assertEquals($reversal['id'], $refreshed['reversedBy']);

        // Balances should be back to original after reversal
        $this->em->clear();
        $this->assertEquals(100_00, $this->em->find(Account::class, $from->getId())->getBalance());
        $this->assertEquals(50_00,  $this->em->find(Account::class, $to->getId())->getBalance());
    }

    public function testCannotReverseTwice(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to   = $this->createAccount('EUR', 50_00);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 20_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000031',
        ]);
        $original = json_decode($this->client->getResponse()->getContent(), true);

        // First reversal — must succeed
        $this->client->request('POST', '/api/v1/transfers/' . $original['id'] . '/reverse');
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        // Second reversal — must fail with 409 Conflict
        $this->client->request('POST', '/api/v1/transfers/' . $original['id'] . '/reverse');
        $response = $this->client->getResponse();
        $this->assertEquals(409, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('transfer_already_reversed', $data['error']);
    }

    public function testReversalOfNonExistentTransfer(): void
    {
        $this->client->request('POST', '/api/v1/transfers/00000000-0000-7000-8000-000000000001/reverse');

        $response = $this->client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('transfer_not_found', $data['error']);
    }

    public function testCannotReverseFailedTransfer(): void
    {
        // STATUS_FAILED transfers are never created by the API (the transaction rolls back
        // before any Transfer record is persisted). We seed one directly to test the guard.
        $from = $this->createAccount('EUR', 100_00);
        $to   = $this->createAccount('EUR', 50_00);

        $failedTransfer = new Transfer(
            idempotencyKey: 'a1b2c3d4-e5f6-4a1b-8c2d-000000000032',
            fromAccount:    $from,
            toAccount:      $to,
            amount:         10_00,
            currency:       'EUR',
            status:         Transfer::STATUS_FAILED,
        );
        $this->em->persist($failedTransfer);
        $this->em->flush();

        $this->client->request('POST', '/api/v1/transfers/' . $failedTransfer->getUuid() . '/reverse');
        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('transfer_not_reversible', $data['error']);
    }

    /**
     * When a transfer fails, TransferFailedEvent must be dispatched.
     * The event drives two side effects: sender notification + fraud system flag.
     * Without this test, those side effects could silently stop working.
     *
     * We test three distinct failure reasons to verify each maps to the right
     * failureReason string on the event — the listener uses this to build the
     * notification message and set the risk level for the fraud system.
     */
    public function testTransferFailedEventIsDispatchedOnInsufficientFunds(): void
    {
        $from = $this->createAccount('EUR', 10_00);  // only €10
        $to   = $this->createAccount('EUR', 50_00);

        $capturedEvent = null;
        $this->listenForTransferFailedEvent($capturedEvent);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 50_00,              // €50 — more than available
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000050',
        ]);

        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());
        $this->assertNotNull($capturedEvent, 'TransferFailedEvent was not dispatched.');
        $this->assertEquals('insufficient_funds', $capturedEvent->failureReason);
        $this->assertFailedTransferPersisted($from->getUuid(), $to->getUuid(), 'insufficient_funds');
    }

    public function testTransferFailedEventIsDispatchedOnCurrencyMismatch(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to   = $this->createAccount('USD', 50_00);  // different currency

        $capturedEvent = null;
        $this->listenForTransferFailedEvent($capturedEvent);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 20_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000051',
        ]);

        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());
        $this->assertNotNull($capturedEvent, 'TransferFailedEvent was not dispatched.');
        $this->assertEquals('currency_mismatch', $capturedEvent->failureReason);
        $this->assertFailedTransferPersisted($from->getUuid(), $to->getUuid(), 'currency_mismatch');
    }

    public function testTransferFailedEventIsDispatchedOnSuspendedAccount(): void
    {
        $from = $this->createAccount('EUR', 100_00);
        $to   = $this->createAccount('EUR', 50_00);
        $from->suspend();
        $this->em->flush();

        $capturedEvent = null;
        $this->listenForTransferFailedEvent($capturedEvent);

        $this->client->jsonRequest('POST', '/api/v1/transfers', [
            'from_account_id' => $from->getUuid(),
            'to_account_id'   => $to->getUuid(),
            'amount'          => 20_00,
            'currency'        => 'EUR',
            'idempotency_key' => 'a1b2c3d4-e5f6-4a1b-8c2d-000000000052',
        ]);

        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());
        $this->assertNotNull($capturedEvent, 'TransferFailedEvent was not dispatched.');
        $this->assertEquals('account_suspended', $capturedEvent->failureReason);
        $this->assertFailedTransferPersisted($from->getUuid(), $to->getUuid(), 'account_suspended');
    }

    /**
     * Register a listener that captures the next TransferFailedEvent into $capture.
     * Must be called BEFORE the HTTP request. Uses the same event_dispatcher instance
     * as the kernel, so no profiler needed.
     */
    private function listenForTransferFailedEvent(?TransferFailedEvent &$capture): void
    {
        static::getContainer()->get('event_dispatcher')
            ->addListener(TransferFailedEvent::class, static function (TransferFailedEvent $e) use (&$capture): void {
                $capture = $e;
            });
    }

    /**
     * Assert that a Transfer row with STATUS_FAILED was written to the database.
     * Clears the ORM identity map to bypass any cached (pre-rollback) state.
     */
    private function assertFailedTransferPersisted(string $fromUuid, string $toUuid, string $expectedReason): void
    {
        $this->em->clear();

        $transfer = $this->em->getRepository(Transfer::class)->findOneBy([
            'status'        => Transfer::STATUS_FAILED,
            'failureReason' => $expectedReason,
        ]);

        $this->assertNotNull($transfer, sprintf(
            'Expected a failed Transfer row with failure_reason="%s" but none was found.',
            $expectedReason,
        ));
        $this->assertEquals($fromUuid, $transfer->getFromAccount()->getUuid());
        $this->assertEquals($toUuid, $transfer->getToAccount()->getUuid());
    }
}

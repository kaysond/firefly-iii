<?php


namespace FireflyIII\Support\Import\Routine\FinTS;


use Fhp\Model\StatementOfAccount\Transaction as FinTSTransaction;
use Fhp\Model\StatementOfAccount\Transaction;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\ImportJob;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\ImportJob\ImportJobRepositoryInterface;
use FireflyIII\Support\FinTS\FinTS;
use FireflyIII\Models\Account as LocalAccount;
use FireflyIII\Support\Import\Routine\File\OpposingAccountMapper;
use Illuminate\Support\Facades\Log;

class StageImportDataHandler
{
    /** @var AccountRepositoryInterface */
    private $accountRepository;
    /** @var ImportJob */
    private $importJob;
    /** @var ImportJobRepositoryInterface */
    private $repository;
    /** @var array */
    private $transactions;
    /** @var OpposingAccountMapper */
    private $mapper;

    /**
     * @param ImportJob $importJob
     *
     * @return void
     */
    public function setImportJob(ImportJob $importJob): void
    {
        $this->transactions      = [];
        $this->importJob         = $importJob;
        $this->repository        = app(ImportJobRepositoryInterface::class);
        $this->accountRepository = app(AccountRepositoryInterface::class);
        $this->mapper            = app(OpposingAccountMapper::class);
        $this->mapper->setUser($importJob->user);
        $this->repository->setUser($importJob->user);
        $this->accountRepository->setUser($importJob->user);
    }

    /**
     * @throws \FireflyIII\Exceptions\FireflyException
     */
    public function run()
    {
        Log::debug('Now in StageImportDataHandler::run()');

        $localAccount       = $this->accountRepository->find($this->importJob->configuration['local_account']);
        $finTS              = new FinTS($this->importJob->configuration);
        $fintTSAccount      = $finTS->getAccount($this->importJob->configuration['fints_account']);
        $statementOfAccount = $finTS->getStatementOfAccount($fintTSAccount, new \DateTime($this->importJob->configuration['from_date']), new \DateTime($this->importJob->configuration['to_date']));
        $collection         = [];
        foreach ($statementOfAccount->getStatements() as $statement) {
            foreach ($statement->getTransactions() as $transaction) {
                $collection[] = $this->convertTransaction($transaction, $localAccount);
            }
        }

        $this->transactions = $collection;
    }

    private function convertTransaction(FinTSTransaction $transaction, LocalAccount $source): array
    {
        Log::debug(sprintf('Start converting transaction %s', $transaction->getDescription1()));

        $amount        = (string) $transaction->getAmount();
        $debitOrCredit = $transaction->getCreditDebit();

        Log::debug(sprintf('Amount is %s', $amount));
        if ($debitOrCredit == Transaction::CD_CREDIT) {
            $type = TransactionType::DEPOSIT;
        } else {
            $type   = TransactionType::WITHDRAWAL;
            $amount = bcmul($amount, '-1');
        }

        $destination = $this->mapper->map(
            null,
            $amount,
            ['iban' => $transaction->getAccountNumber(), 'name' => $transaction->getName()]
        );
        if ($debitOrCredit == Transaction::CD_CREDIT) {
            [$source, $destination] = [$destination, $source];
        }

        if ($source->accountType->type === AccountType::ASSET && $destination->accountType->type === AccountType::ASSET) {
            $type = TransactionType::TRANSFER;
            Log::debug('Both are assets, will make transfer.');
        }

        $storeData = [
            'user' => $this->importJob->user_id,
            'type' => $type,
            'date' => $transaction->getValutaDate()->format('Y-m-d'),
            'description' => $transaction->getDescription1(),
            'piggy_bank_id' => null,
            'piggy_bank_name' => null,
            'bill_id' => null,
            'bill_name' => null,
            'tags' => [],
            'internal_reference' => null,
            'external_id' => null,
            'notes' => null,
            'bunq_payment_id' => null,
            'transactions' => [
                // single transaction:
                [
                    'description' => null,
                    'amount' => $amount,
                    'currency_id' => null,
                    'currency_code' => 'EUR',
                    'foreign_amount' => null,
                    'foreign_currency_id' => null,
                    'foreign_currency_code' => null,
                    'budget_id' => null,
                    'budget_name' => null,
                    'category_id' => null,
                    'category_name' => null,
                    'source_id' => $source->id,
                    'source_name' => null,
                    'destination_id' => $destination->id,
                    'destination_name' => null,
                    'reconciled' => false,
                    'identifier' => 0,
                ],
            ],
        ];

        return $storeData;
    }

    /**
     * @return array
     */
    public function getTransactions(): array
    {
        return $this->transactions;
    }
}
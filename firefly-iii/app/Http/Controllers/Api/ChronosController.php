<?php

/**
 * ChronosController.php
 * Copyright (c) 2024 CHRONOS Fin
 *
 * This file is part of CHRONOS Fin (based on Firefly III).
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Api;

use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Repositories\Transaction\TransactionRepositoryInterface;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * Class ChronosController
 *
 * Controller for CHRONOS Assistant WhatsApp integration
 */
class ChronosController extends Controller
{
    private TransactionRepositoryInterface $transactionRepository;
    private AccountRepositoryInterface $accountRepository;
    private CategoryRepositoryInterface $categoryRepository;

    public function __construct(
        TransactionRepositoryInterface $transactionRepository,
        AccountRepositoryInterface $accountRepository,
        CategoryRepositoryInterface $categoryRepository
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->accountRepository = $accountRepository;
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * Process WhatsApp message and create transaction
     *
     * POST /api/v1/chronos/whatsapp
     */
    public function processWhatsAppMessage(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
            'user_id' => 'required|integer',
            'phone' => 'required|string'
        ]);

        try {
            $message = $request->input('message');
            $userId = $request->input('user_id');

            // Parse the message using AI/NLP logic
            $parsedData = $this->parseTransactionMessage($message);

            if (!$parsedData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não consegui entender a transação. Tente algo como: "Comprei pão por R$ 5,50 no débito"'
                ], 400);
            }

            // Create the transaction
            $transaction = $this->createTransactionFromParsed($parsedData, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Transação registrada com sucesso!',
                'transaction' => [
                    'id' => $transaction->id,
                    'description' => $transaction->description,
                    'amount' => $transaction->amount,
                    'category' => $parsedData['category'] ?? 'Geral',
                    'date' => $transaction->date->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar transação: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's financial summary for WhatsApp
     *
     * GET /api/v1/chronos/summary/{userId}
     */
    public function getFinancialSummary(int $userId): JsonResponse
    {
        try {
            $user = auth()->user();

            // Get accounts
            $accounts = $this->accountRepository->getAccountsByType(['asset']);
            $totalBalance = 0;

            foreach ($accounts as $account) {
                $totalBalance += $account->balance;
            }

            // Get this month's transactions
            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();

            $transactions = $this->transactionRepository->getTransactionsByDateRange(
                $startOfMonth,
                $endOfMonth
            );

            $monthlyIncome = 0;
            $monthlyExpense = 0;

            foreach ($transactions as $transaction) {
                if ($transaction->amount > 0) {
                    $monthlyIncome += $transaction->amount;
                } else {
                    $monthlyExpense += abs($transaction->amount);
                }
            }

            return response()->json([
                'success' => true,
                'summary' => [
                    'total_balance' => number_format($totalBalance, 2, ',', '.'),
                    'monthly_income' => number_format($monthlyIncome, 2, ',', '.'),
                    'monthly_expense' => number_format($monthlyExpense, 2, ',', '.'),
                    'monthly_savings' => number_format($monthlyIncome - $monthlyExpense, 2, ',', '.'),
                    'period' => Carbon::now()->format('M/Y')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter resumo financeiro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Parse WhatsApp message to extract transaction data
     */
    private function parseTransactionMessage(string $message): ?array
    {
        $message = strtolower(trim($message));

        // Regex patterns for different transaction types
        $patterns = [
            // "Comprei X por R$ Y no débito/crédito"
            '/(?:comprei|paguei|gastei)\s+(.+?)\s+(?:por|pagando|no valor de)\s+r?\$?\s*(\d+[,.]?\d*)\s*(?:no\s+)?(débito|crédito|dinheiro|pix)?/i',

            // "Recebi R$ X de Y"
            '/(?:recebi|ganhei)\s+r?\$?\s*(\d+[,.]?\d*)\s+(?:de|por)\s+(.+)/i',

            // "Transferi R$ X para Y"
            '/(?:transferi|enviei)\s+r?\$?\s*(\d+[,.]?\d*)\s+(?:para|pro)\s+(.+)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return $this->extractTransactionData($matches, $message);
            }
        }

        return null;
    }

    /**
     * Extract transaction data from regex matches
     */
    private function extractTransactionData(array $matches, string $originalMessage): array
    {
        $amount = 0;
        $description = '';
        $type = 'expense'; // default
        $paymentMethod = 'unknown';
        $category = 'Geral';

        if (preg_match('/(?:comprei|paguei|gastei)/i', $originalMessage)) {
            $type = 'expense';
            $description = $matches[1] ?? 'Compra';
            $amount = floatval(str_replace(',', '.', $matches[2] ?? '0'));
            $paymentMethod = $matches[3] ?? 'unknown';
            $category = $this->guessCategory($description);
        } elseif (preg_match('/(?:recebi|ganhei)/i', $originalMessage)) {
            $type = 'income';
            $amount = floatval(str_replace(',', '.', $matches[1] ?? '0'));
            $description = $matches[2] ?? 'Receita';
            $category = 'Receita';
        } elseif (preg_match('/(?:transferi|enviei)/i', $originalMessage)) {
            $type = 'transfer';
            $amount = floatval(str_replace(',', '.', $matches[1] ?? '0'));
            $description = 'Transferência para ' . ($matches[2] ?? 'conta');
            $category = 'Transferência';
        }

        return [
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'payment_method' => $paymentMethod,
            'category' => $category,
            'date' => Carbon::now()
        ];
    }

    /**
     * Guess category based on description
     */
    private function guessCategory(string $description): string
    {
        $categoryMap = [
            'supermercado' => 'Alimentação',
            'padaria' => 'Alimentação',
            'restaurante' => 'Alimentação',
            'lanche' => 'Alimentação',
            'comida' => 'Alimentação',
            'uber' => 'Transporte',
            'gasolina' => 'Transporte',
            'combustível' => 'Transporte',
            'ônibus' => 'Transporte',
            'netflix' => 'Entretenimento',
            'cinema' => 'Entretenimento',
            'farmácia' => 'Saúde',
            'remédio' => 'Saúde',
            'médico' => 'Saúde',
            'conta de luz' => 'Contas',
            'conta de água' => 'Contas',
            'internet' => 'Contas',
            'celular' => 'Contas'
        ];

        $description = strtolower($description);

        foreach ($categoryMap as $keyword => $category) {
            if (strpos($description, $keyword) !== false) {
                return $category;
            }
        }

        return 'Geral';
    }

    /**
     * Create transaction from parsed data
     */
    private function createTransactionFromParsed(array $data, int $userId): Transaction
    {
        // Find or create default accounts
        $assetAccount = $this->accountRepository->getAccountsByType(['asset'])->first();
        $expenseAccount = $this->accountRepository->findByName('Gastos Gerais', ['expense'])
                         ?? $this->createDefaultExpenseAccount();

        // Find or create category
        $category = $this->categoryRepository->findByName($data['category'])
                   ?? $this->categoryRepository->store(['name' => $data['category']]);

        // Create transaction data
        $transactionData = [
            'type' => $data['type'] === 'income' ? 'deposit' : 'withdrawal',
            'date' => $data['date']->format('Y-m-d'),
            'amount' => (string) $data['amount'],
            'description' => $data['description'],
            'source_id' => $data['type'] === 'income' ? $expenseAccount->id : $assetAccount->id,
            'destination_id' => $data['type'] === 'income' ? $assetAccount->id : $expenseAccount->id,
            'category_id' => $category->id,
            'notes' => 'Criado via CHRONOS Assistant (WhatsApp)'
        ];

        return $this->transactionRepository->store($transactionData);
    }

    /**
     * Create default expense account if not exists
     */
    private function createDefaultExpenseAccount(): Account
    {
        return $this->accountRepository->store([
            'name' => 'Gastos Gerais',
            'account_type_id' => 4, // expense account type
            'active' => true
        ]);
    }

    /**
     * Health check endpoint for WhatsApp webhook
     *
     * GET /api/v1/chronos/health
     */
    public function healthCheck(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'CHRONOS Fin API',
            'version' => '1.0.0',
            'timestamp' => Carbon::now()->toISOString()
        ]);
    }
}
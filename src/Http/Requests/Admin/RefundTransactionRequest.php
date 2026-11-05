<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use LaravelPlus\PaymentGateway\Models\Transaction;

final class RefundTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $transaction = $this->route('transaction');
        $amountRules = ['nullable', 'integer', 'min:1'];

        if ($transaction instanceof Transaction) {
            $amountRules[] = 'max:'.max(1, $transaction->getRefundableAmount());
        }

        return [
            'amount' => $amountRules,
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}

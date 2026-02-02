<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class CancelSubscriptionRequest extends FormRequest
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
        return [
            'immediately' => ['nullable', 'boolean'],
        ];
    }
}

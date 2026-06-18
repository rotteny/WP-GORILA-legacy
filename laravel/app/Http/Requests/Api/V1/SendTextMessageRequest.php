<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesRecipient;
use Illuminate\Foundation\Http\FormRequest;

class SendTextMessageRequest extends FormRequest
{
    use ValidatesRecipient;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->recipientRules(), [
            'message' => 'required|string|max:4096',
        ]);
    }
}

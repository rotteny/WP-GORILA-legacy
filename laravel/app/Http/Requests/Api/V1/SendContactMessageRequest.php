<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesRecipient;
use Illuminate\Foundation\Http\FormRequest;

class SendContactMessageRequest extends FormRequest
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
            'vcard' => 'required|string|max:8192',
            'display_name' => 'nullable|string|max:255',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesRecipient;
use Illuminate\Foundation\Http\FormRequest;

class SendLocationMessageRequest extends FormRequest
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
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:512',
        ]);
    }
}

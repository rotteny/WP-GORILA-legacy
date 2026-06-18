<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesRecipient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SendMediaMessageRequest extends FormRequest
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
        $maxKb = (int) config('whatsapp.media.max_mb', 20) * 1024;

        return array_merge($this->recipientRules(), [
            'file' => 'required|file|max:' . $maxKb,
            'caption' => 'nullable|string|max:1024',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $file = $this->file('file');
            if (!$file) {
                return;
            }

            $mime = strtolower(explode(';', (string) $file->getMimeType())[0]);
            $allowed = $this->allowedMimesFlat();

            if (!in_array($mime, $allowed, true)) {
                $v->errors()->add('file', "Tipo MIME '{$mime}' nao permitido.");
            }
        });
    }

    /**
     * @return list<string>
     */
    private function allowedMimesFlat(): array
    {
        $groups = config('whatsapp.media.allowed_mimes', []);

        return array_values(array_unique(array_merge(...array_values($groups))));
    }
}

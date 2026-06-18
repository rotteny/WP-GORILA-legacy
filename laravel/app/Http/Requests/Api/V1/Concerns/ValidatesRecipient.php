<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Concerns;

trait ValidatesRecipient
{
    /**
     * @return array<string, mixed>
     */
    protected function recipientRules(): array
    {
        return [
            'to' => 'required_without_all:jid,number|nullable|string|max:32',
            'jid' => 'required_without_all:to,number|nullable|string|max:128',
            'number' => 'required_without_all:to,jid|nullable|string|max:32',
            'client_message_id' => 'nullable|string|max:128',
        ];
    }

    /**
     * @return array{to?: string, jid?: string, number?: string}
     */
    public function recipientFields(): array
    {
        $validated = $this->validated();

        return array_filter([
            'to' => $validated['to'] ?? null,
            'jid' => $validated['jid'] ?? null,
            'number' => $validated['number'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    public function clientMessageId(): ?string
    {
        $id = $this->validated()['client_message_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}

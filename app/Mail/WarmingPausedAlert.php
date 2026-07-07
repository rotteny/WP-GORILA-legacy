<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email avisando o responsável que o aquecimento do projeto foi pausado
 * automaticamente porque um telefone caiu/foi bloqueado durante o warming
 * (possível sinal de padrão detectado). Reativação é manual pelo painel.
 */
class WarmingPausedAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Project $project,
        public string $instanceSlug,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[wp-gorila] Aquecimento pausado no projeto {$this->project->name}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.warming-paused');
    }
}

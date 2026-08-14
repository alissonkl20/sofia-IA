<?php

namespace App\Services;

use App\Models\Conversation;

class ConversationSummaryService
{
    public function generate(Conversation $conversation): string
    {
        $conversation->loadMissing('customer');
        $data = $conversation->collected_data ?? [];

        $lines = [
            'CLIENTE',
            'Nome: '.($conversation->customer->name ?: 'Não informado'),
            'Telefone: '.$conversation->customer->phone,
            '',
            'SERVIÇO',
            $this->label($data['service'] ?? $conversation->intent),
            '',
            'OBJETIVO',
            $data['objective'] ?? 'Não informado',
            '',
            'REQUISITOS',
        ];

        $features = $data['features'] ?? [];

        if (is_array($features) && $features !== []) {
            foreach ($features as $feature) {
                $lines[] = '- '.$feature;
            }
        } else {
            $lines[] = '- Não informado';
        }

        $lines[] = '';
        $lines[] = 'PRAZO';
        $lines[] = $data['deadline'] ?? 'Não informado';
        $lines[] = '';
        $lines[] = 'INTEGRAÇÕES';

        $integrations = $data['integrations'] ?? [];

        if (is_array($integrations) && $integrations !== []) {
            $lines[] = implode(', ', $integrations);
        } else {
            $lines[] = 'Nenhuma informada';
        }

        $lines[] = '';
        $lines[] = 'OBSERVAÇÕES';
        $lines[] = $data['notes'] ?? 'Nenhuma';
        $lines[] = '';
        $lines[] = 'COMPLEXIDADE ESTIMADA';
        $lines[] = $conversation->complexity ?? ($data['complexity'] ?? 'A definir');
        $lines[] = '';
        $lines[] = 'ORÇAMENTO';
        $lines[] = 'A definir pelo Alisson.';

        return implode("\n", $lines);
    }

    protected function label(?string $value): string
    {
        if (! $value) {
            return 'Não classificado';
        }

        return str_replace('_', ' ', ucfirst($value));
    }
}

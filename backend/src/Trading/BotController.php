<?php

namespace Trading;

class BotController
{
    public function status(): array
    {
        return [
            'asset' => 'GRAM',
            'mode' => 'paper',
            'enabled' => false,
            'message' => 'Trading bot ready for configuration'
        ];
    }

    public function enablePaperMode(): array
    {
        return [
            'success' => true,
            'mode' => 'paper'
        ];
    }
}

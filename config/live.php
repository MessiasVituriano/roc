<?php

return [
    /*
     * Nome do evento no <title> da aba, ao lado da marca.
     *
     * O título que aparece *dentro* das telas vem do banco (`events.title`) e é
     * editável por evento. Este aqui é só o rótulo do navegador: o blade é uma
     * casca estática, renderizada antes do Vue montar, então não consulta o
     * banco. Mudou o nome do evento? Mude os dois.
     */
    'event_title' => env('EVENT_TITLE', 'Sala de Decisões ROC'),

    // Shared secret for the master panel (see EnsureMasterToken).
    'master_token' => env('MASTER_TOKEN', 'master-dev-token'),

    // Client polling interval in milliseconds. The whole realtime story.
    'poll_interval' => (int) env('POLL_INTERVAL', 1000),

    // Default clock for each phase, in seconds. The master can override it when
    // starting the phase and extend it live.
    'phase_duration' => (int) env('PHASE_DURATION', 600),

    // A participant is considered offline after this many seconds without polling.
    'presence_ttl' => (int) env('PRESENCE_TTL', 15),
];

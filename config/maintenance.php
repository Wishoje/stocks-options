<?php

return [
    'calculator_publications_enabled' => (bool) env('CALCULATOR_PUBLICATION_PRUNING_ENABLED', false),
    'calculator_archive_path' => storage_path('app/private/maintenance/calculator-prune'),
];

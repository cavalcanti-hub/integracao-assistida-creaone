<?php

declare(strict_types=1);

$caBundle = getenv('CREAONE_CA_BUNDLE');

return [
    'ca_bundle' => is_string($caBundle) && trim($caBundle) !== '' ? trim($caBundle) : null,
];


<?php

// The built-in SPA client (pages that return 'spa' => true).
return [
    'enabled' => true,
    'initial' => 'lazy',                           // lazy: the first visit loads a shell, then the content | inline: the content is in the first page
    'root' => 'body',
    'view' => '#cast-view',
];

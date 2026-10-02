<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'MCP Addons',
    'description' => 'Additional tools for the TYPO3 MCP server: workspace preview links and workspace publishing, plus a command for repeated scheduled publishing.',
    'category' => 'be',
    'author' => 'Sven Juergens',
    'state' => 'beta',
    'version' => '0.4.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'workspaces' => '13.4.0-14.3.99',
            'mcp_server' => '0.6.0-0.99.99',
        ],
    ],
];

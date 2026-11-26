<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DomPDF Configuration
    |--------------------------------------------------------------------------
    */
    'show_warnings'   => false,
    'orientation'     => 'portrait',
    'defines'         => [
        // Remote fetching is off: PDFs only load local file:// images (logo, etc.) inside 'chroot',
        // so HTML in a PDF can never make the server call internal hosts.
        'enable_remote'           => false,
        'enable_html5_parser'     => true,
        'enable_css_float'        => true,
        'enable_font_subsetting'  => true,
        'pdf_backend'             => 'CPDF',
        'default_media_type'      => 'screen',
        'default_paper_size'      => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font'            => 'DejaVu Sans',
        'dpi'                     => 150,
        'font_height_ratio'       => 1.1,
        'is_php_enabled'          => false,
        'is_strong_mode'          => false,
        'is_javascript_enabled'   => false,
        'debugPng'                => false,
        'debugKeepTemp'           => false,
        'debugCss'                => false,
        'debugLayout'             => false,
        'debugLayoutLines'        => true,
        'debugLayoutBlocks'       => true,
        'debugLayoutInline'       => true,
        'debugLayoutPaddingBox'   => true,
        'pdf_unicode'             => true,
        'temp_dir'                => sys_get_temp_dir(),
        // Only public assets and templates; never the project root (which holds .env).
        'chroot'                  => array_values(array_filter([realpath(public_path()), realpath(resource_path())])),
        'log_output_file'         => null,
        'allowed_remote_hosts'    => null,
        'allowed_protocols'       => [
            'file://' => ['rules' => []],
        ],
    ],
];

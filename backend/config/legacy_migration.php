<?php

return [
    /*
     * Folder on the server that legacy document imports may read from.
     * The "source folder" given in the import screen must be this folder or a sub-folder of it,
     * so the importer cannot copy arbitrary server files (such as .env) into the portal.
     */
    'source_root' => env('LEGACY_IMPORT_ROOT') ?: storage_path('app/legacy-import'),
];
